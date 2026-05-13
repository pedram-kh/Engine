<?php

declare(strict_types=1);

namespace App\Modules\TrackedJobs\Enums;

/**
 * Lifecycle of a tracked async job.
 *
 *   queued → processing → complete | failed
 *
 * Per docs/04-API-DESIGN.md § 18 — these are the only four states the
 * GET /api/v1/jobs/{job} endpoint may report.
 */
enum TrackedJobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Complete = 'complete';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Complete, self::Failed => true,
            self::Queued, self::Processing => false,
        };
    }
}
