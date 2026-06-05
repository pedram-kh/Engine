<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Resources;

use App\Modules\Campaigns\Models\CampaignDraft;
use App\Modules\Creators\Http\Resources\CreatorResource;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One `campaign_drafts` row (Sprint 9 Chunk 1, D-1). Exposes the submission
 * payload + the (Chunk-2-populated) review trail. Media attachments carry a
 * presigned `view_url` / `thumbnail_view_url` so Chunk 2's review drawer can
 * preview them without exposing the raw private-disk path.
 *
 * @mixin CampaignDraft
 */
final class CampaignDraftResource extends JsonResource
{
    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CampaignDraft $draft */
        $draft = $this->resource;

        return [
            'id' => $draft->ulid,
            'type' => 'campaign_draft',
            'attributes' => [
                'version' => $draft->version,
                'submitted_at' => $draft->submitted_at?->toIso8601String(),
                'caption' => $draft->caption,
                'hashtags' => $draft->hashtags,
                'mentions' => $draft->mentions,
                'media' => $this->mapMedia($draft),
                'review_status' => $draft->review_status->value,
                'reviewed_at' => $draft->reviewed_at?->toIso8601String(),
                'review_feedback' => $draft->review_feedback,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapMedia(CampaignDraft $draft): array
    {
        $attachments = $draft->media_attachments ?? [];

        return array_values(array_map(function (array $item): array {
            $s3Path = is_string($item['s3_path'] ?? null) ? $item['s3_path'] : null;
            $thumbnailPath = is_string($item['thumbnail_path'] ?? null) ? $item['thumbnail_path'] : null;

            return [
                's3_path' => $s3Path,
                'mime_type' => $item['mime_type'] ?? null,
                'kind' => $item['kind'] ?? null,
                'thumbnail_path' => $thumbnailPath,
                'duration_seconds' => $item['duration_seconds'] ?? null,
                'view_url' => $this->signedViewUrl($s3Path),
                'thumbnail_view_url' => $this->signedViewUrl($thumbnailPath),
            ];
        }, $attachments));
    }

    /**
     * Mint a presigned GET URL against the private `media` disk. Returns null
     * when the path is null or the disk is not S3 (e.g. Storage::fake in
     * tests) — mirrors {@see CreatorResource}.
     */
    private function signedViewUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        return $disk->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
    }
}
