<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Run;

use App\Modules\Erasure\Files\FileKeys;
use App\Modules\Erasure\Selection\DryRunReport;
use App\Modules\Erasure\Subject\SubjectSet;

/**
 * Everything an erasure WOULD do, as numbers: the database lines, the object
 * counts, the vendor calls (none: any real vendor is a refusal) and the reasons
 * it would be refused. Safe to print and to paste into a ticket.
 *
 * The digest covers all of it. Execute recomputes the plan from the live data
 * and compares, so an approval given for one state of the data cannot be used
 * against another.
 */
final readonly class ErasurePlan
{
    /**
     * @param  list<string>  $refusals  from payment and vendor guards
     */
    public function __construct(
        public SubjectSet $subject,
        public DryRunReport $report,
        public FileKeys $files,
        public array $refusals,
        public bool $auditScrubAvailable,
    ) {}

    public function digest(): string
    {
        return hash('sha256', json_encode([
            'report' => $this->report->digest(),
            'files' => $this->files->digest(),
            'vendor_calls' => [],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> every reason an execute would be refused */
    public function blockers(): array
    {
        $blockers = $this->refusals;
        if (! $this->auditScrubAvailable) {
            $blockers[] = 'the audit scrub (stage 4e) is not available here, so the audit trail would keep the old email';
        }

        return $blockers;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_ulid' => $this->subject->userUlid,
            'database' => $this->report->lines,
            'files' => $this->files->summary(),
            'vendor_calls' => 0,
            'refusals' => $this->refusals,
            'audit_scrub_available' => $this->auditScrubAvailable,
            'digest' => $this->digest(),
        ];
    }
}
