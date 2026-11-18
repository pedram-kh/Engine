<?php

declare(strict_types=1);

namespace App\Modules\Waves\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Http\Resources\WaveResource;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Services\WaveRuleViolation;
use App\Modules\Waves\Services\WaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Pennant\Feature;

/**
 * The campaign side of the wave link (PL-1.2, P4: "from either side"):
 *
 *   GET    campaigns/{campaign}/wave-options   this brand's waves that have no campaign
 *   PUT    campaigns/{campaign}/wave           link (or change) the campaign's wave: body { wave_id }
 *   DELETE campaigns/{campaign}/wave           unlink
 *
 * Every action calls the SAME {@see WaveService} methods as the wave side, so the two sides cannot disagree. Order:
 * the `waves_enabled` flag (404 `not_found`), the campaign ownership check (404, never 403), the policy (admin and
 * manager link and unlink; staff may read the options), then validation and the service.
 */
final class CampaignWaveController
{
    public function __construct(private readonly WaveService $waves) {}

    public function options(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertCampaign($campaign, $agency);
        Gate::authorize('viewAny', Wave::class);

        $waves = Wave::query()
            ->where('agency_id', $agency->id)
            ->where('brand_id', $campaign->brand_id)
            ->whereNull('campaign_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $waves->map(static fn (Wave $w): array => [
                'id' => $w->ulid,
                'type' => 'wave_options',
                'attributes' => ['name' => $w->name, 'go_live_on' => $w->go_live_on->format('Y-m-d')],
            ])->values(),
        ]);
    }

    public function link(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertCampaign($campaign, $agency);
        Gate::authorize('create', Wave::class);

        /** @var array{wave_id: string} $data */
        $data = Validator::make($request->all(), ['wave_id' => ['required', 'string', 'size:26']])->validate();

        try {
            $wave = $this->waves->linkFromCampaign($agency, $campaign, $data['wave_id']);
        } catch (WaveRuleViolation $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        }

        return (new WaveResource($wave->load(['brand', 'campaign', 'createdBy'])))->response();
    }

    public function unlink(Request $request, Agency $agency, Campaign $campaign): JsonResponse|Response
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertCampaign($campaign, $agency);
        Gate::authorize('create', Wave::class);

        try {
            $this->waves->unlinkFromCampaign($agency, $campaign);
        } catch (WaveRuleViolation $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        }

        return response()->noContent();
    }

    private function flagOff(Request $request): ?JsonResponse
    {
        if (Feature::active(WavesEnabled::NAME)) {
            return null;
        }

        return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
    }

    /** Route-model binding resolves `{campaign}` before tenancy exists, so ownership is asserted here (404, not 403). */
    private function assertCampaign(Campaign $campaign, Agency $agency): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}
