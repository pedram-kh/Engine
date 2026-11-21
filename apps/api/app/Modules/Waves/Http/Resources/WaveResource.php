<?php

declare(strict_types=1);

namespace App\Modules\Waves\Http\Resources;

use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use App\Modules\Waves\Services\WaveCampaignLink;
use App\Modules\Waves\Services\WaveMarketService;
use App\Modules\Waves\Steps\WaveTrackerCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a Wave (PL-1.1). Ulids only: no internal id and no `agency_id` is emitted. Dates are
 * `Y-m-d` (go-live) and ISO 8601 (timestamps). The linked campaign, the brand and the creator are small
 * relationship objects for the table and the wave page.
 *
 * @mixin Wave
 */
final class WaveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $wave = $this->resource;
        assert($wave instanceof Wave);

        $campaign = $wave->campaign;
        $wave->loadMissing('markets.countries');

        // PL-1.3 (Q1) — the six strategy steps as six segments. The list sets it for the whole page with one grouped
        // query; any other caller gets one query here. It never writes: an unprovisioned wave reads "not started".
        $progress = $wave->relationLoaded('progressData')
            ? $wave->getRelation('progressData')
            : WaveTrackerCalculator::progressForWaves([$wave->id])[$wave->id];

        return [
            'id' => $wave->ulid,
            'type' => 'waves',
            'attributes' => [
                'name' => $wave->name,
                'go_live_on' => $wave->go_live_on->format('Y-m-d'),
                // PL-2.4 — a market is a named group of countries; `id` is its ulid. `markets_version` is the token an
                // edit that carries `markets` must send back (Q12).
                'markets' => $wave->markets->map(static fn (WaveMarket $m): array => [
                    'id' => $m->ulid,
                    'name' => $m->name,
                    'code' => $m->code,
                    'countries' => $m->countryCodes(),
                ])->values()->all(),
                'markets_version' => WaveMarketService::version($wave->markets),
                'status' => $wave->status->value,
                // PL-1.2 (P5, Q3) — true while a card of the linked campaign sits in a working column, so the page can
                // explain a disabled control. The server is the authority; this is only the explanation.
                'progress' => $progress,
                'link_locked' => $campaign !== null && app(WaveCampaignLink::class)->isLocked($campaign->id),
                'created_at' => $wave->created_at->toIso8601String(),
                'updated_at' => $wave->updated_at->toIso8601String(),
            ],
            'relationships' => [
                'brand' => [
                    'data' => [
                        'id' => $wave->brand->ulid,
                        'type' => 'brands',
                        'attributes' => ['name' => $wave->brand->name],
                    ],
                ],
                'campaign' => $campaign === null ? null : [
                    'data' => [
                        'id' => $campaign->ulid,
                        'type' => 'campaigns',
                        'attributes' => ['name' => $campaign->name, 'status' => $campaign->status->value, 'campaign_type' => $campaign->campaign_type->value],
                    ],
                ],
                'created_by' => [
                    'data' => [
                        'id' => $wave->createdBy->ulid,
                        'type' => 'users',
                        'attributes' => ['name' => $wave->createdBy->name],
                    ],
                ],
            ],
        ];
    }
}
