<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers\Admin;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Core\Tenancy\TenancyContext;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Mail\AdminConnectedMail;
use App\Modules\Agencies\Mail\ConnectionRequestMail;
use App\Modules\Agencies\Mail\RelationDisconnectedMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Http\Requests\Admin\AdminCreateConnectionRequest;
use App\Modules\Creators\Http\Requests\Admin\AdminDisconnectRequest;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\TalentPools\Models\TalentPool;
use App\Modules\TalentPools\Models\TalentPoolMembership;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * AH-051 (D-4/D-5/D-9) — admin-initiated agency↔creator connections, attached to
 * the admin creator-detail surface.
 *
 *   GET  /api/v1/admin/creators/{creator}/connections            — list (D-9 read)
 *   POST /api/v1/admin/creators/{creator}/connections            — Door 1 / Door 2
 *   POST /api/v1/admin/creators/{creator}/connections/{agency}/disconnect — D-6
 *
 * The two doors share one POST, mode-switched (D-5 ruling):
 *   mode=request → Door 1: drives the SAME semantics as the agency send-request
 *     path (collision matrix, hard-blacklist 422, re-request from declined/ended,
 *     rides the existing ConnectionRequestMail). Creator accepts/declines through
 *     the unchanged creators/me endpoints (which now carry the D-2 re-gates).
 *   mode=direct → Door 2: records an OFFLINE agreement — targets `roster`
 *     immediately, MANDATORY reason (consent paper-trail), notifies the creator
 *     immediately (dual-emit in-app + mail).
 *
 * Both doors: `approved` binds (a non-approved creator is not eligible), but
 * `is_discoverable` is BYPASSED — an admin-mediated arrangement is not cold
 * outreach, and is_discoverable is a browsing-visibility preference, not an
 * eligibility gate (D-4 ruling). 422 codes are MODE-DISTINCT so a client can
 * tell which door refused.
 *
 * Provenance (D-8): admin-initiated rows are forever distinguishable via the
 * distinct audit verbs (admin_requested / admin_connected) AND `invited_by_user_id`
 * stamped with the acting admin.
 *
 * Tenancy (D-10, §5.1): admin is not an agency member, so every write to the
 * agency-scoped relation runs inside {@see TenancyContext::runAs()} for the
 * target agency — the BelongsToAgency scope + auto-fill apply as if the agency
 * itself acted. Route: auth:web_admin + MFA (platform-admin only). Allowlisted
 * in docs/security/tenancy.md § 4.
 */
final class AdminCreatorConnectionController
{
    public function __construct(
        private readonly MailFactory $mail,
        private readonly NotificationService $notifications,
        private readonly TenancyContext $tenancy,
    ) {}

