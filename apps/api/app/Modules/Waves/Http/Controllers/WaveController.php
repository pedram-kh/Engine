<?php

declare(strict_types=1);

namespace App\Modules\Waves\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Enums\CampaignType;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Http\Resources\WaveResource;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Services\WaveMarketService;
use App\Modules\Waves\Services\WaveRuleViolation;
use App\Modules\Waves\Services\WaveService;
use App\Modules\Waves\Steps\WaveTrackerCalculator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

/**
 * Waves of a brand (PL-1.1):
 *
 *   GET   brands/{brand}/waves                   index
 *   POST  brands/{brand}/waves                   create (201)
 *   GET   brands/{brand}/waves/campaign-options  the New wave dialog's campaign list
 *   GET   brands/{brand}/waves/{wave}            show
 *   PATCH brands/{brand}/waves/{wave}            edit: name, go-live date, markets, and the link while unlocked (PL-1.2)
 *
 * Order in every action: the `waves_enabled` flag (404 `not_found`, before the policy and before the body is
 * read), the brand and wave ownership check (404, never 403), the policy, then validation and the service.
 * `{brand}` resolves archived brands (an archived brand's page is read-only, not missing); `{wave}` is looked up
 * here, by ulid, so a soft-deleted wave is a 404.
 */
final class WaveController
{
    public function __construct(private readonly WaveService $waves) {}

    public function index(Request $request, Agency $agency, Brand $brand): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        Gate::authorize('viewAny', Wave::class);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $paginator = Wave::query()
            ->where('agency_id', $agency->id)
            ->where('brand_id', $brand->id)
            ->with(['brand', 'campaign', 'createdBy', 'markets.countries'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        // PL-1.3 (Q1): the progress of every wave on the page, ONE grouped query and no write.
        $progress = WaveTrackerCalculator::progressForWaves(array_values(array_map(static fn (Wave $w): int => $w->id, $paginator->items())));
        foreach ($paginator->items() as $item) {
            $item->setRelation('progressData', $progress[$item->id]);
        }

        return response()->json([
            'data' => WaveResource::collection($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, Agency $agency, Brand $brand): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        Gate::authorize('create', Wave::class);

        /** @var array<string, mixed> $data */
        $data = Validator::make($request->all(), $this->rules(creating: true, request: $request))->validate();

        // PL-1.2 (P3) — "create a campaign for this wave now" also creates a campaign: the actor needs that ability
        // too (admin and manager hold both, so there is no new role gap).
        $withNewCampaign = is_array($data['new_campaign'] ?? null);
        if ($withNewCampaign) {
            Gate::authorize('create', Campaign::class);
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            $wave = $withNewCampaign
                ? $this->waves->createWithNewCampaign($agency, $brand, $actor, $data)
                : $this->waves->create($agency, $brand, $actor, $data);
        } catch (WaveRuleViolation $e) {
            return ErrorResponse::single($request, $e->status, $e->errorCode, $e->getMessage(), meta: $e->meta);
        }

        return (new WaveResource($wave->load(['brand', 'campaign', 'createdBy', 'markets.countries'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function campaignOptions(Request $request, Agency $agency, Brand $brand): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        Gate::authorize('viewAny', Wave::class);

        $campaigns = Campaign::query()
            ->where('agency_id', $agency->id)
            ->where('brand_id', $brand->id)
            ->where('campaign_type', CampaignType::ForWave->value)
            ->where('status', '!=', CampaignStatus::Cancelled->value)
            ->whereNotIn('id', Wave::query()->whereNotNull('campaign_id')->select('campaign_id'))
            ->orderBy('name')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $campaigns->map(static fn (Campaign $c): array => [
                'id' => $c->ulid,
                'type' => 'campaign_options',
                'attributes' => ['name' => $c->name, 'status' => $c->status->value],
            ])->values(),
        ]);
    }

    public function show(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', $model);

        return (new WaveResource($model))->response();
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', $model);

        /** @var array<string, mixed> $data */
        $data = Validator::make($request->all(), $this->rules(creating: false))->validate();

        try {
            $updated = $this->waves->update($agency, $brand, $model, $data);
        } catch (WaveRuleViolation $e) {
            return ErrorResponse::single($request, $e->status, $e->errorCode, $e->getMessage(), meta: $e->meta);
        }

        return (new WaveResource($updated->load(['brand', 'campaign', 'createdBy', 'markets.countries'])))->response();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $creating, ?Request $request = null): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        return [
            // PL-1.2 (P3) — mutually exclusive with a campaign_id that names a campaign. `campaign_id: null` ("no
            // campaign") alongside it is tolerated.
            ...($creating ? [
                'new_campaign' => ['sometimes', 'array'],
                'new_campaign.name' => ['required_with:new_campaign', 'string', 'min:1', 'max:255'],
            ] : []),
            'name' => [$presence, 'string', 'min:1', 'max:160'],
            'go_live_on' => [$presence, 'date_format:Y-m-d'],
            // PL-2.4 (Q2, Q12) — ONE contract: a list of markets, each a named group of countries. A plain country code
            // ("SK") is refused: `markets.*` must be an object with only these keys. `id` (a market ulid) is for an edit.
            // `markets_version` (from the wave resource) is required whenever an edit carries `markets`.
            'markets' => [$presence, 'array', 'min:1', 'max:'.WaveMarketService::MAX_MARKETS],
            'markets.*' => ['array:'.($creating ? 'name,code,countries' : 'id,name,code,countries')],
            'markets.*.id' => $creating ? ['prohibited'] : ['sometimes', 'string', 'size:26', 'distinct'],
            'markets.*.name' => ['required', 'string', 'min:1', 'max:60'],
            'markets.*.code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{2,6}$/'],
            // A country twice in ONE market is a validation error here; a country in TWO markets is the rule the service
            // enforces (`wave.country_in_two_markets`), so `distinct` must not run across markets.
            'markets.*.countries' => ['required', 'array', 'min:1', 'max:'.WaveMarketService::MAX_COUNTRIES, static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_array($value) && count($value) !== count(array_unique($value))) {
                    $fail('A country can be listed only once in a market.');
                }
            }],
            'markets.*.countries.*' => ['string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            ...($creating ? [] : ['markets_version' => ['required_with:markets', 'string', 'max:64']]),
            'campaign_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::prohibitedIf(static fn (): bool => $request !== null && is_array($request->input('new_campaign')))],
        ];
    }

    private function flagOff(Request $request): ?JsonResponse
    {
        if (Feature::active(WavesEnabled::NAME)) {
            return null;
        }

        return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
    }

    /**
     * Route-model binding resolves `{brand}` before tenancy context exists, so ownership is asserted here.
     * 404, not 403: non-fingerprinting (docs/05-SECURITY-COMPLIANCE.md §7).
     */
    private function assertBrand(Brand $brand, Agency $agency): void
    {
        if ($brand->agency_id !== $agency->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }

    private function findWave(string $ulid, Agency $agency, Brand $brand): Wave
    {
        return Wave::query()
            ->where('ulid', $ulid)
            ->where('agency_id', $agency->id)
            ->where('brand_id', $brand->id)
            ->with(['brand', 'campaign', 'createdBy', 'markets.countries'])
            ->firstOrFail();
    }
}
