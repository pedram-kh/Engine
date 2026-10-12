<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Notifications\Models\PushDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Retention for the push delivery attempt log (AH-102b plan §3.4):
 * `php artisan push:prune-deliveries`. Registered `->daily()` in
 * `bootstrap/app.php` AND safe to run by hand.
 *
 *  - Deletes `push_deliveries` rows with `created_at` STRICTLY before the cutoff
 *    (`--days`, default 90; a row exactly at the cutoff survives).
 *  - Oldest-first by id, in chunks of at most {@see self::CHUNK} rows per DELETE
 *    statement, until nothing older remains or `--limit` rows (default 100000)
 *    have gone in this run — a backlog drains over successive daily runs.
 *  - Touches NO other table.
 *  - Loud on bad input: `--days` must be an integer 7–3650 (the floor stops a
 *    fat-fingered `--days=0` from emptying the table), `--limit` a positive
 *    integer. Anything else exits non-zero and deletes nothing.
 *  - `--dry-run` reports what would go and deletes nothing.
 *  - Idempotent: a second run immediately after deletes 0.
 *
 * Always prints the rows deleted (or would-be) and the remaining total + oldest
 * row — the same two numbers the admin Push deliveries footer shows, so a dead
 * scheduler is visible from either place.
 */
final class PrunePushDeliveries extends Command
{
    public const int CHUNK = 5000;

    public const int MIN_DAYS = 7;

    public const int MAX_DAYS = 3650;

    protected $signature = 'push:prune-deliveries
        {--days=90 : Delete delivery rows older than this many days (7-3650)}
        {--dry-run : Report what would be deleted; delete nothing}
        {--limit=100000 : Delete at most this many rows in this run}';

    protected $description = 'Delete push delivery attempt rows older than the retention window (default 90 days).';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($days === false || $days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            $this->error(sprintf('--days must be an integer between %d and %d; nothing was deleted.', self::MIN_DAYS, self::MAX_DAYS));

            return self::INVALID;
        }

        if ($limit === false || $limit < 1) {
            $this->error('--limit must be a positive integer; nothing was deleted.');

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $eligible = PushDelivery::query()->where('created_at', '<', $cutoff)->count();
            $count = min($eligible, $limit);

            $this->info(sprintf('Dry run: would delete %d row(s) older than %s (limit %d).', $count, $cutoff->toIso8601String(), $limit));
        } else {
            $count = $this->prune($cutoff, $limit);

            $this->info(sprintf('Deleted %d row(s) older than %s.', $count, $cutoff->toIso8601String()));
        }

        $oldest = PushDelivery::query()->min('created_at');
        $this->line(sprintf(
            'Remaining: %d row(s); oldest: %s.',
            PushDelivery::query()->count(),
            $oldest === null ? 'none' : Carbon::parse((string) $oldest)->toIso8601String(),
        ));

        return self::SUCCESS;
    }

    private function prune(Carbon $cutoff, int $limit): int
    {
        $deleted = 0;

        while ($deleted < $limit) {
            $ids = PushDelivery::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(min(self::CHUNK, $limit - $deleted))
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += PushDelivery::query()->whereIn('id', $ids->all())->delete();
        }

        return $deleted;
    }
}
