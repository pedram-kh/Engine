<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Support;

use App\Modules\Campaigns\Services\JobPostedFanOutService;

/**
 * The outcome of one job-posted fan-out run (AH-056, D6) — what the queued job
 * logs and what the operator command prints, in one shape so the dry-run
 * preview and the real send cannot report differently.
 *
 * `remaining` is the reason a capped run is operable at all: it is the count
 * still un-notified AFTER this run, so the operator knows whether to re-run and
 * how many times. Without it a cap is indistinguishable from a completed
 * fan-out.
 *
 * @see JobPostedFanOutService
 */
final readonly class JobPostedFanOutReport
{
    public function __construct(
        /** Recipients notified by this run (0 for a dry-run or a flag-OFF no-op). */
        public int $notified,
        /** Eligible recipients still un-notified after this run. */
        public int $remaining,
        /** False when the Pennant flag was OFF and the run was a deliberate no-op. */
        public bool $enabled = true,
    ) {}

    /**
     * The flag-OFF no-op: nothing queued, nothing stamped, and `remaining`
     * reported honestly so an operator reading the log knows exactly what a
     * flip would send.
     */
    public static function disabled(int $remaining): self
    {
        return new self(notified: 0, remaining: $remaining, enabled: false);
    }

    /** True when a further run would notify somebody (the drain signal). */
    public function hasRemainder(): bool
    {
        return $this->remaining > 0;
    }
}
