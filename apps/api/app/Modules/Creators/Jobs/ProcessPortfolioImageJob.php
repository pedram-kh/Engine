<?php

declare(strict_types=1);

namespace App\Modules\Creators\Jobs;

use App\Modules\Creators\Enums\PortfolioItemKind;
use App\Modules\Creators\Enums\PortfolioProcessingStatus;
use App\Modules\Creators\Http\Controllers\PortfolioController;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use App\Modules\Creators\Services\PortfolioImageProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sanitise a presigned-PUT portfolio image asynchronously (ad-hoc AH-004 Q5).
 *
 * Dispatched by {@see PortfolioController::completeImageUpload()}
 * after the raw bytes land in S3 and the item is created `processing`. Until
 * this job flips the item to `ready`, every portfolio resource WITHHOLDS the
 * signed `view_url` / `thumbnail_view_url`, so the raw EXIF-bearing object is
 * never reachable.
 *
 * Steps:
 *   1. Read the raw object.
 *   2. Megapixel guard (decompression-bomb protection) BEFORE full decode.
 *   3. Re-encode at FULL resolution, EXIF stripped (NOT the avatar 1024px
 *      downscale), and generate a separate thumbnail.
 *   4. Overwrite the raw key in place (destroys the GPS-bearing original),
 *      write the thumbnail, set `thumbnail_path`, flip to `ready`.
 *
 * Any failure (over-cap, corrupt, undecodable) → the item is marked `failed`
 * and KEPT so the creator can see it and delete / re-upload — never a silent
 * forever-`processing`.
 *
 * Worker memory: the live `queue:work` running this job must be sized for a
 * 50 MP decode (≥768 MB) — see docs/reviews/ah-004-portfolio-overhaul-plan.md §6.
 */
final class ProcessPortfolioImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $portfolioItemId) {}

    public function handle(PortfolioImageProcessor $processor): void
    {
        $item = CreatorPortfolioItem::query()->find($this->portfolioItemId);

        // Item gone, already resolved, or not an image awaiting processing —
        // nothing to do (idempotent on re-run).
        if (
            ! $item instanceof CreatorPortfolioItem
            || $item->kind !== PortfolioItemKind::Image
            || $item->processing_status !== PortfolioProcessingStatus::Processing
            || $item->s3_path === null
        ) {
            return;
        }

        $disk = Storage::disk('media');
        $extension = $processor->extensionForMime($item->mime_type)
            ?? pathinfo($item->s3_path, PATHINFO_EXTENSION);

        try {
            $raw = $disk->get($item->s3_path);
            if ($raw === null) {
                $this->markFailed($item);

                return;
            }

            $result = $processor->process($raw, $extension);

            // Overwrite the raw key in place — the GPS-bearing original bytes
            // are replaced by the sanitised full-res image.
            $disk->put($item->s3_path, $result['full']);

            $thumbnailPath = $this->thumbnailPathFor($item->s3_path);
            $disk->put($thumbnailPath, $result['thumbnail']);

            $item->forceFill([
                'thumbnail_path' => $thumbnailPath,
                'processing_status' => PortfolioProcessingStatus::Ready->value,
            ])->save();
        } catch (Throwable) {
            $this->markFailed($item);
        }
    }

    /**
     * Derive the thumbnail key from the source key:
     *   creators/{ulid}/portfolio/{file}.jpg
     *     → creators/{ulid}/portfolio/thumbs/{file}.jpg
     */
    private function thumbnailPathFor(string $sourcePath): string
    {
        $dir = trim(pathinfo($sourcePath, PATHINFO_DIRNAME), '/');
        $base = pathinfo($sourcePath, PATHINFO_BASENAME);

        return $dir.'/thumbs/'.$base;
    }

    private function markFailed(CreatorPortfolioItem $item): void
    {
        $item->forceFill([
            'processing_status' => PortfolioProcessingStatus::Failed->value,
        ])->save();
    }
}
