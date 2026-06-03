<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Http\Controllers\BrandController;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Http\Controllers\Admin\AdminCreatorController;
use App\Modules\Creators\Models\Creator;
use Illuminate\Database\Connection;
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
 * (roster / prospect / external), joined to their creators, with the
 * filters that have backing data today:
 *
 *   ?status=   relationship_status (roster|prospect|external)
 *   ?country=  creators.country_code  (+ idx_creators_country_code)
 *   ?language= creators.primary_language
 *   ?category= creators.categories jsonb containment (+ GIN on pgsql;
 *              json_each on the SQLite test DB — both via whereJsonContains)
 *   ?q=        name/bio full-text search (Sprint 6 Chunk 1, D-1). Driver-aware:
 *              Postgres `search_vector @@ plainto_tsquery('simple', ?)` over the
 *              generated tsvector + GIN (migration #…_add_search_vector); SQLite
 *              `LOWER(...) LIKE` substring fallback over display_name/bio. The
 *              two paths diverge in result semantics (FTS = whole-word lexemes;
 *              ILIKE = substring) — documented, not papered over (D-3).
 *
 * Deliberately deferred (later Sprint 6 chunks):
 *   - handle search (creator_social_accounts) → follow-on, social-adapter era (D-2).
 *   - follower / engagement filters → social metrics are null today (disabled
 *     affordance on the FE, D-4).
 *   - a REAL availability filter → no stored status; needs a cheap roster-wide
 *     signal — its own chunk (D-5; disabled affordance on the FE, D-4).
 *   - saved talent pools   → no schema (Sprint 6).
 *   - internal_rating editing + internal_notes → Sprint 6 roster mgmt.
 *   - row → creator-detail navigation → no agency-side detail exists (Chunk 2).
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
            // application_status (Chunk 5b): already on the joined creators
            // table — added to the select only, no new join/query shape.
            ->with('creator:id,ulid,display_name,country_code,primary_language,categories,application_status')
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
     * `?q=` (FTS) is the exception that DOES need a driver branch — see
     * {@see self::applySearchFilter}.
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

        $search = $request->query('q');
        if (is_string($search) && trim($search) !== '') {
            $this->applySearchFilter($creatorQuery, trim($search));
        }
    }

    /**
     * Name/bio full-text search (Sprint 6 Chunk 1, D-1).
     *
     * Driver-aware because FTS has no portable grammar-level degrade (unlike
     * `whereJsonContains`):
     *
     *   - Postgres: `search_vector @@ plainto_tsquery('simple', ?)` against the
     *     generated `tsvector` column + GIN index from the pgsql-guarded
     *     migration. `plainto_tsquery` ANDs the query's lexemes, so a
     *     multi-word `q` narrows (all words must match some token).
     *   - SQLite (test + local dev): `LOWER(...) LIKE` substring match over
     *     `display_name` + `bio`. There is no `tsvector`/`search_vector` column
     *     on SQLite (the migration skips it), so the fallback queries the raw
     *     columns directly. `%`/`_` in the needle are escaped so they're treated
     *     as literals, not wildcards.
     *
     * Result-semantics divergence (D-3, honest-deviation trigger #2): the
     * Postgres path matches whole-word lexemes (token boundaries) while the
     * SQLite path matches substrings — e.g. `q=lov` matches "Lovelace" under
     * SQLite but NOT under Postgres. The `'simple'` tsvector config keeps the
     * two as close as practical (no stemming). The SQLite fallback is the path
     * the CI suite actually exercises and is fully tested; the Postgres branch
     * is verified by a manual local-Postgres pass + a dormant `markTestSkipped`
     * counterpart until Postgres CI lands (~Sprint 8).
     *
     * @param  Builder<Model>  $creatorQuery
     */
    private function applySearchFilter(Builder $creatorQuery, string $search): void
    {
        // `getConnection()` on an Eloquent builder returns a ConnectionInterface,
        // which does not declare getDriverName(). The concrete value is always a
        // \Illuminate\Database\Connection subclass (Postgres in prod, SQLite under
        // test), so we narrow inline for Larastan — mirrors MembershipController.
        $connection = $creatorQuery->getConnection();
        /** @var Connection $connection */
        $isPostgres = $connection->getDriverName() === 'pgsql';

        if ($isPostgres) {
            $creatorQuery->whereRaw(
                "search_vector @@ plainto_tsquery('simple', ?)",
                [$search],
            );

            return;
        }

        // Escape LIKE wildcards so a literal `%`/`_`/`\` in the search term is
        // matched as itself; the ESCAPE clause makes `\` the escape char (SQLite
        // does not treat `\` as an escape by default).
        $needle = mb_strtolower($search);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $needle).'%';

        $creatorQuery->where(function (Builder $inner) use ($like): void {
            $inner->whereRaw("LOWER(display_name) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(bio) LIKE ? ESCAPE '\\'", [$like]);
        });
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
                // Application lifecycle state (Chunk 5b): display-only, NOT
                // filterable — lets the agency tell an approved/usable creator
                // from one still pending/incomplete/rejected. Distinct axis
                // from relationship_status above.
                'application_status' => $creator?->application_status->value,
                'country_code' => $creator?->country_code,
                'primary_language' => $creator?->primary_language,
                'categories' => $creator?->categories,
            ],
        ];
    }
}
