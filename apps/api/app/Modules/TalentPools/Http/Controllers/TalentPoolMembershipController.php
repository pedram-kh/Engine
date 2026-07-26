<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Agencies\Support\AgencyCreatorRelationGuard;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Models\Creator;
use App\Modules\TalentPools\Http\Requests\AddPoolCreatorRequest;
use App\Modules\TalentPools\Http\Resources\TalentPoolMemberResource;
use App\Modules\TalentPools\Http\Resources\TalentPoolResource;
use App\Modules\TalentPools\Models\TalentPool;
use App\Modules\TalentPools\Models\TalentPoolMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The pool MEMBERSHIP surface (Sprint 6 Chunk 2b, D-2b-8) — the net-new
 * pivot-write endpoints (no controller precedent: MembershipController is
 * read-only; agency adds go through invitations, not a direct pivot write):
 *
 *   GET    talent-pools/{pool}/creators            — paginated members (detail)
 *   POST   talent-pools/{pool}/creators            — add a creator (idempotent)
 *   DELETE talent-pools/{pool}/creators/{creator}  — remove a creator
 *
 * Every method composes BOTH tenancy checks (D-2b-8):
 *   - assertBelongsToAgency($pool, $agency) — the pool is THIS agency's, and
 *   - requireRosterRelation($agency, $creator) — the creator has an
 *     AgencyCreatorRelation with this agency (any status, D-2b-5: you can't
 *     pool a creator you don't have).
 *
 * Writes (add/remove) are gated by TalentPoolPolicy::update (admin/manager —
 * staff 403). The members LIST is gated by view (any member). The add is
 * idempotent via firstOrCreate keyed by the (pool, creator) unique constraint
 * (adding twice → one row, not a 500 / duplicate). Brand-scope adds NO
 * eligibility constraint (D-2b-4): a creator with no brand link can be added
 * to a brand-scoped pool just the same.
 */
final class TalentPoolMembershipController
{
    /**
     * GET /api/v1/agencies/{agency}/talent-pools/{talent_pool}/creators
     *
     * The pool's members, paginated (default 25/page) so the signed-avatar
     * minting in TalentPoolMemberResource is bounded to one page — the
     * D-2b-7 list/detail boundary (counts on the list, the roster on detail).
     */
    public function index(Request $request, Agency $agency, TalentPool $talentPool): AnonymousResourceCollection
    {
        $this->assertBelongsToAgency($talentPool, $agency);
        Gate::authorize('view', $talentPool);

        $members = $talentPool->creators()
            ->addSelect([
                // The pool member's blacklist STATUS, scoped to the POOL-OWNING
                // agency (D-4 — the privacy pin). Two correlated subqueries on
                // agency_creator_relations, each filtered to BOTH the creator
                // AND `agency_id = pool.agency_id`, so the badge can only ever
                // reflect THIS agency's own blacklist of the creator — never
                // another agency's (mirrors requireRosterRelation's scoped
                // (agency_id, creator_id) lookup + the discovery EXISTS scope).
                // Break-revert: drop the agency_id clause → agency A's blacklist
                // surfaces in agency B's pool → the cross-agency violation.
                'acr_is_blacklisted' => AgencyCreatorRelation::query()
                    ->select('is_blacklisted')
                    ->whereColumn('agency_creator_relations.creator_id', 'creators.id')
                    ->where('agency_creator_relations.agency_id', $talentPool->agency_id)
                    ->limit(1),
                'acr_blacklist_type' => AgencyCreatorRelation::query()
                    ->select('blacklist_type')
                    ->whereColumn('agency_creator_relations.creator_id', 'creators.id')
                    ->where('agency_creator_relations.agency_id', $talentPool->agency_id)
                    ->limit(1),
            ])
            ->orderByPivot('created_at', 'desc')
            ->paginate(25);

        return TalentPoolMemberResource::collection($members);
    }

    /**
     * POST /api/v1/agencies/{agency}/talent-pools/{talent_pool}/creators
     *
     * Body: { "creator_id": "<creator ULID>" }. Idempotent — adding an
     * already-member is a 200 no-op (no second row, no second audit row);
     * a genuine add returns 201.
     */
    public function store(AddPoolCreatorRequest $request, Agency $agency, TalentPool $talentPool): JsonResponse
    {
        $this->assertBelongsToAgency($talentPool, $agency);

        $creator = $this->resolveCreator($request->validated()['creator_id']);
        $relation = AgencyCreatorRelationGuard::requireExisting($agency, $creator);

        // AH-051 follow-up — a SEVERED relation may not be re-pooled. Admin
        // disconnect deletes this pair's memberships (D-6) on the reasoning that
        // pool presence would leak a relationship that has ended; permitting an
        // add here would let the agency undo that a second later and make the
        // deletion decorative. REMOVE stays open (see destroy) so rows can
        // always be cleaned up, and every other status is unaffected — a
        // prospect shortlist is still legitimate curation.
        if ($relation->isEnded()) {
            return ErrorResponse::single(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'pool.relation_ended',
                'This connection has ended, so the creator can no longer be added to a pool.',
            );
        }

        Gate::authorize('update', $talentPool);

        $membership = TalentPoolMembership::query()->firstOrCreate(
            [
                'talent_pool_id' => $talentPool->id,
                'creator_id' => $creator->id,
            ],
            [
                'added_by_user_id' => $request->user()?->id,
            ],
        );

        if ($membership->wasRecentlyCreated) {
            Audit::log(
                action: AuditAction::TalentPoolCreatorAdded,
                subject: $talentPool,
                after: ['creator_id' => $creator->id],
            );
        }

        return (new TalentPoolResource($talentPool->loadCount('creators')->load('brand')))
            ->response()
            ->setStatusCode($membership->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /api/v1/agencies/{agency}/talent-pools/{talent_pool}/creators/{creator}
     *
     * Idempotent — removing a non-member is a 200 no-op. Composes both tenancy
     * checks (D-2b-8). Audited only when a row was actually removed.
     */
    public function destroy(Request $request, Agency $agency, TalentPool $talentPool, Creator $creator): JsonResponse
    {
        $this->assertBelongsToAgency($talentPool, $agency);
        // Deliberately NOT status-gated: removal must stay possible for every
        // status, including `ended`, or a severed relation could strand rows
        // that the add path now refuses to recreate.
        AgencyCreatorRelationGuard::requireExisting($agency, $creator);

        Gate::authorize('update', $talentPool);

        $detached = $talentPool->creators()->detach($creator->id);

        if ($detached > 0) {
            Audit::log(
                action: AuditAction::TalentPoolCreatorRemoved,
                subject: $talentPool,
                before: ['creator_id' => $creator->id],
            );
        }

        return (new TalentPoolResource($talentPool->loadCount('creators')->load('brand')))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Resolve a creator by its ULID (the public API id), or 404. We do NOT
     * 422 on an unknown ULID — a 404 here mirrors route-model binding and
     * avoids fingerprinting which ULIDs are valid.
     */
    private function resolveCreator(string $creatorUlid): Creator
    {
        $creator = Creator::query()->where('ulid', $creatorUlid)->first();

        if ($creator === null) {
            abort(404);
        }

        return $creator;
    }

    /**
     * Belt-and-suspenders cross-tenant check (mirrors TalentPoolController).
     * 404 (not 403) — docs/05-SECURITY-COMPLIANCE.md §7.
     */
    private function assertBelongsToAgency(TalentPool $pool, Agency $agency): void
    {
        if ($pool->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
