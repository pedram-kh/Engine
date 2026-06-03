<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Concerns\FiltersCreatorColumns;
use App\Modules\Agencies\Http\Resources\CreatorDiscoveryResource;
use App\Modules\Agencies\Http\Resources\CreatorPublicProfileResource;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/agencies/{agency}/creators/discover            — browse/search
 *     the GLOBAL creator pool (Sprint 6.6a, D-1).
 * GET /api/v1/agencies/{agency}/creators/discover/{creator}  — view a creator's
 *     PUBLIC profile (D-6).
 *
 * This is the FIRST agency-facing creator query that is NOT relation-scoped: it
 * queries the global `creators` pool, not the agency's `agency_creator_relations`
 * (contrast the roster + detail + availability controllers). Authz is a DISTINCT
 * `discover` ability (D-1) — any agency member; the routes sit in the house
 * `auth:web → tenancy.agency → tenancy` stack, so a non-member 404s before this
 * runs.
 *
 * The fail-closed discoverable GATE (D-2) is a WHITELIST applied to BOTH reads:
 *
 *     application_status = 'approved'  AND  is_discoverable = true
 *     (+ the implicit Creator SoftDeletes global scope)
 *
 * so mid-onboarding / pending / rejected / soft-deleted / opted-out creators
 * are excluded by construction. The detail applies the SAME gate (not just the
 * no-relation rule of D-6) — else a non-discoverable creator would be probeable
 * by ULID (the gate is a whitelist, fail-closed).
 *
 * The "already-connected" annotation (D-4) is computed in ONE query (no N+1):
 * a correlated subquery selecting the CALLING agency's relationship_status for
 * each creator (null ⟹ no relation). ⚠ Privacy (D-7): it is scoped to the
 * calling agency ONLY via an explicit `agency_id = {agency}` filter — it never
 * surfaces any OTHER agency's relation. Break-revert: un-scope that filter and
 * Agency B begins seeing Agency A's relation → the cross-agency isolation test
 * fails.
 *
 * Read-only this chunk (D-9): NO send-request action — that (and the
 * `pending_request`/`declined` statuses) is Sprint 6.6b. The annotation only
 * ever sees today's statuses (roster / prospect / external).
 */
final class AgencyCreatorDiscoveryController
{
    use FiltersCreatorColumns;

    public function index(Request $request, Agency $agency): JsonResponse
    {
        Gate::authorize('discover', AgencyCreatorRelation::class);

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $query = $this->discoverableCreators()
            // Slim card columns only (D-10) — no heavy/leaky columns, no
            // tsvector. The FTS WHERE references search_vector directly, not the
            // SELECT, so it works regardless of the projection.
            ->select([
                'creators.id',
                'creators.ulid',
                'creators.display_name',
                'creators.country_code',
                'creators.primary_language',
                'creators.categories',
                'creators.avatar_path',
            ])
            ->addSelect(['connected_relationship_status' => $this->connectionSubquery($agency)])
            // Stable display-name ASC sort with an id tiebreaker (mirrors the
            // roster's default sort).
            ->orderBy('creators.display_name')
            ->orderBy('creators.id');

        // Shared country / language / category / `?q=` FTS filters (D-3) —
        // identical semantics to the roster, single-source.
        $this->applyCreatorFilters($query, $request);

        $paginator = $query->paginate($perPage)->withQueryString();

        /** @var list<Creator> $rows */
        $rows = $paginator->items();

        $data = array_map(
            fn (Creator $creator): array => (new CreatorDiscoveryResource($creator))->resolve($request),
            $rows,
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
     * The PUBLIC profile (D-5/D-6). Does NOT 404 on no-relation — the whole
     * point is viewing a discovered creator you have no relation with. It DOES
     * 404 when the creator fails the discoverable gate (non-approved /
     * not-discoverable / soft-deleted) — fail-closed, the gate is a whitelist.
     *
     * `{creator}` is route-model-bound by ULID (the SoftDeletes scope already
     * 404s a deleted creator); the approved + is_discoverable legs are checked
     * here.
     */
    public function show(Request $request, Agency $agency, Creator $creator): JsonResponse
    {
        Gate::authorize('discover', AgencyCreatorRelation::class);

        if ($creator->application_status !== ApplicationStatus::Approved || ! $creator->is_discoverable) {
            abort(404);
        }

        $creator->loadMissing(['socialAccounts', 'portfolioItems']);

        // The calling agency's OWN relation status (D-4/D-7) — a single query
        // (one creator, not an N+1). null ⟹ no relation. Scoped explicitly to
        // this agency; the global BelongsToAgency scope is dropped so the only
        // tenancy predicate is the deliberate, auditable agency_id filter.
        $status = AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('creator_id', $creator->id)
            ->value('relationship_status');

        $creator->setAttribute(
            'connected_relationship_status',
            $status instanceof \BackedEnum ? $status->value : $status,
        );

        return (new CreatorPublicProfileResource($creator))->response($request);
    }

    /**
     * The discoverable-pool base query (D-2): the fail-closed whitelist gate.
     * The Creator SoftDeletes global scope supplies the third leg.
     *
     * @return Builder<Creator>
     */
    private function discoverableCreators(): Builder
    {
        return Creator::query()
            ->where('creators.application_status', ApplicationStatus::Approved->value)
            ->where('creators.is_discoverable', true);
    }

    /**
     * Correlated subquery yielding the CALLING agency's relationship_status for
     * each creators row, or null when no relation exists (D-4). Scoped to the
     * calling agency ONLY (D-7) by an explicit agency_id filter; the global
     * tenancy scope is dropped so the agency_id predicate is the single,
     * explicit source of the scope. `limit(1)` is belt-and-suspenders against
     * the (agency_id, creator_id) uniqueness.
     *
     * @return Builder<AgencyCreatorRelation>
     */
    private function connectionSubquery(Agency $agency): Builder
    {
        return AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->select('relationship_status')
            ->whereColumn('agency_creator_relations.creator_id', 'creators.id')
            ->where('agency_creator_relations.agency_id', $agency->id)
            ->limit(1);
    }
}
