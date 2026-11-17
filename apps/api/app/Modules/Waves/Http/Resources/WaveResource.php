<?php

declare(strict_types=1);

namespace App\Modules\Waves\Http\Resources;

use App\Modules\Waves\Models\Wave;
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

        return [
            'id' => $wave->ulid,
            'type' => 'waves',
            'attributes' => [
                'name' => $wave->name,
                'go_live_on' => $wave->go_live_on->format('Y-m-d'),
                'markets' => array_values($wave->markets),
                'status' => $wave->status->value,
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
                        'attributes' => ['name' => $campaign->name, 'status' => $campaign->status->value],
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
