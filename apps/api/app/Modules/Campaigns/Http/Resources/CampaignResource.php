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
                // AH-069 D1 — the posting posture, read in the POSITIVE
                // direction the label uses: `true` means the creator posts the
                // deliverable and the assignment continues through
                // posted → verified. `false` means approval IS completion.
                'creator_posts_content' => $campaign->creator_posts_content,
                'is_marketplace_visible' => $campaign->is_marketplace_visible,

                // Jobs board (AH-054). `listed_on_jobs_board` is the agency's
                // stored intent, NOT "currently visible" — a terminal campaign
                // keeps the flag while being invisible (D5). Agency-side
                // surfaces only; nothing creator-facing consumes this chunk.
                'listed_on_jobs_board' => $campaign->listed_on_jobs_board,
                'listing_duration' => $campaign->listing_duration,
                'listing_fee' => $campaign->listing_fee,
                'listing_languages' => $campaign->listing_languages,
                'listing_regions' => $campaign->listing_regions,
                // AH-118 — the STORED switch (it is inert while the flag is off).
                'restrict_to_listing_regions' => $campaign->restrict_to_listing_regions,
                'listing_examples_url' => $campaign->listing_examples_url,
                // AH-120 — the STORED end date (inert while the flag is off). A
                // plain `Y-m-d`: it is a calendar date in UTC, not an instant.
                'exclusivity_ends_on' => $campaign->exclusivity_ends_on?->toDateString(),
                // AH-120 — the blocked brands, on show / create / update ONLY
                // (the list never loads the pivot, so the keys are absent there
                // rather than wrongly empty). `ids` round-trips on a write;
                // `brands` is for display, and flags an archived brand, which
                // stays blocked. Agency-side only, never a creator surface.
                ...($campaign->relationLoaded('exclusiveBrands') ? [
                    'exclusive_brand_ids' => $campaign->exclusiveBrands->pluck('ulid')->values()->all(),
                    'exclusive_brands' => $campaign->exclusiveBrands->map(static fn (Brand $b): array => [
                        'id' => $b->ulid,
                        'name' => $b->name,
                        'is_archived' => $b->trashed(),
                    ])->values()->all(),
                ] : []),
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
                        'color' => $brand->color,
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
