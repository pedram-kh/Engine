<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Mail\ConnectionRequestMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/agencies/{agency}/creators/discover/{creator}/connection-request
 *   — the AGENCY half of the two-sided connection lifecycle (Sprint 6.6b, D-7).
 *
 * A STATEFUL WRITE, deliberately NOT an action on the read-only
 * {@see AgencyCreatorDiscoveryController}: it has its own policy ability
 * (admin/manager — staff 403), its own mailable (D-9), and its own audit
 * footprint. It sits in the same `agencies/{agency}` tenancy stack
 * (`auth:web → tenancy.agency → tenancy`), so the BelongsToAgency global
 * scope is set to the path agency; a non-member 404s before this runs.
 *
 * The creator must pass the SAME fail-closed discoverable gate as the
 * discovery reads (approved + is_discoverable, + the implicit SoftDeletes
 * scope) — you can only request a creator you could discover; a
 * non-discoverable / non-approved creator 404s, not probeable by ULID.
 *
 * State machine (D-1/D-2) — fail-closed, only two legal entry states:
 *
 *   (none)   → pending_request   W1, net-new (201). Mail fired.
 *   declined → pending_request   explicit re-engagement (D-4, 200). Mail fired.
 *                                NOT a silent no-op — the status flips.
 *   pending_request → (no-op)    already asked; surface the existing state (200).
 *   roster          → (no-op)    already connected; surface the state (200).
 *   prospect/external → (no-op)  a real relation already exists (200).
 *
 * Idempotency: the `pending_request`/`roster` no-ops mirror the bulk-invite
 * `already_invited` precedent — they surface the current status with a code,
 * never duplicating a row or firing a second mail. NO magic-link token/expiry
 * is ever set (the distinguishing feature vs `prospect`).
 *
 * Audit: the relation's create / status-transition is captured by the
 * Audited trait's auto `agency_creator_relation.created` / `.updated` rows
 * (relationship_status is on the model's auditableAllowlist), so the lifecycle
 * is auditable without a dedicated verb.
 */
final class AgencyConnectionRequestController
{
    public function __construct(
        private readonly MailFactory $mail,
    ) {}

    public function store(Request $request, Agency $agency, Creator $creator): JsonResponse
    {
        Gate::authorize('sendRequest', AgencyCreatorRelation::class);

        // Fail-closed discoverable gate — identical whitelist to the discovery
        // reads. A non-approved / non-discoverable / soft-deleted creator 404s,
        // so a relation can never be opened against a creator the agency could
        // not have discovered.
        if ($creator->application_status !== ApplicationStatus::Approved || ! $creator->is_discoverable) {
            abort(404);
        }

        // The calling agency's existing relation (if any). The global scope is
        // set to the path agency by tenancy.agency; the explicit agency_id
        // filter is the belt-and-suspenders mirror of the roster controller.
        $relation = AgencyCreatorRelation::query()
            ->where('agency_id', $agency->id)
            ->where('creator_id', $creator->id)
            ->first();

        // Sprint 7 (B2) — the hard-blacklist send gate, BEFORE the state
        // machine. A HARD agency-wide blacklist on this relation BLOCKS the
        // send (a typed 422 failure), regardless of the current
        // relationship_status. soft does NOT block (warn-only, D-1) and falls
        // through to the normal flow. This is orthogonal to the state machine:
        // it neither creates nor transitions a relation. Break-revert: drop
        // this guard → a hard-blacklisted creator can be re-requested.
        if ($relation !== null
            && $relation->is_blacklisted
            && $relation->blacklist_type === BlacklistType::Hard) {
            return response()->json([
                'message' => 'This creator is hard-blacklisted and cannot be sent a connection request.',
                'errors' => ['blacklist' => ['This creator is hard-blacklisted and cannot be sent a connection request.']],
                'meta' => ['code' => 'connection.blacklisted'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // No-op surfacing the existing state for any status that is NOT a legal
        // entry point. The only existing-row transition is `declined → pending`
        // (D-4); every other status (pending_request / roster / prospect /
        // external) surfaces its state without duplicating a row or firing a
        // second mail.
        if ($relation !== null && $relation->relationship_status !== RelationshipStatus::Declined) {
            return $this->relationResponse($relation, Response::HTTP_OK, $this->noopCodeFor($relation));
        }

        /** @var User $actor */
        $actor = $request->user();

        $isNetNew = $relation === null;

        $relation = DB::transaction(function () use ($relation, $agency, $creator, $actor): AgencyCreatorRelation {
            if ($relation === null) {
                // W1 — net-new. NO token/expiry (the prospect distinction).
                return AgencyCreatorRelation::query()->create([
                    'agency_id' => $agency->id,
                    'creator_id' => $creator->id,
                    'relationship_status' => RelationshipStatus::PendingRequest,
                    'invited_by_user_id' => $actor->id,
                    'invitation_sent_at' => now(),
                    'notification_sent_at' => now(),
                ]);
            }

            // declined → pending_request — explicit re-engagement (D-4). The
            // status actually flips; this is NOT swallowed as a no-op.
            $relation->relationship_status = RelationshipStatus::PendingRequest;
            $relation->invited_by_user_id = $actor->id;
            $relation->invitation_sent_at = now();
            $relation->notification_sent_at = now();
            $relation->save();

            return $relation;
        });

        $this->sendNotification($creator, $agency);

        return $this->relationResponse(
            $relation,
            $isNetNew ? Response::HTTP_CREATED : Response::HTTP_OK,
            $isNetNew ? 'connection.requested' : 'connection.re_requested',
        );
    }

    /**
     * Queue the creator's connection-request email (D-9), localized to their
     * preferred language. Mirrors SignUpService / CreatorApprovedMail. The
     * creator always has a User (Creator is bootstrapped with one); guard
     * defensively so a missing email never 500s the write that already
     * succeeded.
     */
    private function sendNotification(Creator $creator, Agency $agency): void
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

    private function relationResponse(AgencyCreatorRelation $relation, int $status, string $code): JsonResponse
    {
        return response()->json([
            'data' => [
                'type' => 'agency_connection_request',
                'id' => $relation->ulid,
                'attributes' => [
                    'relationship_status' => $relation->relationship_status->value,
                ],
            ],
            'meta' => [
                'code' => $code,
            ],
        ], $status);
    }
}
