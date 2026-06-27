<?php

declare(strict_types=1);

namespace App\Modules\Creators\Support;

use App\Modules\Agencies\Http\Resources\AgencyCreatorDetailResource;
use App\Modules\Agencies\Http\Resources\CreatorPublicProfileResource;
use App\Modules\Creators\Enums\PortfolioProcessingStatus;
use App\Modules\Creators\Http\Resources\CreatorResource;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for serialising a creator's portfolio items to the
 * SPA-consumable summary shape (ad-hoc AH-004).
 *
 * Before this presenter, three resources — {@see CreatorResource}
 * (creator owner + platform-admin), {@see AgencyCreatorDetailResource}
 * (agency roster), and {@see CreatorPublicProfileResource}
 * (agency discover) — each carried their own copy of the mapping + signed-URL
 * minting. They are now all routed through here so the load-bearing
 * **server-authoritative `ready`-gate lives in exactly one place** and a future
 * fourth portfolio surface cannot silently re-introduce the leak.
 *
 * The gate (AH-004 Q2/Q5): a `processing` image's raw S3 object is EXIF-bearing
 * and must never be reachable, and a `failed` item has no serveable asset — so
 * `view_url` and `thumbnail_view_url` are minted ONLY when
 * `processing_status === ready`; otherwise both are null. `processing_status`
 * is always emitted so each surface can render a processing / failed state.
 * Link items (no `s3_path`) are `ready` by definition and expose `external_url`.
 */
final class PortfolioItemPresenter
{
    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * Map a creator's portfolio items to the summary list shape.
     *
     * @return list<array<string, mixed>>
     */
    public function mapForCreator(Creator $creator): array
    {
        $items = $creator->relationLoaded('portfolioItems')
            ? $creator->portfolioItems
            : $creator->portfolioItems()->get();

        // array_values() pins the JSON list shape for Larastan.
        return array_values(
            $items
                ->map(fn (CreatorPortfolioItem $item): array => $this->mapItem($item))
                ->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function mapItem(CreatorPortfolioItem $item): array
    {
        $isReady = $item->processing_status === PortfolioProcessingStatus::Ready;

        return [
            'id' => $item->ulid,
            'kind' => $item->kind->value,
            'processing_status' => $item->processing_status->value,
            'title' => $item->title,
            'description' => $item->description,
            's3_path' => $item->s3_path,
            // GATE: withhold signed URLs until the asset is sanitised + ready.
            'view_url' => $isReady ? $this->signedViewUrl($item->s3_path) : null,
            // Download (AH-004 D10): a presigned GET that forces `attachment`
            // so the browser saves the FULL-RES sanitised asset (never the
            // thumbnail). It rides the SAME resource — so it inherits each
            // surface's authorization and the same `ready`-gate; it is never a
            // broader grant than view. Link items have no file to download.
            'download_url' => $isReady ? $this->signedDownloadUrl($item) : null,
            'external_url' => $item->external_url,
            'thumbnail_path' => $item->thumbnail_path,
            'thumbnail_view_url' => $isReady ? $this->signedViewUrl($item->thumbnail_path) : null,
            'mime_type' => $item->mime_type,
            'size_bytes' => $item->size_bytes,
            'duration_seconds' => $item->duration_seconds,
            'position' => $item->position,
        ];
    }

    /**
     * Mint a presigned GET URL against the private `media` disk. Returns null
     * when the path is null OR the disk's adapter is not S3-compatible (test
     * fakes use the local driver, which throws on temporaryUrl()).
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

    /**
     * Mint a presigned GET that forces a browser download of the full-res
     * source object via `ResponseContentDisposition=attachment`. Returns null
     * for link items (no file) or a non-S3 disk (test fakes).
     */
    private function signedDownloadUrl(CreatorPortfolioItem $item): ?string
    {
        if ($item->s3_path === null) {
            return null;
        }

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        $filename = $this->downloadFilename($item);

        return $disk->temporaryUrl(
            $item->s3_path,
            now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
            ['ResponseContentDisposition' => 'attachment; filename="'.$filename.'"'],
        );
    }

    /**
     * A safe download filename: the item title (alnum/dash/underscore only) or
     * a fallback, suffixed with the stored object's extension.
     */
    private function downloadFilename(CreatorPortfolioItem $item): string
    {
        $extension = pathinfo($item->s3_path ?? '', PATHINFO_EXTENSION);
        $base = $item->title !== null && $item->title !== ''
            ? (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $item->title)
            : 'portfolio-'.$item->ulid;
        $base = trim($base, '-') ?: 'portfolio-'.$item->ulid;

        return $extension !== '' ? $base.'.'.$extension : $base;
    }
}
