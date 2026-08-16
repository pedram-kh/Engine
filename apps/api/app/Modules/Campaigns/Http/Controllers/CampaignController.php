<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Boards\Http\Resources\BoardResource;
use App\Modules\Boards\Models\Board;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Boards\Support\BoardDefaults;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Http\Requests\CreateCampaignRequest;
use App\Modules\Campaigns\Http\Requests\UpdateCampaignRequest;
use App\Modules\Campaigns\Http\Resources\CampaignResource;
use App\Modules\Campaigns\Jobs\AutoRejectPendingApplicationsJob;
use App\Modules\Campaigns\Jobs\SendJobPostedNotificationsJob;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Agency-side campaign CRUD (Sprint 8 Chunk 1, D-8/D-10).
 *
 *   - index  — list (filter by brand / status / date), agency-scoped, any member.
 *   - store  — create (admin/manager gate).
 *   - show   — single campaign, any member.
 *   - update — Settings edit (admin/manager gate).
 *
 * `campaign.created` / `campaign.updated` are logged MANUALLY (the Brand
 * precedent) with the free-text `brief` redacted from the snapshot.
 */
final class CampaignController
{
    /**
     * GET /api/v1/agencies/{agency}/campaigns
     */
    public function index(Request $request, Agency $agency): JsonResponse
    {
        Gate::authorize('viewAny', Campaign::class);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $query = Campaign::query()
            ->where('campaigns.agency_id', $agency->id)
            ->with(['brand:id,ulid,name', 'agency:id,ulid'])
            ->withCount('assignments');

        $this->applyBrandFilter($query, $request, $agency);
        $this->applyStatusFilter($query, $request);
        $this->applyDateFilters($query, $request);

        $paginator = $query->orderByDesc('campaigns.created_at')
            ->orderByDesc('campaigns.id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => CampaignResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/agencies/{agency}/campaigns
     */
    public function store(CreateCampaignRequest $request, Agency $agency): JsonResponse
    {
        Gate::authorize('create', Campaign::class);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        $brand = Brand::query()
            ->where('ulid', $validated['brand_id'])
            ->where('agency_id', $agency->id)
            ->firstOrFail();

        $campaign = Campaign::query()->create([
            'agency_id' => $agency->id,
            'brand_id' => $brand->id,
            'created_by_user_id' => $actor->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'objective' => $validated['objective'],
            'status' => CampaignStatus::Draft,
            'budget_minor_units' => $validated['budget_minor_units'],
            'budget_currency' => strtoupper((string) $validated['budget_currency']),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'posting_window_starts_at' => $validated['posting_window_starts_at'] ?? null,
            'posting_window_ends_at' => $validated['posting_window_ends_at'] ?? null,
            'brief' => $validated['brief'] ?? null,
            'target_creator_count' => $validated['target_creator_count'] ?? null,
            'requires_per_campaign_contract' => $validated['requires_per_campaign_contract'] ?? false,

            // AH-069 D1 — this array is a WHITELIST, not `$fillable`, so a field
            // missing from here validates, returns 201 and never persists (the
            // AH-054 catch). The `?? true` fallback IS the Q1 safety floor: a
            // caller that does not name the field gets today's behaviour
            // (posting expected), matching the column default. The create form
            // always names it.
            'creator_posts_content' => $validated['creator_posts_content'] ?? true,

            // Jobs-board listing copy (AH-054, D2). `listed_on_jobs_board` is
            // absent by design — create never lists (D4); the column default
            // (false) carries it.
            'listing_duration' => $validated['listing_duration'] ?? null,
            'listing_fee' => $validated['listing_fee'] ?? null,
            'listing_languages' => $validated['listing_languages'] ?? null,
            'listing_regions' => $validated['listing_regions'] ?? null,
            'listing_examples_url' => $validated['listing_examples_url'] ?? null,
        ]);

        Audit::log(
            action: AuditAction::CampaignCreated,
            subject: $campaign,
            after: $this->auditableSnapshot($campaign),
        );

        return (new CampaignResource($campaign->load(['brand:id,ulid,name', 'agency:id,ulid'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/agencies/{agency}/campaigns/{campaign}
     */
    public function show(Request $request, Agency $agency, Campaign $campaign): CampaignResource
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        return new CampaignResource(
            $campaign->loadCount('assignments')->load(['brand:id,ulid,name', 'agency:id,ulid']),
        );
    }

    /**
     * PATCH /api/v1/agencies/{agency}/campaigns/{campaign}
     *
     * The Settings edit (D-8/D-10) — admin/manager only.
     *
     * Returns the resource on success; the one refusal path (AH-069 D6 — the
     * posting toggle cannot be turned off while cards sit in the posting column)
     * returns a 422 envelope directly, hence the union.
     */
    public function update(UpdateCampaignRequest $request, Agency $agency, Campaign $campaign): CampaignResource|JsonResponse
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('update', $campaign);

        $before = $this->auditableSnapshot($campaign);

        $updates = $request->validated();
        if (isset($updates['budget_currency'])) {
            $updates['budget_currency'] = strtoupper((string) $updates['budget_currency']);
        }

        // AH-056 (D4/D6) — the LISTING FLIP detector. `listed_on_jobs_board`
        // has exactly one write path on the platform (this method: `store()`
        // strips it, and nothing else assigns the column), so comparing the
        // stored value against the incoming one here catches every transition
        // there is. No domain event, no listener, no scheduler.
        //
        // §5.32 reinterpretation, recorded: the plan reached for a new campaign
        // event to hang this on. It does not need one — AH-054's F3 already put
        // `listed_on_jobs_board` in the audit snapshot pair three lines below,
        // which means the before/after comparison is not a new mechanism but a
        // read of one that already ships and is already tested. The INTENT was
        // "fire exactly once, on the false→true edge, without depending on the
        // scheduler"; a new event would have added machinery to reach the same
        // place from further away.
        // AH-058 (D5) — the TERMINAL flip detector, deliberately the same three
        // lines in the same shape as the listing flip above rather than a new
        // `CampaignStatusChanged` event: AH-056's ruling that a campaign event
        // adds machinery to reach the same place from further away has not been
        // given new evidence to overturn, and status has one write path (here).
        $wasTerminal = $campaign->status->isTerminal();

        // AH-069 (D6/Q4) — refuse the flip to OFF while cards sit in the posting
        // column. Turning posting off would stop that column being RENDERED, and
        // those cards would silently vanish from the agency's board — present in
        // the database, invisible on screen, with no way to move them. Refusing
        // the flip is the only answer that neither deletes a row nor hides one.
        if (
            $request->has('creator_posts_content')
            && ! $request->boolean('creator_posts_content')
            && $campaign->creator_posts_content
        ) {
            $stranded = $this->cardsOnPostingColumns($campaign);

            if ($stranded->isNotEmpty()) {
                $names = $stranded->pluck('creator_name')->filter()->values();

                return ErrorResponse::single(
                    $request,
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'campaign.posting_cards_present',
                    trans_choice(
                        'campaigns.posting_toggle.cards_present',
                        $stranded->count(),
                        ['count' => $stranded->count(), 'creators' => $names->join(', ')],
                    ),
                    meta: [
                        'count' => $stranded->count(),
                        // ULIDs, not database ids — the agency's client can act
                        // on these, and they leak nothing.
                        'assignment_ids' => $stranded->pluck('assignment_ulid')->values()->all(),
                        'card_ids' => $stranded->pluck('card_ulid')->values()->all(),
                    ],
                );
            }
        }

        $wasListed = $campaign->listed_on_jobs_board;
        $willList = ! $wasListed
            && $request->has('listed_on_jobs_board')
            && $request->boolean('listed_on_jobs_board');

        if ($willList) {
            // Display metadata for the recency chip, written on the flip and
            // only on the flip. The read scope remains the sole visibility
            // authority — it never consults this column.
            $updates['listed_at'] = now();
        }

        $campaign->fill($updates)->save();

        Audit::log(
            action: AuditAction::CampaignUpdated,
            subject: $campaign,
            before: $before,
            after: $this->auditableSnapshot($campaign->fresh() ?? $campaign),
        );

        // Dispatched off the POST-SAVE state, not off the request: a payload
        // that claimed `true` but was refused by a validator, or that arrived
        // on an already-listed campaign, must not enqueue a fan-out. Plain
        // dispatch AFTER the save, so the worker can never read a campaign that
        // is not yet listed — and not `dispatchAfterResponse()`, which would
        // run a mail loop inside the web process (Q3).
        if (! $wasListed && $campaign->listed_on_jobs_board) {
            SendJobPostedNotificationsJob::dispatch($campaign->id);
        }

        // The same post-save reasoning for the terminal edge: a campaign that
        // just became `completed` or `cancelled` answers the applications still
        // waiting on it. The job re-reads and re-filters, so a re-cancel is a
        // no-op rather than a second round of notices.
        if (! $wasTerminal && $campaign->status->isTerminal()) {
            AutoRejectPendingApplicationsJob::dispatch($campaign->id);
        }

        return new CampaignResource(
            ($campaign->fresh() ?? $campaign)->loadCount('assignments')->load(['brand:id,ulid,name', 'agency:id,ulid']),
        );
    }

    /**
     * The cards currently sitting on a posting-only column of this campaign's
     * board (AH-069, D6/Q4).
     *
     * "Posting-only" is derived exactly as the render filter derives it — the
     * posting family targets the column and nothing else does
     * ({@see BoardResource::hiddenColumnIds()}) — so the set this refuses to
     * strand is precisely the set the render filter would hide. Two different
     * rules here would be a bug generator.
     *
     * Returns an empty collection when the campaign has no board yet, which is
     * the common case for a campaign being configured before anyone is invited.
     *
     * @return Collection<int, array{assignment_ulid: string, card_ulid: string, creator_name: string|null}>
     */
    private function cardsOnPostingColumns(Campaign $campaign): Collection
    {
        // No `withoutGlobalScope` here: we are inside the agency-scoped route and
        // the board belongs to the campaign we just authorised, so the tenant
        // scope is defence, not an obstacle.
        $board = Board::query()
            ->where('campaign_id', $campaign->id)
            ->with(['automations:id,board_id,event_key,target_column_id'])
            ->first();

        if ($board === null) {
            return collect();
        }

        $postingKeys = BoardDefaults::postingFamilyEventKeys();

        $postingOnlyColumnIds = $board->automations
            ->whereNotNull('target_column_id')
            ->groupBy('target_column_id')
            ->filter(fn (Collection $automations): bool => $automations
                ->pluck('event_key')
                ->unique()
                ->every(static fn (string $key): bool => in_array($key, $postingKeys, true)))
            ->keys()
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        if ($postingOnlyColumnIds === []) {
            return collect();
        }

        return BoardCard::query()
            ->where('board_id', $board->id)
            ->whereIn('column_id', $postingOnlyColumnIds)
            ->with(['assignment:id,ulid,creator_id', 'assignment.creator:id,display_name'])
            ->get()
            ->map(fn (BoardCard $card): array => [
                'assignment_ulid' => (string) $card->assignment?->ulid,
                'card_ulid' => $card->ulid,
                'creator_name' => $card->assignment?->creator?->display_name,
            ])
            ->values();
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    private function applyBrandFilter(Builder $query, Request $request, Agency $agency): void
    {
        $brandUlid = $request->query('brand');
        if (! is_string($brandUlid) || $brandUlid === '') {
            return;
        }

        $brand = Brand::query()
            ->where('ulid', $brandUlid)
            ->where('agency_id', $agency->id)
            ->first();

        // Unknown / cross-agency brand → empty page (not a 422), mirroring
        // the roster filter convention.
        $query->where('campaigns.brand_id', $brand !== null ? $brand->id : -1);
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    private function applyStatusFilter(Builder $query, Request $request): void
    {
        $statusInput = $request->query('status');
        if (! is_string($statusInput) || $statusInput === '' || $statusInput === 'all') {
            return;
        }

        $status = CampaignStatus::tryFrom($statusInput);
        if ($status === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('campaigns.status', $status->value);
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    private function applyDateFilters(Builder $query, Request $request): void
    {
        $from = $request->query('starts_from');
        if (is_string($from) && $from !== '') {
            $query->whereDate('campaigns.starts_at', '>=', $from);
        }

        $to = $request->query('starts_to');
        if (is_string($to) && $to !== '') {
            $query->whereDate('campaigns.starts_at', '<=', $to);
        }
    }

    /**
     * Audit-safe snapshot — the structured/free-text `brief` is NEVER copied
     * into an audit row.
     *
     * `listed_on_jobs_board` IS included (AH-054, F3): a visibility flip is
     * exactly the kind of state change an audit trail exists to explain, and
     * it is a boolean, not content. The free-text/jsonb listing fields stay
     * OUT, on the same reasoning that keeps `brief` out — agency-authored
     * content does not belong in an audit snapshot.
     *
     * @return array<string, mixed>
     */
    private function auditableSnapshot(Campaign $campaign): array
    {
        return [
            'name' => $campaign->name,
            'objective' => $campaign->objective->value,
            'status' => $campaign->status->value,
            'budget_minor_units' => $campaign->budget_minor_units,
            'budget_currency' => $campaign->budget_currency,
            'brand_id' => $campaign->brand_id,
            'agency_id' => $campaign->agency_id,
            'target_creator_count' => $campaign->target_creator_count,
            'requires_per_campaign_contract' => $campaign->requires_per_campaign_contract,
            // AH-069 D1 — included on the same reasoning as the two toggles
            // above, and with more force: this one decides whether an approval
            // ends the assignment, so "who turned it off, and when" is a
            // question the trail must be able to answer.
            'creator_posts_content' => $campaign->creator_posts_content,
            'listed_on_jobs_board' => $campaign->listed_on_jobs_board,
        ];
    }

    /**
     * Belt-and-suspenders cross-tenant check — SubstituteBindings resolves
     * {campaign} before tenancy.agency sets the context. 404 (not 403) to
     * avoid leaking ULID validity (docs/05-SECURITY-COMPLIANCE.md §7).
     */
    private function assertBelongsToAgency(Campaign $campaign, Agency $agency): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }
    }
}
