<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Creators\Services\IncompleteCreatorNudgeService;
use Illuminate\Console\Command;

/**
 * Send the one-time incomplete-creator email nudge (D6) — a thin handler that
 * delegates to {@see IncompleteCreatorNudgeService} (the SendMessageDigests
 * shape). Registered ->daily() via withSchedule in bootstrap/app.php.
 *
 * The Pennant flag (incomplete_creator_nudge_enabled) is checked INSIDE the
 * service, not here: flag OFF → an explicit no-op with a "disabled" line and
 * exit 0. `--dry-run` ignores the flag and mutates nothing — it prints the
 * per-variant would-send counts an operator reads before flipping the flag
 * (docs/runbooks/production-queue-worker.md §7).
 *
 * `--limit=N` caps the run at N nudges (oldest-first), defaulting to the
 * service's conservative DEFAULT_LIMIT — a production-safety bound so the first
 * enable drains the backlog deterministically rather than blasting everyone.
 */
final class SendIncompleteCreatorNudges extends Command
{
    protected $signature = 'creators:send-incomplete-nudges
        {--dry-run : Count eligible creators per variant without sending or stamping}
        {--limit= : Max nudges to send this run, oldest-first (default '.IncompleteCreatorNudgeService::DEFAULT_LIMIT.')}';

    protected $description = 'Email self-serve creators sitting incomplete for 48+ hours a one-time onboarding nudge.';

    public function handle(IncompleteCreatorNudgeService $service): int
    {
        $limit = $this->resolveLimit();
        if ($limit === null) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $report = $service->preview($limit);
            $this->info(sprintf(
                '[dry-run] would send %d nudge(s): verify=%d, finish=%d (cap %d). No changes made.',
                $report->total(),
                $report->verify,
                $report->finish,
                $limit,
            ));

            return self::SUCCESS;
        }

        $report = $service->send($limit);

        if ($report->disabled) {
            $this->info('incomplete_creator_nudge_enabled is OFF — no nudges sent.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Sent %d nudge(s): verify=%d, finish=%d (cap %d).',
            $report->total(),
            $report->verify,
            $report->finish,
            $limit,
        ));

        return self::SUCCESS;
    }

    /**
     * Resolve `--limit` to a positive int, or the service default when absent.
     * Returns null on an invalid value (non-numeric, ≤ 0) so the caller can fail
     * loudly rather than silently sending an unbounded or zero-capped run.
     */
    private function resolveLimit(): ?int
    {
        $raw = $this->option('limit');

        if ($raw === null) {
            return IncompleteCreatorNudgeService::DEFAULT_LIMIT;
        }

        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw < 1) {
            return null;
        }

        return (int) $raw;
    }
}
