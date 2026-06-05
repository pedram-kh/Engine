<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Http\Requests\CreateCampaignRequest;
use App\Modules\Campaigns\Http\Requests\UpdateCampaignRequest;
use App\Modules\Campaigns\Http\Resources\CampaignResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     */
    public function update(UpdateCampaignRequest $request, Agency $agency, Campaign $campaign): CampaignResource
    {
        $this->assertBelongsToAgency($campaign, $agency);
        Gate::authorize('update', $campaign);

        $before = $this->auditableSnapshot($campaign);

        $updates = $request->validated();
        if (isset($updates['budget_currency'])) {
            $updates['budget_currency'] = strtoupper((string) $updates['budget_currency']);
        }
        $campaign->fill($updates)->save();

        Audit::log(
            action: AuditAction::CampaignUpdated,
            subject: $campaign,
            before: $before,
            after: $this->auditableSnapshot($campaign->fresh() ?? $campaign),
        );

        return new CampaignResource(
            ($campaign->fresh() ?? $campaign)->loadCount('assignments')->load(['brand:id,ulid,name', 'agency:id,ulid']),
        );
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
