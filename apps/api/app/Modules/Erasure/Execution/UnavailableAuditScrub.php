<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use App\Modules\Erasure\Subject\SubjectSet;

/**
 * The binding until stage 4e is built (it waits on read-back C, the grants for
 * the scrub role). It makes the command refuse to execute: erasing the database
 * while the audit trail keeps the old email would be an incomplete erasure of an
 * account that can then no longer be resolved, so the run fails closed instead.
 * The dry-run still prints what the scrub WOULD change (the S1 to S5 counts).
 */
final class UnavailableAuditScrub implements AuditScrub
{
    public function available(): bool
    {
        return false;
    }

    public function run(SubjectSet $subject): array
    {
        throw AuditScrubUnavailable::noScrubConnection();
    }
}
