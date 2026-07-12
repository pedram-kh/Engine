<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Resources;

use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Campaigns\Services\AssignmentOfferAttachmentUploadService;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a CampaignAssignment for the agency-side Creators tab
 * (Sprint 8 Chunk 1, read-only — inviting + mutating land in Chunk 2). Money
 * is emitted as raw minor-units integers. Internal `notes` / `cancelled_reason`
 * are deliberately omitted (free-text, GDPR-sensitive).
 *
 * `verification_status` (verification-resolution chunk, D-7) is the LATEST
 * posted-content row's status — it drives the `posted`+failed row action that
 * opens the resolution drawer. Emitted only when `latestPostedContent` is
 * eager-loaded (null otherwise); the FE treats null/absent as "no action".
 *
 * `has_pending_contract` (contract-issue visibility fix) is true when a
 * per-campaign contract is awaiting the creator's acceptance. It lets the
 * Creators tab show "Contract sent — awaiting creator" instead of re-offering
 * "Issue contract" on an accepted assignment (the status stays `accepted`
 * until the creator accepts). Emitted only when `sentContract` is eager-loaded
 * (null otherwise); the FE treats null/absent as "unknown".
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

        $verificationStatus = null;
        if ($assignment->relationLoaded('latestPostedContent')) {
            $verificationStatus = $assignment->latestPostedContent?->verification_status->value;
        }

        $hasPendingContract = null;
        if ($assignment->relationLoaded('sentContract')) {
            $hasPendingContract = $assignment->sentContract !== null;
        }

        return [
            'id' => $assignment->ulid,
            'type' => 'campaign_assignments',
            'attributes' => [
                'status' => $assignment->status->value,
                'agreed_fee_minor_units' => $assignment->agreed_fee_minor_units,
                'agreed_fee_currency' => $assignment->agreed_fee_currency,
                // Invite-offer context (invite-offer-details batch). The
                // attachment URL is a short-lived signed GET minted here, so
                // the download inherits this surface's view authz (AH-004).
                'fee_per' => $assignment->fee_per,
                'offer_description' => $assignment->offer_description,
                'offer_attachment' => $assignment->offer_attachment_path !== null ? [
                    'name' => $assignment->offer_attachment_name,
                    'mime_type' => $assignment->offer_attachment_mime,
                    'size_bytes' => $assignment->offer_attachment_size_bytes,
                    'url' => AssignmentOfferAttachmentUploadService::signedViewUrl($assignment->offer_attachment_path),
                ] : null,
                'countered_fee_minor_units' => $assignment->countered_fee_minor_units,
                'countered_fee_currency' => $assignment->countered_fee_currency,
                'invited_at' => $assignment->invited_at?->toIso8601String(),
                'responded_at' => $assignment->responded_at?->toIso8601String(),
                'posting_due_at' => $assignment->posting_due_at?->toIso8601String(),
                'verification_status' => $verificationStatus,
                'has_pending_contract' => $hasPendingContract,
                'creator' => $creator instanceof Creator ? [
                    'id' => $creator->ulid,
                    'display_name' => $creator->display_name,
                ] : null,
            ],
        ];
    }
}
