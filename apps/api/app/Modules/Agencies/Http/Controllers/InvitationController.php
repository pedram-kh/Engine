<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Http\Requests\AcceptInvitationRequest;
use App\Modules\Agencies\Http\Requests\CreateInvitationRequest;
use App\Modules\Agencies\Http\Resources\AgencyInvitationResource;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\Modules\Agencies\Services\AgencyInvitationService;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvitationController
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AgencyInvitationService $invitationService,
    ) {}

    /**
     * GET /api/v1/agencies/{agency}/invitations — Sprint 3 Chunk 4 sub-step 3.
     *
     * Paginated invitation history (pending + accepted + expired).
     * Admin-only — invitation history is sensitive (it reveals who has
     * been invited and when, including failed acceptances). Non-admin
     * agency members get 403.
     *
     * Filter: ?status=pending|accepted|expired.
     * Default sort: -invited_at (== -created_at on the row).
     */
    public function index(Request $request, Agency $agency): JsonResponse
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,accepted,expired'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);

        $query = AgencyUserInvitation::query()
            ->where('agency_id', $agency->id)
            ->with(['agency', 'invitedBy']);

        $status = $request->string('status')->value();
        if ($status !== '') {
            $now = now();
            match ($status) {
                'pending' => $query
                    ->whereNull('accepted_at')
                    ->where('expires_at', '>', $now),
                'accepted' => $query->whereNotNull('accepted_at'),
                'expired' => $query
                    ->whereNull('accepted_at')
                    ->where('expires_at', '<=', $now),
                default => null,
            };
        }

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($perPage)->appends($request->query());

        return AgencyInvitationResource::collection($paginator)
            ->response()
            ->setStatusCode(200);
    }

    /**
     * POST /api/v1/agencies/{agency}/invitations
     *
     * Creates an invitation and dispatches the magic-link email.
     * Requires agency_admin role.
     *
     * User-enumeration defence: returns 201 with the invitation resource
     * regardless of whether the email belongs to an existing user. The
     * existing-user branch is handled at accept time.
     *
     * Privilege-escalation defence: an agency_admin cannot assign a role
     * higher than their own. Since agency_admin is the highest role,
     * any role assignment is valid for an admin. If managers were ever
     * allowed to invite, this check would need to compare roles.
     */
    public function store(CreateInvitationRequest $request, Agency $agency): JsonResponse
    {
        $this->authorizeAdmin($request);

        /** @var User $inviter */
        $inviter = $request->user();

        $invitation = $this->invitationService->invite(
            agency: $agency,
            inviter: $inviter,
            email: $request->string('email')->lower()->value(),
            role: AgencyRole::from($request->string('role')->value()),
        );

        return (new AgencyInvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/v1/agencies/{agency}/invitations/accept
     *
     * Accepts an invitation by token.
     *
     * Q1: single-use-with-retry-on-failure — attempts before `accepted_at`
     * is stamped are fine; once stamped → 409 Conflict.
     *
     * Q2: Option B — the user must be authenticated (the SPA's dedicated
     * accept page handles sign-in/sign-up inline). The authenticated user's
     * email must match the invitation email.
     *
     * NOT mounted under tenancy.agency — the accepting user is not yet a
     * member of the agency. Authentication is handled by auth:web.
     */
    public function accept(AcceptInvitationRequest $request, Agency $agency): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tokenHash = hash('sha256', $request->string('token')->value());

        // Find the invitation for this agency + token hash — no tenancy scope
        // here since the accepting user is not yet a member.
        $invitation = AgencyUserInvitation::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('token_hash', $tokenHash)
            ->first();

        if ($invitation === null) {
            return ErrorResponse::single(
                $request,
                404,
                'invitation.not_found',
                'Invitation not found.',
            );
        }

        // Expired?
        if ($invitation->isExpired()) {
            Audit::log(
                action: AuditAction::InvitationExpiredOnAttempt,
                actor: $user,
                subject: $invitation,
                agencyId: $agency->id,
            );

            return ErrorResponse::single(
                $request,
                410,
                'invitation.expired',
                'This invitation has expired.',
            );
        }

        // Already accepted? (Q1: single-use-with-retry)
        if ($invitation->isAccepted()) {
            return ErrorResponse::single(
                $request,
                409,
                'invitation.already_accepted',
                'This invitation has already been accepted.',
            );
        }

        // The authenticated user's email must match the invitation email.
        if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            return ErrorResponse::single(
                $request,
                403,
                'invitation.email_mismatch',
                'The invitation was sent to a different email address.',
            );
        }

        // Check the user is not already a member of this agency.
        $alreadyMember = AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($alreadyMember) {
            return ErrorResponse::single(
                $request,
                409,
                'invitation.already_member',
                'You are already a member of this agency.',
            );
        }

        $this->invitationService->accept($invitation, $user);

        return (new AgencyInvitationResource($invitation->fresh() ?? $invitation))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Only agency_admin may send invitations.
     * Returns 403 if the authenticated user is not an admin of this agency.
     */
    private function authorizeAdmin(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();

        $routeAgency = $request->route('agency');
        $agencyId = $routeAgency instanceof Agency ? $routeAgency->id : null;

        $membership = AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->first();

        if ($membership === null || $membership->role !== AgencyRole::AgencyAdmin) {
            abort(403, 'Only agency admins can send invitations.');
        }
    }
}
