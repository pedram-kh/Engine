<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Resources;

use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lightweight summary of one `campaign_drafts` row for the campaign-wide
 * Drafts tab list. Deliberately omits media + presigned URLs — those load
 * lazily via {@see CampaignDraftResource} when the review drawer opens
 * (`showAssignment`).
 *
 * `assignment.verification_status` is the LATEST posted-content row's status
 * (the same D-7 field CampaignAssignmentResource emits) — it lets the Drafts
 * tab offer the failure-resolution row action next to Review. Emitted only
 * when `latestPostedContent` is eager-loaded (null otherwise); the FE treats
 * null/absent as "no action".
 *
 * @mixin CampaignDraft
 */
final class CampaignDraftListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CampaignDraft $draft */
        $draft = $this->resource;

        $assignment = $draft->relationLoaded('assignment') ? $draft->assignment : null;
        $creator = $assignment instanceof CampaignAssignment && $assignment->relationLoaded('creator')
            ? $assignment->creator
            : null;

        $verificationStatus = null;
        if ($assignment instanceof CampaignAssignment && $assignment->relationLoaded('latestPostedContent')) {
            $verificationStatus = $assignment->latestPostedContent?->verification_status->value;
        }

        return [
            'id' => $draft->ulid,
            'type' => 'campaign_draft_list_item',
            'attributes' => [
                'version' => $draft->version,
                'review_status' => $draft->review_status->value,
                'submitted_at' => $draft->submitted_at?->toIso8601String(),
                'review_feedback' => $draft->review_feedback,
                'assignment' => $assignment instanceof CampaignAssignment ? [
                    'id' => $assignment->ulid,
                    'status' => $assignment->status->value,
                    'verification_status' => $verificationStatus,
                    'creator' => $creator instanceof Creator ? [
                        'id' => $creator->ulid,
                        'display_name' => $creator->display_name,
                    ] : null,
                ] : null,
            ],
        ];
    }
}
