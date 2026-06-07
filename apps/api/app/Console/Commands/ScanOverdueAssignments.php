<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Boards\Services\OverdueScanService;
use Illuminate\Console\Command;

/**
 * The daily overdue sweep (Sprint 12 Chunk 3, D-5) — the app's SECOND scheduled
 * command (registered ->daily() via withSchedule in bootstrap/app.php, the
 * SendMessageDigests precedent).
 *
 * It hands off to {@see OverdueScanService}, which fires the two time-triggered
 * board events (assignment.posting_overdue / assignment.draft_overdue) for every
 * assignment whose deadline has passed and that has not yet been flagged — a
 * cross-agency sweep with per-card tenant self-resolution (D-6). Daily is the
 * right grain: "deadline passed" is a day-grain fact; nothing in the spec wants
 * finer.
 */
final class ScanOverdueAssignments extends Command
{
    protected $signature = 'boards:scan-overdue';

    protected $description = 'Fire the time-triggered overdue board events for assignments past their posting/draft deadline.';

    public function handle(OverdueScanService $scanner): int
    {
        $counts = $scanner->scan();

        $this->info(sprintf(
            'Fired %d posting-overdue + %d draft-overdue event(s).',
            $counts['posting'],
            $counts['draft'],
        ));

        return self::SUCCESS;
    }
}
