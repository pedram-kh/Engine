<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Resources;

use App\Modules\Agencies\Services\AgencyFileUploadService;
use App\Modules\Campaigns\Models\CampaignAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The narrow creator-facing presenter for a campaign attachment (Campaign
 * attachments chunk B, AH-092, D4). Owns exactly `{filename, mime_type,
 * size_bytes, url}` — never `storage_key`, never `attached_by`,
 * never `visible_to_creators` (a creator only ever sees rows that already
 * passed the flag).
 *
 * ⚠ MUST only ever be constructed from rows that have already passed BOTH
 * {@see CampaignAttachment::scopeCreatorVisible()} AND the caller's own outer
 * campaign gate (assignment ownership, or `JobsBoardVisibility`) — this
 * resource enforces neither. `url` reuses
 * {@see AgencyFileUploadService::signedDownloadUrl()} verbatim: that signer
 * is mechanically caller-agnostic (no agency-prefix assertion of its own),
 * so the composition at the two call sites — never this resource, never the
 * signer — is the actual security boundary.
 *
 * @mixin CampaignAttachment
 */
final class CreatorCampaignAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attachment = $this->resource;
        assert($attachment instanceof CampaignAttachment);

        $file = $attachment->agencyFile;

        return [
            'filename' => $file->original_filename,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
            'url' => app(AgencyFileUploadService::class)->signedDownloadUrl($file),
        ];
    }
}
