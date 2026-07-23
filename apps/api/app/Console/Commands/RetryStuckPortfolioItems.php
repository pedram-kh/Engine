<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Creators\Enums\PortfolioItemKind;
use App\Modules\Creators\Enums\PortfolioProcessingStatus;
use App\Modules\Creators\Jobs\ProcessPortfolioImageJob;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Re-dispatch {@see ProcessPortfolioImageJob} for portfolio images stranded
 * at `processing` (July 2026 incident: an uncatchable worker kill — OOM /
 * timeout — died before the job could mark the item `failed`, and the queue
 * entry crashed out the same way on `queue:retry`).
 *
 * The job is idempotent on re-run (it no-ops unless the item is still
 * `processing`), so re-dispatching is always safe. `--include-failed` also
 * rescues items already marked `failed` by flipping them back to
 * `processing` first — use it after the underlying cause (memory / timeout)
 * has been fixed, otherwise they will simply fail again.
 *
 * IDEMPOTENT + `--dry-run` first, per the deploy checklist
 * (docs/runbooks/production-queue-worker.md §8).
 */
final class RetryStuckPortfolioItems extends Command
{
    protected $signature = 'portfolio:retry-stuck
        {--dry-run : List the items that would be re-dispatched without dispatching}
        {--include-failed : Also reset `failed` items to `processing` and re-dispatch}
        {--min-age=10 : Only touch `processing` items untouched for at least this many minutes (avoids racing in-flight jobs)}';

    protected $description = 'Re-dispatch the image-processing job for portfolio items stuck at `processing` (optionally `failed`).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeFailed = (bool) $this->option('include-failed');
        $minAgeMinutes = (int) $this->option('min-age');

        if ($minAgeMinutes < 0) {
            $this->error('--min-age must be zero or a positive number of minutes.');

            return self::FAILURE;
        }

        $items = CreatorPortfolioItem::query()
            ->where('kind', PortfolioItemKind::Image->value)
            ->whereNotNull('s3_path')
            ->where(function (Builder $query) use ($includeFailed, $minAgeMinutes): void {
                $query->where(function (Builder $stuck) use ($minAgeMinutes): void {
                    $stuck->where('processing_status', PortfolioProcessingStatus::Processing->value)
                        ->where('updated_at', '<=', now()->subMinutes($minAgeMinutes));
                });

                if ($includeFailed) {
                    $query->orWhere('processing_status', PortfolioProcessingStatus::Failed->value);
                }
            })
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            $this->info('No stuck portfolio items found.');

            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $this->line(sprintf(
                '%s item #%d (creator %d, status %s, %s, updated %s)',
                $dryRun ? '[dry-run] would re-dispatch' : 'Re-dispatching',
                $item->id,
                $item->creator_id,
                $item->processing_status->value,
                $item->mime_type ?? 'unknown mime',
                (string) $item->updated_at,
            ));

            if ($dryRun) {
                continue;
            }

            if ($item->processing_status === PortfolioProcessingStatus::Failed) {
                $item->forceFill([
                    'processing_status' => PortfolioProcessingStatus::Processing->value,
                ])->save();
            }

            ProcessPortfolioImageJob::dispatch($item->id);
        }

        $this->info(sprintf(
            '%d item(s) %s.',
            $items->count(),
            $dryRun ? 'would be re-dispatched' : 're-dispatched',
        ));

        return self::SUCCESS;
    }
}
