<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Resources;

use App\Modules\Agencies\Services\AgencyFileUploadService;
use App\Modules\Campaigns\Models\CampaignAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a CampaignAttachment for the agency-side
 * Attachments tab (Campaign attachments chunk B, AH-092).
 *
 * `id` is the FILE's own ulid, not a ulid of this row (there is none — see
 * {@see CampaignAttachment}'s docblock) — it is also the exact segment the
 * PATCH/DELETE routes address (`campaigns/{campaign}/attachments/{file}`),
 * so a client can act on a row using only what this resource already gave
 * it. `storage_key` is never exposed; `download_url` reuses the AH-091
 * signer verbatim (Q2 — the volume argument that keeps URLs off Jobs Board
 * cards does not apply to a campaign's own, small attachment list, and the
 * agency member could reach the same signed URL via the library's own
 * `show()` regardless, so withholding it here buys no security, only a
 * round-trip).
 *
 * @mixin CampaignAttachment
 */
final class CampaignAttachmentResource extends JsonResource
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
            'id' => $file->ulid,
            'type' => 'campaign_attachments',
            'attributes' => [
                'original_filename' => $file->original_filename,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'visible_to_creators' => $attachment->visible_to_creators,
                'download_url' => app(AgencyFileUploadService::class)->signedDownloadUrl($file),
                'attached_at' => $attachment->created_at->toIso8601String(),
            ],
            'relationships' => [
                'attached_by' => $attachment->attachedBy === null ? null : [
                    'data' => [
                        'id' => $attachment->attachedBy->ulid,
                        'type' => 'users',
                        'attributes' => [
                            'name' => $attachment->attachedBy->name,
                        ],
                    ],
                ],
            ],
        ];
    }
}
