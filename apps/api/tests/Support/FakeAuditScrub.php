<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Erasure\Execution\AuditScrub;
use App\Modules\Erasure\Execution\AuditScrubUnavailable;
use App\Modules\Erasure\Subject\SubjectSet;

/** Stands in for stage 4e, which waits on read-back C. Records that and when it ran. */
final class FakeAuditScrub implements AuditScrub
{
    public int $runs = 0;

    public bool $fail = false;

    public bool $available = true;

    public function __construct(private readonly ?ErasureEvents $events = null) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function run(SubjectSet $subject): array
    {
        if ($this->fail) {
            throw AuditScrubUnavailable::notAvailable();
        }
        $this->runs++;
        if ($this->events !== null) {
            $this->events->add('scrub');
        }

        // Idempotent like the real one: the closing pass finds nothing left.
        return $this->runs === 1 ? ['audit_logs.S1' => 2, 'audit_logs.S2' => 1] : [];
    }
}
