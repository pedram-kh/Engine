<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Resources;

use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a CampaignAssignment for the agency-side Creators tab
 * (Sprint 8 Chunk 1, read-only — inviting + mutating land in Chunk 2). Money
 * is emitted as raw minor-units integers. Internal `notes` / `cancelled_reason`
 * are deliberately omitted (free-text, GDPR-sensitive).
 *
 * @mixin CampaignAssignment
 */
final class CampaignAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = $this->resource;
        assert($assignment instanceof CampaignAssignment);

        $creator = $assignment->relationLoaded('creator') ? $assignment->creator : null;

        return [
            'id' => $assignment->ulid,
            'type' => 'campaign_assignments',
            'attributes' => [
                'status' => $assignment->status->value,
                'agreed_fee_minor_units' => $assignment->agreed_fee_minor_units,
                'agreed_fee_currency' => $assignment->agreed_fee_currency,
                'countered_fee_minor_units' => $assignment->countered_fee_minor_units,
                'countered_fee_currency' => $assignment->countered_fee_currency,
                'invited_at' => $assignment->invited_at?->toIso8601String(),
                'responded_at' => $assignment->responded_at?->toIso8601String(),
                'posting_due_at' => $assignment->posting_due_at?->toIso8601String(),
                'creator' => $creator instanceof Creator ? [
                    'id' => $creator->ulid,
                    'display_name' => $creator->display_name,
                ] : null,
            ],
        ];
    }
}
