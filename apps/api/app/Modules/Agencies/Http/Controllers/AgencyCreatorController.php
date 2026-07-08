<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Modules\Agencies\Concerns\FiltersCreatorColumns;
use App\Modules\Agencies\Http\Requests\ListAgencyRosterRequest;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Brands\Http\Controllers\BrandController;
use App\Modules\Creators\Enums\BlockType;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Http\Controllers\Admin\AdminCreatorController;
use App\Modules\Creators\Http\Controllers\CreatorAvailabilityController;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\Availability\AvailabilityConflictService;
use App\Modules\Creators\Services\Availability\AvailabilityExpansionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 *   ?available_from=&?available_to=
 *              availability range filter (Sprint 6.5, D-6). A creator is
 *              "available within [from, to]" iff they have NO overlapping HARD
 *              block in that window (soft blocks never exclude — mirrors
 *              {@see AvailabilityConflictService}).
 *              Availability is NOT a SQL predicate (it's per-creator RRULE
 *              intervals, no stored status), so it can't join the paginated
 *              whereHas. Instead we expand the FILTERED relation set in PHP
 *              (batched — {@see AvailabilityExpansionService::expandMany()}),
 *              find the busy creator ids, and apply them as a `whereNotIn`
 *              BEFORE paginating — so meta.total / last_page / page contents
 *              stay correct (D-3, no filter-within-page). Activates only when
 *              BOTH bounds are present; a one-sided range is ignored.
 *
 * Deliberately deferred (later Sprint 6 chunks):
 *   - handle search (creator_social_accounts) → follow-on, social-adapter era (D-2).
 *   - follower / engagement filters → social metrics are null today (disabled
 *     affordance on the FE, D-4).
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
    // The creator-column filters + `?q=` FTS were extracted to a shared trait
    // (Sprint 6.6a, D-3) so the discovery surface reuses the SAME logic — the
    // driver-aware FTS branch in particular stays single-source. The roster's
    // call-sites (applyCreatorFilters / applySearchFilter) are unchanged; the
    // relation-coupled filters (status, availability) stay private below.
    use FiltersCreatorColumns;

    /**
     * Hard ceiling on the availability window span (D-6). Mirrors
     * {@see CreatorAvailabilityController}'s
     * MAX_WINDOW_DAYS — bounds recurrence expansion so a pathological
     * `?available_from=...&available_to=...` can't generate an unbounded
     * occurrence set per creator. Combined with the per-agency bound on the
     * filtered relation set, neither expansion vector is unbounded.
     */
    private const int MAX_WINDOW_DAYS = 366;

    /**
     * The relationship statuses EXCLUDED from the default (unfiltered) roster
     * index (Sprint 6.6b, D-6). The roster is "my working relationships," not a
     * request inbox — so the two lifecycle-in-flight statuses are hidden unless
     * the agency explicitly filters to them via a chip.
     *
     * @var list<RelationshipStatus>
     */
    private const array DEFAULT_EXCLUDED_STATUSES = [
        RelationshipStatus::PendingRequest,
        RelationshipStatus::Declined,
    ];

    public function __construct(
        private readonly AvailabilityExpansionService $expansion,
    ) {}

    public function index(ListAgencyRosterRequest $request, Agency $agency): JsonResponse
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
            // The contact email lives on the related User; it is eager-loaded
            // here (one extra query for the whole page, NOT per-row) so the
            // roster list can surface it without an N+1 — `user_id` is added to
            // the creator select so the belongsTo can hydrate.
            ->with([
                'creator:id,ulid,user_id,display_name,country_code,primary_language,accent,categories,application_status',
                'creator.user:id,email',
            ])
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
        $this->applyAvailabilityFilter($query, $request);

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
     * Status filter on the relation itself (Sprint 6.6b, D-6) — a
     * default-when-unfiltered, explicit-when-filtered rule that MUST satisfy
     * both halves:
     *
     *   - NO `?status=`  → the default real-relationship set: exclude
     *     `pending_request` + `declined` (the roster is not a request inbox).
     *   - `?status=X`    → return EXACTLY that status, INCLUDING
     *     `pending_request` / `declined` (the "show my pending requests" /
     *     "who declined me" chips).
     *
     * ⚠ The default-exclude is NOT an unconditional `whereNotIn` — that would
     * break the chip (filtering BY pending_request would return nothing).
     * Break-revert: making the exclusion unconditional fails the "filtering by
     * pending_request returns them" test.
     *
     * Unknown value → empty page (the SPA only sends valid chips), mirroring
     * the admin index's `tryFrom` → `whereRaw('1 = 0')` precedent.
     *
     * @param  Builder<AgencyCreatorRelation>  $query
     */
    private function applyStatusFilter(Builder $query, Request $request): void
    {
        $statusInput = $request->query('status');

        // Unfiltered: default real-relationship set — exclude the two
        // lifecycle-in-flight statuses (but NOT unconditionally; this branch
        // only runs when no explicit status was requested).
        if (! is_string($statusInput) || $statusInput === '') {
            $query->whereNotIn(
                'agency_creator_relations.relationship_status',
                array_map(static fn (RelationshipStatus $s): string => $s->value, self::DEFAULT_EXCLUDED_STATUSES),
            );

            return;
        }

        $status = RelationshipStatus::tryFrom($statusInput);
        if ($status === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        // Explicit filter → exactly that status, including pending_request /
        // declined when those chips are selected.
        $query->where('agency_creator_relations.relationship_status', $status->value);
    }

    /**
     * Availability range filter (D-6). Excludes creators with an overlapping
     * HARD block in [available_from, available_to] (soft never excludes — D-2,
     * mirroring AvailabilityConflictService).
     *
     * Availability can't be a SQL predicate (per-creator RRULE intervals, no
     * stored status), so it can't join the paginated whereHas. Instead we
     * filter-before-pagination with CORRECT counts (D-3):
     *
     *   1. pluck the creator ids of the ALREADY-filtered relation set (one
     *      light query, bounded by the agency's roster size);
     *   2. batch-expand them ({@see AvailabilityExpansionService::expandMany()},
     *      2 queries total) over the window;
     *   3. collect the BUSY ids (any hard occurrence in-window);
     *   4. apply `whereNotIn(creator_id, busy)` to the live query.
     *
     * Step 4 turns the availability exclusion into a real SQL predicate once
     * PHP knows the busy set, so the subsequent ->paginate() reports a correct
     * meta.total / last_page and returns the right page rows — no
     * filter-within-page (which would leave meta.total counting the pre-filter
     * set and desync the pager).
     *
     * The window is day-granular + inclusive of the `to` day (D-6, divergence
     * #1): an agency picking "June 8–12" means the whole of those days. The
     * span is clamped to MAX_WINDOW_DAYS to bound recurrence expansion.
     *
     * Activates only when BOTH bounds are present (divergence #3) — a one-sided
     * range is ignored, never defaulted-forward.
     *
     * @param  Builder<AgencyCreatorRelation>  $query
     */
    private function applyAvailabilityFilter(Builder $query, ListAgencyRosterRequest $request): void
    {
        if (! $request->filled('available_from') || ! $request->filled('available_to')) {
            return;
        }

        // Day-granular, inclusive of the `to` day, normalized server-side so
        // the client never constructs the boundary and the window math lives
        // in one place (divergence #1). Half-open: [from 00:00, to+1d 00:00).
        $windowStart = CarbonImmutable::parse((string) $request->input('available_from'))->startOfDay();
        $windowEnd = CarbonImmutable::parse((string) $request->input('available_to'))->startOfDay()->addDay();

        // Clamp the span so recurrence expansion stays bounded (mirrors the
        // availability list's MAX_WINDOW_DAYS).
        $maxEnd = $windowStart->addDays(self::MAX_WINDOW_DAYS);
        if ($windowEnd->greaterThan($maxEnd)) {
            $windowEnd = $maxEnd;
        }

        // The filtered relation set's creator ids (clone so the pluck doesn't
        // consume the live builder). Bounded by the agency's roster size.
        /** @var list<int> $creatorIds */
        $creatorIds = (clone $query)
            ->pluck('agency_creator_relations.creator_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($creatorIds === []) {
            return;
        }

        $expanded = $this->expansion->expandMany($creatorIds, $windowStart, $windowEnd);

        $busyIds = [];
        foreach ($expanded as $creatorId => $occurrences) {
            foreach ($occurrences as $occurrence) {
                if ($occurrence->block->block_type === BlockType::Hard) {
                    $busyIds[] = $creatorId;
                    break;
                }
            }
        }

        if ($busyIds !== []) {
            $query->whereNotIn('agency_creator_relations.creator_id', $busyIds);
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
                // hard | soft (null when not blacklisted) — lets the roster
                // list distinguish a hard exclusion from a soft warning, the
                // same hard/soft axis the detail page renders.
                'blacklist_type' => $relation->blacklist_type?->value,
                'internal_rating' => $relation->internal_rating,
                'total_campaigns_completed' => $relation->total_campaigns_completed,
                'total_paid_minor_units' => $relation->total_paid_minor_units,
                'last_engaged_at' => $relation->last_engaged_at?->toIso8601String(),
                // creator_id is the creator ULID — useful for Sprint 6's
                // click-through; this chunk's rows do NOT navigate (D-c5-4).
                'creator_id' => $creator?->ulid,
                'display_name' => $creator?->display_name,
                // Contact email (lives on the related User, eager-loaded). The
                // agency-holds-a-relation invariant makes this appropriate on
                // the roster list — the same privacy basis as the detail view.
                'email' => $creator?->user?->email,
                // Application lifecycle state (Chunk 5b): display-only, NOT
                // filterable — lets the agency tell an approved/usable creator
                // from one still pending/incomplete/rejected. Distinct axis
                // from relationship_status above.
                'application_status' => $creator?->application_status->value,
                'country_code' => $creator?->country_code,
                'primary_language' => $creator?->primary_language,
                'accent' => $creator?->accent,
                'categories' => $creator?->categories,
            ],
        ];
    }
}