    /**
     * D-9 read — the creator's relations across ALL agencies (agency, status,
     * since). Scope-bypassed (admin is not a member of any of them).
     */
    public function index(Request $request, Creator $creator): JsonResponse
    {
        $this->assertAdmin($request);

        $relations = AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->with('agency:id,ulid,name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $relations->map(fn (AgencyCreatorRelation $relation): array => [
                'id' => $relation->ulid,
                'type' => 'admin_connection',
                'attributes' => [
                    'agency_id' => $relation->agency->ulid,
                    'agency_name' => $relation->agency->name,
                    'relationship_status' => $relation->relationship_status->value,
                    'is_blacklisted' => (bool) $relation->is_blacklisted,
                    'blacklist_type' => $relation->blacklist_type?->value,
                    'since' => $relation->created_at->toIso8601String(),
                ],
            ])->all(),
        ]);
    }

    public function store(AdminCreateConnectionRequest $request, Creator $creator): JsonResponse
    {
        $this->assertAdmin($request);

        $agency = Agency::query()->where('ulid', $request->agencyUlid())->first();
        if ($agency === null) {
            return $this->error(404, 'connection.agency_not_found', 'No agency found for that identifier.');
        }

        // `approved` binds BOTH doors; is_discoverable is bypassed for admin.
        if ($creator->application_status !== ApplicationStatus::Approved) {
            return $this->error(422, 'connection.creator_not_approved', 'This creator is not approved and cannot be connected.');
        }

        /** @var User $admin */
        $admin = $request->user();
        $mode = $request->mode();
        $reason = $request->reason();

        return $this->tenancy->runAs($agency->id, function () use ($agency, $creator, $admin, $mode, $reason): JsonResponse {
            $relation = AgencyCreatorRelation::query()
                ->where('agency_id', $agency->id)
                ->where('creator_id', $creator->id)
                ->first();

            // Hard-blacklist gate BEFORE the state machine — a HARD agency-wide
            // blacklist blocks BOTH doors (soft is warn-only). Mode-distinct code.
            if ($relation !== null
                && $relation->is_blacklisted
                && $relation->blacklist_type === BlacklistType::Hard) {
                return $this->error(
                    422,
                    $mode === 'direct' ? 'connection.direct_blacklisted' : 'connection.request_blacklisted',
                    'This creator is hard-blacklisted for the selected agency.',
                );
            }

            return $mode === 'direct'
                ? $this->directConnect($relation, $agency, $creator, $admin, (string) $reason)
                : $this->sendRequest($relation, $agency, $creator, $admin);
        });
    }

    /**
     * D-6 — admin disconnect (the platform's FIRST relation-termination path).
     *
     * roster → ended ONLY (any other status → 422 `connection.not_disconnectable`,
     * nothing to disconnect). In ONE DB::transaction: the status flip + the pair's
     * pool-membership rows deleted (this agency's pools only — the over-reach
     * break-revert seam) + the reason-required `disconnected` audit. Messaging
     * closes automatically (the AH-010 gate is roster-only — asserted, not torn
     * down). Campaign assignments are DELIBERATELY untouched (in-flight commercial
     * work survives the relationship ending). Both parties notified (dual-emit).
     */
    public function disconnect(AdminDisconnectRequest $request, Creator $creator, Agency $agency): JsonResponse
    {
        $this->assertAdmin($request);

        /** @var User $admin */
        $admin = $request->user();
        $reason = $request->reason();

        return $this->tenancy->runAs($agency->id, function () use ($agency, $creator, $admin, $reason): JsonResponse {
            $relation = AgencyCreatorRelation::query()
                ->where('agency_id', $agency->id)
                ->where('creator_id', $creator->id)
                ->first();

            // roster → ended only. Anything else (none / pending_request /
            // declined / ended / prospect / external) has nothing to disconnect.
            if ($relation === null || $relation->relationship_status !== RelationshipStatus::Roster) {
                return $this->error(422, 'connection.not_disconnectable', 'There is no active connection to disconnect.');
            }

            $relation = DB::transaction(function () use ($relation, $agency, $creator, $admin, $reason): AgencyCreatorRelation {
                $relation->relationship_status = RelationshipStatus::Ended;
                $relation->save();

                // Delete the pair's pool memberships — SCOPED to THIS agency's
                // pools (pool presence would leak a severed relation). The
                // scope-by-pool-ids is the over-reach break-revert seam: dropping
                // the whereIn deletes another agency's memberships too.
                $poolIds = TalentPool::query()->pluck('id')->all();
                $deleted = TalentPoolMembership::query()
                    ->where('creator_id', $creator->id)
                    ->whereIn('talent_pool_id', $poolIds)
                    ->delete();

                Audit::log(
                    action: AuditAction::AgencyCreatorRelationDisconnected,
                    actor: $admin,
                    subject: $relation,
                    reason: $reason,
                    metadata: ['agency_id' => $agency->ulid, 'pool_memberships_deleted' => $deleted],
                    agencyId: $agency->id,
                );

                return $relation;
            });

            $this->notifyDisconnect($creator, $agency, $admin, $relation);

            return $this->relationResponse($relation, $agency, Response::HTTP_OK, 'connection.disconnected', 'disconnect');
        });
    }

    /**
     * D-6 dual-emit — BOTH parties are notified (in-app + mail). The creator's
     * counterparty is the agency name; each active agency member's counterparty
     * is the creator's display name. ONE RelationDisconnected type + ONE
     * RelationDisconnectedMail, direction-agnostic (the counterparty differs).
     */
    private function notifyDisconnect(Creator $creator, Agency $agency, User $admin, AgencyCreatorRelation $relation): void
    {
        $creatorName = $creator->display_name ?? '';

        // Creator side.
        $creatorUser = $creator->user;
        if ($creatorUser !== null) {
            $this->notifications->notify(
                recipient: $creatorUser,
                type: NotificationType::RelationDisconnected,
                subject: $relation,
                actor: $admin,
                data: ['counterparty_name' => $agency->name],
            );

            if ($creatorUser->email !== '') {
                $this->mail->mailer()
                    ->to($creatorUser->email)
                    ->locale($creatorUser->preferred_language ?: 'en')
                    ->queue(new RelationDisconnectedMail(
                        recipientName: $creatorName,
                        counterpartyName: $agency->name,
                    ));
            }
        }

        // Agency side — every ACTIVE member of the agency.
        $memberships = AgencyMembership::query()
            ->where('agency_id', $agency->id)
            ->whereNotNull('accepted_at')
            ->with('user')
            ->get();

        foreach ($memberships as $membership) {
            $memberUser = $membership->user;
            if ($memberUser === null) {
                continue;
            }

            $this->notifications->notify(
                recipient: $memberUser,
                type: NotificationType::RelationDisconnected,
                subject: $relation,
                actor: $admin,
                data: ['counterparty_name' => $creatorName],
            );

            if ($memberUser->email !== '') {
                $this->mail->mailer()
                    ->to($memberUser->email)
                    ->locale($memberUser->preferred_language ?: 'en')
                    ->queue(new RelationDisconnectedMail(
                        recipientName: $memberUser->name ?? '',
                        counterpartyName: $creatorName,
                    ));
            }
        }
    }

    /**
     * Door 1 — admin send-request. Mirrors the agency store collision matrix:
     * re-request from declined/ended, no-op on any other existing status,
     * net-new otherwise. Rides the existing ConnectionRequestMail. Records the
     * admin_requested verb + admin provenance.
     */
    private function sendRequest(?AgencyCreatorRelation $relation, Agency $agency, Creator $creator, User $admin): JsonResponse
    {
        // No-op surfacing the existing state for any status that is NOT a legal
        // entry point (declined/ended are the only re-request entries).
        if ($relation !== null
            && ! in_array($relation->relationship_status, [RelationshipStatus::Declined, RelationshipStatus::Ended], true)) {
            return $this->relationResponse($relation, $agency, Response::HTTP_OK, $this->noopCodeFor($relation), 'request');
        }

        $isNetNew = $relation === null;

        $relation = DB::transaction(function () use ($relation, $agency, $creator, $admin): AgencyCreatorRelation {
            if ($relation === null) {
                $relation = AgencyCreatorRelation::query()->create([
                    'agency_id' => $agency->id,
                    'creator_id' => $creator->id,
                    'relationship_status' => RelationshipStatus::PendingRequest,
                    'invited_by_user_id' => $admin->id,
                    'invitation_sent_at' => now(),
                    'notification_sent_at' => now(),
                ]);
            } else {
                // declined/ended → pending_request re-engagement.
                $relation->relationship_status = RelationshipStatus::PendingRequest;
                $relation->invited_by_user_id = $admin->id;
                $relation->invitation_sent_at = now();
                $relation->notification_sent_at = now();
                $relation->save();
            }

            Audit::log(
                action: AuditAction::AgencyCreatorRelationAdminRequested,
                actor: $admin,
                subject: $relation,
                metadata: ['agency_id' => $agency->ulid, 'mode' => 'request'],
                agencyId: $agency->id,
            );

            return $relation;
        });

        $this->sendConnectionRequestMail($creator, $agency);

        return $this->relationResponse(
            $relation,
            $agency,
            $isNetNew ? Response::HTTP_CREATED : Response::HTTP_OK,
            $isNetNew ? 'connection.requested' : 'connection.re_requested',
            'request',
        );
    }

    /**
     * Door 2 — admin direct-connect (records an offline agreement). Idempotent
     * no-op if already rostered; otherwise elevates/creates a `roster` relation.
     * MANDATORY reason (the admin_connected verb requiresReason). Dual-emit:
     * in-app RelationAdminConnected + AdminConnectedMail to the creator.
     */
    private function directConnect(?AgencyCreatorRelation $relation, Agency $agency, Creator $creator, User $admin, string $reason): JsonResponse
    {
        if ($relation !== null && $relation->relationship_status === RelationshipStatus::Roster) {
            return $this->relationResponse($relation, $agency, Response::HTTP_OK, 'connection.already_connected', 'direct');
        }

        $isNetNew = $relation === null;

        $relation = DB::transaction(function () use ($relation, $agency, $creator, $admin, $reason): AgencyCreatorRelation {
            if ($relation === null) {
                $relation = AgencyCreatorRelation::query()->create([
                    'agency_id' => $agency->id,
                    'creator_id' => $creator->id,
                    'relationship_status' => RelationshipStatus::Roster,
                    'invited_by_user_id' => $admin->id,
                    'invitation_sent_at' => now(),
                    'notification_sent_at' => now(),
                ]);
            } else {
                // pending_request / declined / ended / prospect / external →
                // roster. Direct-connect supersedes a pending ask.
                $relation->relationship_status = RelationshipStatus::Roster;
                $relation->invited_by_user_id = $admin->id;
                $relation->notification_sent_at = now();
                $relation->save();
            }

            Audit::log(
                action: AuditAction::AgencyCreatorRelationAdminConnected,
                actor: $admin,
                subject: $relation,
                reason: $reason,
                metadata: ['agency_id' => $agency->ulid, 'mode' => 'direct'],
                agencyId: $agency->id,
            );

            return $relation;
        });

        $this->notifyDirectConnect($creator, $agency, $admin, $relation);

        return $this->relationResponse(
            $relation,
            $agency,
            $isNetNew ? Response::HTTP_CREATED : Response::HTTP_OK,
            'connection.direct_connected',
            'direct',
        );
    }

    /**
     * Door 2 dual-emit — the creator is notified IMMEDIATELY (in-app + mail),
     * naming the agency, with a "contact support if unexpected" line. Guarded so
     * a missing creator user/email never 500s the write that already committed.
     */
    private function notifyDirectConnect(Creator $creator, Agency $agency, User $admin, AgencyCreatorRelation $relation): void
    {
        $user = $creator->user;
        if ($user === null) {
            return;
        }

        $this->notifications->notify(
            recipient: $user,
            type: NotificationType::RelationAdminConnected,
            subject: $relation,
            actor: $admin,
            data: ['agency_name' => $agency->name],
        );

        if ($user->email !== '') {
            $this->mail
                ->mailer()
                ->to($user->email)
                ->locale($user->preferred_language ?: 'en')
                ->queue(new AdminConnectedMail(
                    creatorDisplayName: $creator->display_name ?? '',
                    agencyName: $agency->name,
                ));
        }
    }

    /**
     * Door 1 mail — the EXISTING ConnectionRequestMail (no new mail), localized
     * to the creator's preferred language. Mirrors AgencyConnectionRequestController.
     */
    private function sendConnectionRequestMail(Creator $creator, Agency $agency): void
    {
        $user = $creator->user;
        if ($user === null || $user->email === '') {
            return;
        }

        $this->mail
            ->mailer()
            ->to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue(new ConnectionRequestMail(
                creatorDisplayName: $creator->display_name ?? '',
                agencyName: $agency->name,
            ));
    }

    private function noopCodeFor(AgencyCreatorRelation $relation): string
    {
        return $relation->isPendingRequest()
            ? 'connection.already_requested'
            : 'connection.already_connected';
    }

    private function relationResponse(AgencyCreatorRelation $relation, Agency $agency, int $status, string $code, string $mode): JsonResponse
    {
        return response()->json([
            'data' => [
                'type' => 'admin_connection',
                'id' => $relation->ulid,
                'attributes' => [
                    'agency_id' => $agency->ulid,
                    'agency_name' => $agency->name,
                    'relationship_status' => $relation->relationship_status->value,
                    'mode' => $mode,
                ],
            ],
            'meta' => [
                'code' => $code,
            ],
        ], $status);
    }

    private function error(int $status, string $code, string $detail): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => (string) $status,
                'code' => $code,
                'detail' => $detail,
            ]],
        ], $status);
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if ($user->type !== UserType::PlatformAdmin) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
