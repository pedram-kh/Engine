<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Services;

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Exceptions\InvitationAccountExistsException;
use App\Modules\Agencies\Http\Requests\CreateAccountAndAcceptInvitationRequest;
use App\Modules\Agencies\Mail\InviteAgencyUserMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Enums\ThemePreference;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Identity\Services\ImpersonationService;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class AgencyInvitationService
{
    private const int EXPIRES_IN_DAYS = 7;

    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Create a new invitation and dispatch the magic-link email.
     *
     * User-enumeration defence: the method behaves identically whether
     * the email belongs to an existing user or not. The distinction is
     * resolved at accept time, not creation time.
     */
    public function invite(
        Agency $agency,
        User $inviter,
        string $email,
        AgencyRole $role,
    ): AgencyUserInvitation {
        // Generate a cryptographically-random unhashed token; store only
        // the hash. The unhashed token goes into the email exclusively.
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        $invitation = DB::transaction(function () use ($agency, $inviter, $email, $role, $tokenHash): AgencyUserInvitation {
            $invitation = AgencyUserInvitation::query()->create([
                'agency_id' => $agency->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => $tokenHash,
                'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
                'invited_by_user_id' => $inviter->id,
            ]);

            Audit::log(
                action: AuditAction::InvitationCreated,
                actor: $inviter,
                subject: $invitation,
                after: ['email' => $email, 'role' => $role->value],
                agencyId: $agency->id,
            );

            return $invitation;
        });

        // Build the magic-link URL for the SPA. AH-086b: the door is now
        // `/agency/sign-in` (the agency-branded sign-in variant) rather
        // than the bare `/accept-invitation` page directly — SignInPage.vue
        // detects `token`+`agency` on that route and forwards to the SAME
        // `/accept-invitation` page/flow, unchanged, carrying both params.
        // The `agency` parameter carries the agency ULID so the accept
        // flow can call POST /api/v1/agencies/{agency}/invitations/accept —
        // the endpoint requires the agency identifier in the path. Already
        // -sent invitations keep the old `/accept-invitation?...` URL baked
        // into their email body; that route is untouched and still
        // resolves directly, so those links keep working unchanged.
        $acceptUrl = rtrim((string) config('app.frontend_main_url', config('app.url')), '/')
            .'/agency/sign-in?token='.$token.'&agency='.$agency->ulid;

        // The invitee's name: if they already have an account, use it;
        // otherwise fall back to the email local part.
        $inviteeName = User::query()
            ->where('email', $email)
            ->value('name') ?? explode('@', $email)[0];

        Mail::to($email)->queue(new InviteAgencyUserMail(
            agency: $agency,
            inviter: $inviter,
            inviteeName: (string) $inviteeName,
            role: $role,
            acceptUrl: $acceptUrl,
            expiresInDays: self::EXPIRES_IN_DAYS,
        ));

        return $invitation;
    }

    /**
     * Accept an invitation by unhashed token.
     *
     * Q1 (single-use-with-retry-on-failure): multiple attempts before
     * `accepted_at` is set are fine. Once stamped → 409 from the controller.
     *
     * Q2 (Option B): the accepting user must already be authenticated.
     * The SPA's dedicated accept page handles sign-in / sign-up inline
     * before calling this endpoint.
     */
    public function accept(
        AgencyUserInvitation $invitation,
        User $acceptingUser,
    ): AgencyMembership {
        return DB::transaction(function () use ($invitation, $acceptingUser): AgencyMembership {
            // Stamp accepted_at + record who accepted.
            $invitation->update([
                'accepted_at' => now(),
                'accepted_by_user_id' => $acceptingUser->id,
            ]);

            // Create the agency membership.
            $membership = AgencyMembership::query()->create([
                'agency_id' => $invitation->agency_id,
                'user_id' => $acceptingUser->id,
                'role' => $invitation->role,
                'invited_by_user_id' => $invitation->invited_by_user_id,
                'invited_at' => $invitation->created_at,
                'accepted_at' => now(),
            ]);

            Audit::log(
                action: AuditAction::InvitationAccepted,
                actor: $acceptingUser,
                subject: $invitation,
                after: [
                    'role' => $invitation->role->value,
                    'agency_id' => $invitation->agency_id,
                ],
                agencyId: $invitation->agency_id,
            );

            return $membership;
        });
    }

    /**
     * Accept an invitation AND create the account in one atomic step
     * (AH-095 — the stranger path: no account exists for the invited
     * email at submit time).
     *
     * Email immutability (review priority #1): the created row's email
     * is ALWAYS `$invitation->email`. `$attributes` carries no email key
     * at all — {@see CreateAccountAndAcceptInvitationRequest}
     * has no `email` field in its rules — so there is no client-supplied
     * value here to prefer, ignore, or accidentally trust. Foreclosure
     * by construction, not by a check.
     *
     * Email verification: the invitee clicked a link mailed to this
     * exact address, so `email_verified_at` is stamped to now() — the
     * single-use token already proved possession; a second verification
     * mail would prove nothing the token hasn't already proven.
     *
     * Collision race (D3): the controller ran a pre-check
     * (`account_exists`) before opening this transaction. This method
     * re-checks INSIDE the transaction to close the page-load-to-submit
     * window, and additionally catches the `users.email` unique-index
     * violation as the last-resort backstop for a true concurrent
     * double-submit that slips past both checks — never a bare 500.
     * Both layers funnel into the same {@see InvitationAccountExistsException}
     * so the controller (and the SPA) has exactly one code to branch on.
     *
     * Rollback-safe (review priority #5): the user create, membership
     * create, invitation stamp, audit log, and session login all live
     * inside ONE DB::transaction. Any failure — including the
     * unique-violation catch re-throwing after the transaction unwinds —
     * leaves zero rows: no half-account, no orphaned membership, no
     * consumed token.
     *
     * Session establishment mirrors {@see ImpersonationService::claim()}
     * — `$this->auth->guard('web')->login($user)` +
     * `$request->session()->regenerate()` inside the same transaction —
     * rather than {@see AuthService::login()},
     * which does password/MFA/failed-login-tracking work that does not
     * apply to a freshly-created, invite-authenticated account.
     *
     * @param  array{name: string, last_name: string, password: string}  $attributes
     *
     * @throws InvitationAccountExistsException
     */
    public function acceptWithNewAccount(
        AgencyUserInvitation $invitation,
        array $attributes,
        Request $request,
    ): User {
        try {
            return DB::transaction(function () use ($invitation, $attributes, $request): User {
                if (User::query()->where('email', $invitation->email)->exists()) {
                    throw new InvitationAccountExistsException;
                }

                /** @var User $user */
                $user = User::query()->create([
                    'type' => UserType::AgencyUser,
                    'email' => $invitation->email,
                    'name' => $attributes['name'],
                    'last_name' => $attributes['last_name'],
                    'password' => $attributes['password'],
                    'email_verified_at' => now(),
                    'preferred_language' => 'en',
                    'preferred_currency' => config('app.default_currency', 'EUR'),
                    'timezone' => 'UTC',
                    // Set explicitly (not left to the column's DB default):
                    // the in-memory $user is fed straight into UserResource
                    // below without a refresh(), and the cast expects an
                    // enum instance, not the raw DB-default string.
                    'theme_preference' => ThemePreference::System,
                    'mfa_required' => false,
                    'is_suspended' => false,
                ]);

                AgencyMembership::query()->create([
                    'agency_id' => $invitation->agency_id,
                    'user_id' => $user->id,
                    'role' => $invitation->role,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'invited_at' => $invitation->created_at,
                    'accepted_at' => now(),
                ]);

                $invitation->update([
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $user->id,
                ]);

                Audit::log(
                    action: AuditAction::InvitationAcceptedWithNewAccount,
                    actor: $user,
                    subject: $invitation,
                    after: [
                        'role' => $invitation->role->value,
                        'agency_id' => $invitation->agency_id,
                        'user_id' => $user->id,
                    ],
                    agencyId: $invitation->agency_id,
                );

                $this->auth->guard('web')->login($user);
                $request->session()->regenerate();

                return $user;
            });
        } catch (QueryException $e) {
            // Postgres unique_violation. The pre-check above (and the
            // controller's own pre-check before it) closes the common
            // window; this is the last-resort backstop for a true
            // concurrent double-submit racing past both.
            if ((string) $e->getCode() === '23505') {
                throw new InvitationAccountExistsException;
            }

            throw $e;
        }
    }
}
