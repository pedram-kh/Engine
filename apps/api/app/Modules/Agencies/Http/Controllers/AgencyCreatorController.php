<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Http\Controllers\BrandController;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Http\Controllers\Admin\AdminCreatorController;
use App\Modules\Creators\Models\Creator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/agencies/{agency}/creators — the agency roster list
 * ("my creators"). Sprint 4 Chunk 5 (D-c5-1).
 *
 * A rich-but-bounded forerunner of Sprint 6's internal creator matching:
 * the agency's relations across ALL relationship_status values
 * (roster / prospect / external), joined to their creators, with the four
 * filters that have backing data today (D-c5-1):
 *
 *   ?status=   relationship_status (roster|prospect|external)
 *   ?country=  creators.country_code  (+ idx_creators_country_code)
 *   ?language= creators.primary_language
 *   ?category= creators.categories jsonb containment (+ GIN on pgsql;
 *              json_each on the SQLite test DB — both via whereJsonContains)
 *
 * Deliberately deferred (Sprint 5/6, inventory B4/B5 + D-c5-2/3/4):
 *   - FTS name/bio search  → Sprint 6 (tsvector spec-only, not built).
 *   - follower / engagement filters → social metrics are null today.
 *   - availability filter  → table exists but unpopulated, no CRUD (Sprint 5).
 *   - saved talent pools   → no schema (Sprint 6).
 *   - internal_rating editing + internal_notes → Sprint 6 roster mgmt.
 *   - row → creator-detail navigation → no agency-side detail exists (B7).
 *
 * Pattern: agency tenancy mirrors {@see BrandController::index}
 * (the `tenancy.agency` stack + the belt-and-suspenders explicit
 * `agency_id` filter from the chunk-1 dashboard precedent); the clamped
 * pagination + slim hand-rolled `{data, meta}` shape mirrors
 * {@see AdminCreatorController::index}
 * (D-c5-6).
 *
 * Slim resource (D-c5-5): a hand-rolled per-row shape — NOT CreatorResource,
 * which mints signed S3 URLs + eager-loads social/portfolio/kyc per call
 * (an N+1 signing disaster on a list). No signed URLs, no heavy relations,
 * and NO internal_notes (GDPR-sensitive, audit-excluded).
 *
 * Blacklisted relations are INCLUDED here (with the flag visible) — unlike
 * the dashboard KPI which excludes them. A management list and a count are
 * different surfaces: the agency should see whom they've blacklisted in
 * their own roster.
 */
final class AgencyCreatorController
{
    public function index(Request $request, Agency $agency): JsonResponse
    {
        Gate::authorize('viewAny', AgencyCreatorRelation::class);

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $query = AgencyCreatorRelation::query()
            // Belt-and-suspenders on top of the BelongsToAgency global
            // scope (mirrors DashboardSummaryController) — agency A never
            // sees agency B's relations even if the tenant context is
            // somehow unset.
            ->where('agency_creator_relations.agency_id', $agency->id)
            // whereHas enforces a non-soft-deleted creator exists (the
            // Creator SoftDeletes global scope applies to the EXISTS
            // subquery) AND carries the country/language/category filters.
            ->whereHas('creator', function (Builder $creatorQuery) use ($request): void {
                $this->applyCreatorFilters($creatorQuery, $request);
            })
            // Eager-load only the slim display columns — no social /
            // portfolio / kyc relations, no signed-URL minting.
            ->with('creator:id,ulid,display_name,country_code,primary_language,categories')
            // Default sort: creator display_name ASC via a correlated
            // subquery (avoids a join + hydration clobber), with a stable
            // id tiebreaker. NULL display_names (prospects mid-wizard) sort
            // first on SQLite / last on Postgres — irrelevant for named rows.
            ->orderBy(
                Creator::query()
                    ->select('display_name')
                    ->whereColumn('creators.id', 'agency_creator_relations.creator_id'),
            )
            ->orderBy('agency_creator_relations.id');

        $this->applyStatusFilter($query, $request);

        $paginator = $query->paginate($perPage)->withQueryString();

        /** @var list<AgencyCreatorRelation> $rows */
        $rows = $paginator->items();

        $data = array_map($this->toRow(...), $rows);

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
     * Status filter on the relation itself. Unknown value → empty page
     * (the SPA only ever sends valid chips), mirroring the admin index's
     * `tryFrom` → `whereRaw('1 = 0')` precedent.
     *
     * @param  Builder<AgencyCreatorRelation>  $query
     */
    private function applyStatusFilter(Builder $query, Request $request): void
    {
        $statusInput = $request->query('status');
        if (! is_string($statusInput) || $statusInput === '') {
            return;
        }

        $status = RelationshipStatus::tryFrom($statusInput);
        if ($status === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('agency_creator_relations.relationship_status', $status->value);
    }

    /**
     * Apply the creator-column filters inside the `whereHas('creator')`
     * subquery. Each is optional and composable (they AND together).
     *
     * `?category=` uses whereJsonContains: on Postgres this compiles to the
     * `@>` containment operator served by idx_creators_categories_gin; on
     * the SQLite test DB it compiles to a `json_each(...)` EXISTS — so the
     * query degrades gracefully across both drivers with no branching.
     *
     * @param  Builder<Model>  $creatorQuery
     */
    private function applyCreatorFilters(Builder $creatorQuery, Request $request): void
    {
        $country = $request->query('country');
        if (is_string($country) && $country !== '') {
            $creatorQuery->where('country_code', $country);
        }

        $language = $request->query('language');
        if (is_string($language) && $language !== '') {
            $creatorQuery->where('primary_language', $language);
        }

        $category = $request->query('category');
        if (is_string($category) && $category !== '') {
            $creatorQuery->whereJsonContains('categories', $category);
        }
    }

    /**
     * Slim per-row shape (D-c5-5). Carries internal_rating (read-only) and
     * the denormalized counters; deliberately omits internal_notes and any
     * signed media URLs.
     *
     * @return array<string, mixed>
     */
    private function toRow(AgencyCreatorRelation $relation): array
    {
        $creator = $relation->creator;

        return [
            'id' => $relation->ulid,
            'type' => 'agency_creator_relations',
            'attributes' => [
                'relationship_status' => $relation->relationship_status->value,
                'is_blacklisted' => $relation->is_blacklisted,
                'internal_rating' => $relation->internal_rating,
                'total_campaigns_completed' => $relation->total_campaigns_completed,
                'total_paid_minor_units' => $relation->total_paid_minor_units,
                'last_engaged_at' => $relation->last_engaged_at?->toIso8601String(),
                // creator_id is the creator ULID — useful for Sprint 6's
                // click-through; this chunk's rows do NOT navigate (D-c5-4).
                'creator_id' => $creator?->ulid,
                'display_name' => $creator?->display_name,
                'country_code' => $creator?->country_code,
                'primary_language' => $creator?->primary_language,
                'categories' => $creator?->categories,
            ],
        ];
    }
}
