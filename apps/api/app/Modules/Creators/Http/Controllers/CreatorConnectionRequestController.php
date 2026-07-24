<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\BlacklistType;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The CREATOR half of the two-sided connection lifecycle (Sprint 6.6b, D-8).
 *
 *   GET    /api/v1/creators/me/connection-requests             list pending requests
 *   POST   /api/v1/creators/me/connection-requests/{relation}/accept   accept → roster
 *   POST   /api/v1/creators/me/connection-requests/{relation}/decline  decline → declined
 *
 * Mirrors the documented `/me/assignments/{assignment}/accept|decline` shape
 * (04-API-DESIGN.md §). Lives in the Creators module's `creators/me/*` group
 * (`auth:web → tenancy.set → verified`); allowlisted in docs/security/tenancy.md
 * § 4 for the Creator-is-global discipline.
 *
 * CROSS-MODULE READ (honest-deviation note): these endpoints live in Creators
 * but read/write an Agencies model (AgencyCreatorRelation) — the same
 * cross-module shape as the dashboard summary. Ownership is STRUCTURAL: every
 * relation is resolved from `$request->user()->creator` by `creator_id`, never
 * from a path agency id, so one creator can never accept another's request.
 *
 * ⚠ The BelongsToAgency global scope is bypassed deliberately here (the one
 * justified HTTP bypass, mirroring the discovery controller): the authenticated
 * caller is a CREATOR, who may have requests from MANY agencies. If a creator
 * also happened to be an agency member (Sprint 4+), an ambient tenant context
 * would otherwise hide every OTHER agency's request — so we scope by
 * `creator_id` only and drop the agency scope. Break-revert: leaving the scope
 * on would make a creator-who-is-also-a-member see only one agency's requests.
 *
 * Fail-closed (D-2): accept/decline reject unless the relation is EXACTLY
 * `pending_request` — a roster/declined/prospect/external/ended row cannot be
 * accepted or declined (422 `connection.not_pending`).
 *
 * ACCEPT re-gates (AH-051 D-2, fail-closed, status unchanged on failure):
 * accepting drives the relation to `roster`, which unlocks contact visibility
 * (D-1) + messaging (AH-010), so accept additionally requires the creator's
 * application to be APPROVED (422 `connection.creator_not_approved`) and the
 * relation not HARD-blacklisted (422 `connection.blacklisted`). Decline is
 * never re-gated.
 */
final class CreatorConnectionRequestController
{
    public function index(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $relations = AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('relationship_status', RelationshipStatus::PendingRequest->value)
            ->with('agency:id,ulid,name')
            ->orderByDesc('invitation_sent_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $relations->map($this->toRow(...))->all(),
        ]);
    }

    public function accept(Request $request, string $relation): JsonResponse
    {
        return $this->transition($request, $relation, RelationshipStatus::Roster, 'connection.accepted');
    }

    public function decline(Request $request, string $relation): JsonResponse
    {
        return $this->transition($request, $relation, RelationshipStatus::Declined, 'connection.declined');
    }

    /**
     * Resolve the relation within the creator's OWN relations, fail-closed
     * guard it is `pending_request`, then flip the status. A non-owned ULID is
     * simply not found (404) — the structural owner-only guard.
     */
    private function transition(Request $request, string $relationUlid, RelationshipStatus $to, string $code): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $relation = AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('ulid', $relationUlid)
            ->first();

        if ($relation === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'connection.not_found',
                    'detail' => 'No connection request found.',
                ]],
            ], 404));
        }

        // Fail-closed: only a pending_request may be accepted or declined
        // (D-2). A roster / declined / prospect / external row is rejected.
        if (! $relation->isPendingRequest()) {
            abort(response()->json([
                'errors' => [[
                    'status' => '422',
                    'code' => 'connection.not_pending',
                    'detail' => 'This connection request is no longer pending.',
                ]],
            ], 422));
        }

        // AH-051 (D-2) — ACCEPT re-gates, fail-closed. Accepting drives the
        // relation to `roster`, which unlocks contact visibility (D-1) and
        // messaging (AH-010); decline never re-gates (declining is always
        // allowed). Both leave the status UNCHANGED on failure, with a distinct
        // 422 code so the client can tell which invariant refused.
        if ($to === RelationshipStatus::Roster) {
            // 1. The creator's application must be APPROVED — a
            //    non-approved creator cannot be elevated onto a roster.
            //    Break-revert: drop this → a non-approved creator can accept.
            if ($creator->application_status !== ApplicationStatus::Approved) {
                abort(response()->json([
                    'errors' => [[
                        'status' => '422',
                        'code' => 'connection.creator_not_approved',
                        'detail' => 'Your creator profile must be approved before you can accept a connection.',
                    ]],
                ], 422));
            }

            // 2. A HARD-blacklisted relation cannot be accepted onto the roster
            //    (an agency may hard-blacklist AFTER sending). soft does NOT
            //    block (warn-only). Break-revert: drop this → a hard-blacklisted
            //    creator can accept onto the roster.
            if ($relation->is_blacklisted && $relation->blacklist_type === BlacklistType::Hard) {
                abort(response()->json([
                    'errors' => [[
                        'status' => '422',
                        'code' => 'connection.blacklisted',
                        'detail' => 'This connection can no longer be accepted.',
                    ]],
                ], 422));
            }
        }

        $relation->relationship_status = $to;
        $relation->save();

        return response()->json([
            'data' => [
                'type' => 'connection_request',
                'id' => $relation->ulid,
                'attributes' => [
                    'relationship_status' => $relation->relationship_status->value,
                ],
            ],
            'meta' => [
                'code' => $code,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(AgencyCreatorRelation $relation): array
    {
        return [
            'id' => $relation->ulid,
            'type' => 'connection_request',
            'attributes' => [
                'relationship_status' => $relation->relationship_status->value,
                'invitation_sent_at' => $relation->invitation_sent_at?->toIso8601String(),
                'agency_id' => $relation->agency->ulid,
                'agency_name' => $relation->agency->name,
            ],
        ];
    }

    private function requireCreator(Request $request): Creator
    {
        /** @var User $user */
        $user = $request->user();
        $creator = $user->creator;

        if ($creator === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'creator.not_found',
                    'detail' => 'No creator profile is associated with this user.',
                ]],
            ], 404));
        }

        return $creator;
    }
}
