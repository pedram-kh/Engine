<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use App\Modules\Erasure\Subject\SubjectSet;

/**
 * Stage 4e's slot in the run: the audit scrub (predicates S1 to S5).
 *
 * It runs FIRST, before any object is deleted and before the database stage.
 * Both later steps destroy what this one needs (the subject's identity), and an
 * erased user cannot be resolved again, so a scrub left for after the commit
 * could never be finished if the process died in between. Run first, a failure
 * leaves the account intact and the whole run can simply be started again.
 *
 * The scrub updates `audit_logs` through its own connection and role (D-B), so
 * the real implementation waits on read-back C (the grants). Until it exists,
 * {@see UnavailableAuditScrub} is bound and the command refuses to execute.
 */
interface AuditScrub
{
    /** False when the scrub cannot run here (no scrub connection): an execute is refused. */
    public function available(): bool;

    /**
     * Idempotent: a second call changes nothing.
     *
     * @return array<string, int> rows scrubbed per predicate
     *
     * @throws AuditScrubUnavailable
     */
    public function run(SubjectSet $subject): array;
}
