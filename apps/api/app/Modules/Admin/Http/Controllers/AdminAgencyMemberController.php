<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\MemberMfaActionRequest;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Mail\MfaExemptionGrantedMail;
use App\Modules\Identity\Mail\MfaExemptionRemovedMail;
use App\Modules\Identity\Mail\MfaResetByAdminMail;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin agency-members drill-in + the two per-member 2FA operations (AH-087).
 *
 *   GET  /api/v1/admin/agencies/{agency}/members
 *   POST /api/v1/admin/agencies/{agency}/members/{user}/reset-2fa
 *   POST /api/v1/admin/agencies/{agency}/members/{user}/exempt-2fa
 *   POST /api/v1/admin/agencies/{agency}/members/{user}/remove-2fa-exemption
 *
 * Sibling of {@see AdminAgencyController} (its own class because members are a
 * distinct resource with three write verbs of their own). Same posture: flat
 * platform-admin policy gate, cross-agency BY DESIGN — the bounded bypass
 * (docs/security/tenancy.md § 4).
 *
 * Tenancy: no scope machinery is needed anywhere here. AgencyMembership does
 * not use BelongsToAgency (there is no global scope to strip — contrast
 * AH-051's AgencyCreatorRelation, which needed runAs + withoutGlobalScope),
 * and User is not a tenant-scoped model, so the reads are ordinary
 * `where('agency_id', ...)` queries and the writes are ordinary saves.
 *
 * WHAT AN ADMIN CAN NEVER DO HERE (D4 — the boundary):
 *   - see any secret material: neither `two_factor_secret` nor
 *     `two_factor_recovery_codes` (nor any derivative) is read, emitted, or
 *     logged by this controller. The members payload's keyset is pinned by
 *     test so a future field cannot drift in;
 *   - generate recovery codes for a user, or complete enrollment on their
 *     behalf: no code here mints or persists credential material. Reset only
 *     ever NULLs columns; the user re-enrolls themselves through the ordinary
 *     self-service flow, which is the only path that can create a secret;
 *   - bypass 2FA: an exempt user who IS enrolled is still challenged at
 *     sign-in, because the login gate reads enrollment and knows nothing about
 *     the exemption columns.
 *
 * Both write directions of the exemption are audited with a mandatory reason,
 * and the affected user is emailed on all three operations.
 */
final class AdminAgencyMemberController
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    public function __construct(private readonly MailFactory $mail) {}

    /**
     * The paginated members list. Deliberately mirrors the agency-self
     * MembershipController's filter/search/sort/pagination contract
     * (?role, ?search, ?sort, ?page, ?per_page) so the admin table behaves
     * like the agency's own — but it is a separate implementation rather
     * than a shared one: extracting the query would refactor a live
     * tenant-facing endpoint inside a chunk whose whole point is that
     * existing behaviour does not move.
     */
    public function index(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize($request, 'viewMembers', $agency);

        $request->validate([
            'role' => ['sometimes', 'string'],
            'search' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);

        $query = AgencyMembership::query()
            ->where('agency_id', $agency->id)
            ->whereNull('deleted_at')
            ->with('user');

        if ($request->has('role')) {
            $role = AgencyRole::tryFrom($request->string('role')->value());
            if ($role !== null) {
                $query->where('role', $role->value);
            }
        }

        if ($request->has('search')) {
            $this->applySearch($query, trim($request->string('search')->value()));
        }

        $this->applySort($query, $request->string('sort')->value() ?: '-created_at');

        $paginator = $query->paginate($perPage)->appends($request->query());

        /** @var list<AgencyMembership> $memberships */
        $memberships = $paginator->items();

        $data = array_map(
            fn (AgencyMembership $membership): array => $this->serialize($membership),
            $memberships,
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST .../members/{user}/reset-2fa — the support operation.
     *
     * Clears the COMPLETE enrollment artifact set in one transaction (secret,
     * recovery codes, confirmed-at, enrollment-suspended-at), leaving the
     * account's MFA posture untouched: the user simply re-enrolls at their
     * next gated access. Mandatory reason — a security operation gets a paper
     * trail even when the user asked for it.
     *
     * Idempotency: 409 + `mfa.nothing_to_reset` when there is nothing to
     * clear. "Nothing" means neither enrolled NOR enrollment-frozen — a user
     * whose enrollment was frozen by the failed-attempt throttle without ever
     * confirming still has state worth clearing, and this is the first admin
     * surface able to clear it (previously no endpoint could).
     */
    public function resetTwoFactor(MemberMfaActionRequest $request, Agency $agency, User $user): JsonResponse
    {
        $this->authorize($request, 'manageMemberMfa', $agency);
        $membership = $this->membershipOrAbort($agency, $user);

        if (! $user->hasTwoFactorEnabled() && ! $user->hasTwoFactorEnrollmentSuspended()) {
            return $this->conflict('mfa.nothing_to_reset', 'This member has no two-factor enrollment to reset.');
        }

        /** @var User $admin */
        $admin = $request->user();
        $reason = $request->reason();

        DB::transaction(function () use ($user, $agency, $admin, $reason): void {
            // The same four-column wipe as the self-service disable path
            // (TwoFactorEnrollmentService::disable) — partial state would
            // leave a wiped secret with a stale confirmed_at, which slips
            // past the login gate.
            //
            // saveQuietly: User is Audited, so a plain save() would append a
            // generic `user.updated` row beside the explicit verb below. One
            // operation, one row.
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_enrollment_suspended_at' => null,
            ])->saveQuietly();

            Audit::log(
                action: AuditAction::UserMfaReset,
                actor: $admin,
                subject: $user,
                reason: $reason,
                metadata: ['agency_id' => $agency->ulid],
            );
        });

        // The TwoFactorDisabled event is DELIBERATELY not dispatched (§5.5's
        // listener-intentionally-not-registered shape). Its listener writes
        // `mfa.disabled` with actor = the subject user — a self-service verb
        // attributing this admin action to the person it was done to, which
        // would corrupt the trail this operation exists to create.

        $this->mailTo($user, new MfaResetByAdminMail(recipientName: $user->name));

        return response()->json(['data' => $this->serialize($membership->refresh())]);
    }

    /**
     * POST .../members/{user}/exempt-2fa — the policy operation.
     *
     * Stamps `mfa_exempted_at` + `mfa_exemption_reason`, which removes the
     * REQUIREMENT to enroll (the client-side MFA gate branches on it). It does
     * not touch enrollment: an exempt user who is enrolled keeps their
     * authenticator and is still challenged at sign-in.
     *
     * Platform admins are NEVER exemptable — 422. The server-side admin gate
     * (EnsureMfaForAdmins) is unconditional and does not consult these
     * columns, so an exemption on an admin would be a silent no-op that reads
     * like a granted exemption. Refusing it is the honest answer.
     *
     * Idempotency: 409 + `mfa.already_exempt`; the original timestamp and
     * reason are never re-stamped.
     */
    public function exempt(MemberMfaActionRequest $request, Agency $agency, User $user): JsonResponse
    {
        $this->authorize($request, 'manageMemberMfa', $agency);
        $membership = $this->membershipOrAbort($agency, $user);

        if ($user->isPlatformAdmin()) {
            return $this->error(
                422,
                'mfa.platform_admin_not_exemptable',
                'Platform administrators cannot be exempted from two-factor authentication.',
            );
        }

        if ($user->isMfaExempt()) {
            return $this->conflict('mfa.already_exempt', 'This member is already exempt from two-factor authentication.');
        }

        /** @var User $admin */
        $admin = $request->user();
        $reason = $request->reason();

        DB::transaction(function () use ($user, $agency, $admin, $reason): void {
            $user->forceFill([
                'mfa_exempted_at' => Carbon::now(),
                'mfa_exemption_reason' => $reason,
            ])->saveQuietly();

            Audit::log(
                action: AuditAction::UserMfaExempted,
                actor: $admin,
                subject: $user,
                reason: $reason,
                metadata: ['agency_id' => $agency->ulid],
            );
        });

        $this->mailTo($user, new MfaExemptionGrantedMail(recipientName: $user->name));

        return response()->json(['data' => $this->serialize($membership->refresh())]);
    }

    /**
     * POST .../members/{user}/remove-2fa-exemption — restores the requirement.
     *
     * Mandatory reason in this direction too, unlike the agency
     * suspend/reactivate pair where reactivate needs none: this restores a
     * requirement on a PERSON rather than the default state of an org, so both
     * halves of a per-person security-posture change answer "why".
     *
     * No platform-admin refusal here — the 422 guards the grant direction
     * only. Clearing an exemption always restores the stricter state, so it is
     * never the operation that needs blocking.
     *
     * Idempotency: 409 + `mfa.not_exempt`.
     */
    public function removeExemption(MemberMfaActionRequest $request, Agency $agency, User $user): JsonResponse
    {
        $this->authorize($request, 'manageMemberMfa', $agency);
        $membership = $this->membershipOrAbort($agency, $user);

        if (! $user->isMfaExempt()) {
            return $this->conflict('mfa.not_exempt', 'This member is not exempt from two-factor authentication.');
        }

        /** @var User $admin */
        $admin = $request->user();
        $reason = $request->reason();

        DB::transaction(function () use ($user, $agency, $admin, $reason): void {
            $user->forceFill([
                'mfa_exempted_at' => null,
                'mfa_exemption_reason' => null,
            ])->saveQuietly();

            Audit::log(
                action: AuditAction::UserMfaExemptionRemoved,
                actor: $admin,
                subject: $user,
                reason: $reason,
                metadata: ['agency_id' => $agency->ulid],
            );
        });

        $this->mailTo($user, new MfaExemptionRemovedMail(recipientName: $user->name));

        return response()->json(['data' => $this->serialize($membership->refresh())]);
    }

    /**
     * The member row's EXACT keyset (D4). Every field is either membership
     * metadata or a boolean/timestamp projection of MFA state — no secret,
     * and nothing derived from one. `is_mfa_enrolled` is the same
     * `two_factor_confirmed_at !== null` projection the self resource has
     * always exposed; it never carries the secret or the codes.
     *
     * The three rendered states (Enrolled / Not enrolled / Exempt) are the
     * UI's collapse of two independent booleans: exempt wins the chip, and
     * the detail line carries "also enrolled" when both are true.
     *
     * @return array<string, mixed>
     */
    private function serialize(AgencyMembership $membership): array
    {
        $user = $membership->user;
        assert($user !== null, 'AgencyMembership::user expected to be loaded');

        return [
            'id' => $user->ulid,
            'type' => 'agency_members',
            'attributes' => [
                'user_id' => $user->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $membership->role->value,
                'status' => $membership->isAccepted() ? 'active' : 'pending',
                'joined_at' => $membership->created_at->toIso8601String(),
                'last_active_at' => $user->last_login_at?->toIso8601String(),
                'is_mfa_enrolled' => $user->hasTwoFactorEnabled(),
                'is_mfa_exempt' => $user->isMfaExempt(),
                'mfa_exempted_at' => $user->mfa_exempted_at?->toIso8601String(),
                'mfa_exemption_reason' => $user->mfa_exemption_reason,
            ],
        ];
    }

    /**
     * The membership pairing {agency} with {user}. Nothing at the route layer
     * ties the two bindings together, so a 404 here is what stops an admin
     * from acting on a user through an agency they have nothing to do with.
     */
    private function membershipOrAbort(Agency $agency, User $user): AgencyMembership
    {
        $membership = AgencyMembership::query()
            ->where('agency_id', $agency->id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->with('user')
            ->first();

        if ($membership === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $membership;
    }

    private function mailTo(User $user, Mailable $mailable): void
    {
        if ($user->email === '') {
            return;
        }

        $this->mail->mailer()
            ->to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue($mailable);
    }

    /**
     * @param  Builder<AgencyMembership>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        // Postgres has `ilike`; SQLite (the test driver) does not. Same
        // dual-path as MembershipController — case-insensitive substring
        // match across the related user's email + name.
        $connection = $query->getConnection();
        /** @var Connection $connection */
        $isPostgres = $connection->getDriverName() === 'pgsql';
        $like = '%'.str_replace('%', '\%', mb_strtolower($search)).'%';

        $query->whereHas('user', function ($q) use ($isPostgres, $like): void {
            $q->where(function ($inner) use ($isPostgres, $like): void {
                if ($isPostgres) {
                    $inner->where('email', 'ilike', $like)
                        ->orWhere('name', 'ilike', $like);
                } else {
                    $inner->whereRaw('LOWER(email) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like]);
                }
            });
        });
    }

    /**
     * @param  Builder<AgencyMembership>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        $direction = 'asc';
        $key = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $key = substr($sort, 1);
        } elseif (str_starts_with($sort, '+')) {
            $key = substr($sort, 1);
        }

        switch ($key) {
            case 'name':
            case 'email':
                $query->orderBy(
                    User::query()
                        ->select($key)
                        ->whereColumn('users.id', 'agency_users.user_id'),
                    $direction,
                );
                break;

            case 'created_at':
                $query->orderBy('agency_users.created_at', $direction);
                break;

            default:
                $query->orderBy('agency_users.created_at', 'desc');
        }
    }

    private function conflict(string $code, string $title): JsonResponse
    {
        return $this->error(409, $code, $title);
    }

    private function error(int $status, string $code, string $title): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => (string) $status,
                'code' => $code,
                'title' => $title,
            ]],
        ], $status);
    }

    private function authorize(Request $request, string $ability, Agency $agency): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if (! $user->can($ability, $agency)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
