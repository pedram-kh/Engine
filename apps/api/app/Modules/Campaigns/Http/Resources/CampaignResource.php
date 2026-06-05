<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Resources;

use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a Campaign (docs/04-API-DESIGN.md §4 envelope).
 *
 * ULIDs are the public identifiers; integer `id` is never exposed. Money is
 * emitted as raw minor-units integers + the currency (the client formats).
 *
 * @mixin Campaign
 */
final class CampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->resource;
        assert($campaign instanceof Campaign);

        $brand = $campaign->brand;
        assert($brand instanceof Brand);

        return [
            'id' => $campaign->ulid,
            'type' => 'campaigns',
            'attributes' => [
                'name' => $campaign->name,
                'description' => $campaign->description,
                'objective' => $campaign->objective->value,
                'status' => $campaign->status->value,
                'budget_minor_units' => $campaign->budget_minor_units,
                'budget_currency' => $campaign->budget_currency,
                'starts_at' => $campaign->starts_at?->toIso8601String(),
                'ends_at' => $campaign->ends_at?->toIso8601String(),
                'posting_window_starts_at' => $campaign->posting_window_starts_at?->toIso8601String(),
                'posting_window_ends_at' => $campaign->posting_window_ends_at?->toIso8601String(),
                'brief' => $campaign->brief,
                'target_creator_count' => $campaign->target_creator_count,
                'requires_per_campaign_contract' => $campaign->requires_per_campaign_contract,
                'is_marketplace_visible' => $campaign->is_marketplace_visible,
                'published_at' => $campaign->published_at?->toIso8601String(),
                'completed_at' => $campaign->completed_at?->toIso8601String(),
                'assignment_count' => $campaign->assignments_count ?? null,
                'created_at' => $campaign->created_at->toIso8601String(),
                'updated_at' => $campaign->updated_at->toIso8601String(),
            ],
            'relationships' => [
                'brand' => [
                    'data' => [
                        'id' => $brand->ulid,
                        'type' => 'brands',
                        'name' => $brand->name,
                    ],
                ],
                'agency' => [
                    'data' => [
                        'id' => $campaign->agency->ulid,
                        'type' => 'agencies',
                    ],
                ],
            ],
        ];
    }
}
