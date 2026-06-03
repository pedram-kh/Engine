<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
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
        $this->requireRosterRelation($agency, $creator);

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
        $this->requireRosterRelation($agency, $creator);

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
     * 404 unless the creator is in this agency's roster (any relationship
     * status) — the exact requireRosterRelation pattern from the availability
     * + detail controllers (D-2b-5). Break-revert: dropping this check lets an
     * agency pool a creator it has no relation with.
     */
    private function requireRosterRelation(Agency $agency, Creator $creator): void
    {
        $hasRelation = AgencyCreatorRelation::query()
            ->where('agency_id', $agency->id)
            ->where('creator_id', $creator->id)
            ->exists();

        if (! $hasRelation) {
            abort(404);
        }
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
