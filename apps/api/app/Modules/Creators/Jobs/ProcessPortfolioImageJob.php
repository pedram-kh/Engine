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
use Illuminate\Support\Facades\Log;
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
 * Two failure modes are UNCATCHABLE inside handle() and killed the July 2026
 * stuck-`processing` items in production:
 *
 *   - an OOM fatal (PHP dies mid-decode; `catch (Throwable)` never runs), and
 *   - a `$timeout` kill (pcntl SIGALRM terminates the worker process).
 *
 * Both are covered by the {@see self::failed()} hook: once the queue gives up
 * (after {@see self::$tries} attempts) the item is flipped to `failed` so the
 *  creator sees a real failure state instead of an endless spinner.
 *
 * Worker memory: `queue:work --memory` only recycles the worker BETWEEN jobs;
 * the hard cap during a decode is php.ini `memory_limit`. handle() therefore
 * raises the limit to {@see self::MEMORY_LIMIT_FLOOR} when the configured one
 * is lower — the matched pair with MAX_MEGAPIXELS = 50 (see
 * docs/reviews/ah-004-portfolio-overhaul-plan.md §6).
 */
final class ProcessPortfolioImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Minimum `memory_limit` for a near-cap (50 MP) GD decode, per the AH-004
     * §6 envelope. Raised at runtime because the fatal OOM this prevents is
     * uncatchable and strands the item at `processing`.
     */
    private const string MEMORY_LIMIT_FLOOR = '768M';

    /**
     * Only fatal kills (OOM / timeout) reach the queue's retry machinery —
     * application-level decode errors are swallowed by handle() and mark the
     * item `failed` on the first attempt.
     */
    public int $tries = 3;

    /**
     * Worst-case 50 MP decode + two full-res encodes. MUST stay below the
     * connection's `retry_after` (config/queue.php, 300s) or a slow job gets
     * delivered twice.
     */
    public int $timeout = 240;

    /**
     * Seconds between retries — an immediate re-run of a fatal kill just
     * reproduces it under the same instantaneous memory pressure.
     */
    public int $backoff = 30;

    public function __construct(public readonly int $portfolioItemId) {}

    public function handle(PortfolioImageProcessor $processor): void
    {
        $this->ensureMemoryHeadroom();

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
        } catch (Throwable $e) {
            Log::warning('Portfolio image processing failed; item marked failed.', [
                'portfolio_item_id' => $item->id,
                's3_path' => $item->s3_path,
                'mime_type' => $item->mime_type,
                'size_bytes' => $item->size_bytes,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($item);
        }
    }

    /**
     * Called by the queue when the job fails PERMANENTLY (timeout kill on the
     * last attempt, max attempts exceeded after an OOM fatal, …). handle()'s
     * catch never runs for those, so this is the only place that can rescue
     * the item from a forever-`processing` state.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Portfolio image processing job failed permanently.', [
            'portfolio_item_id' => $this->portfolioItemId,
            'exception' => $exception?->getMessage(),
        ]);

        $item = CreatorPortfolioItem::query()->find($this->portfolioItemId);

        if (
            $item instanceof CreatorPortfolioItem
            && $item->processing_status === PortfolioProcessingStatus::Processing
        ) {
            $this->markFailed($item);
        }
    }

    /**
     * Raise `memory_limit` to the floor when the configured limit is lower.
     * `-1` (unlimited) and anything ≥ the floor are left untouched.
     */
    private function ensureMemoryHeadroom(): void
    {
        $current = (string) ini_get('memory_limit');

        if ($current === '-1') {
            return;
        }

        if ($this->toBytes($current) < $this->toBytes(self::MEMORY_LIMIT_FLOOR)) {
            ini_set('memory_limit', self::MEMORY_LIMIT_FLOOR);
        }
    }

    /**
     * Parse a php.ini shorthand size (`512M`, `1G`, `786432K`, plain bytes).
     */
    private function toBytes(string $iniSize): int
    {
        $iniSize = trim($iniSize);
        $unit = strtoupper(substr($iniSize, -1));
        $value = (int) $iniSize;

        return match ($unit) {
            'G' => $value * 1024 ** 3,
            'M' => $value * 1024 ** 2,
            'K' => $value * 1024,
            default => $value,
        };
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
