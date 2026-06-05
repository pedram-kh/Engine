<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Resources;

use App\Modules\Campaigns\Models\CampaignPostedContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `campaign_posted_content` row (Sprint 9 Chunk 1, D-2). Chunk 1 exposes
 * the creator-reported fields + the `verification_status` (which stays
 * `pending` until Chunk 2's verification job advances it).
 *
 * @mixin CampaignPostedContent
 */
final class CampaignPostedContentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CampaignPostedContent $posted */
        $posted = $this->resource;

        return [
            'id' => $posted->ulid,
            'type' => 'campaign_posted_content',
            'attributes' => [
                'platform' => $posted->platform,
                'post_url' => $posted->post_url,
                'platform_post_id' => $posted->platform_post_id,
                'posted_at' => $posted->posted_at?->toIso8601String(),
                'verified_at' => $posted->verified_at?->toIso8601String(),
                'verification_status' => $posted->verification_status->value,
            ],
        ];
    }
}
