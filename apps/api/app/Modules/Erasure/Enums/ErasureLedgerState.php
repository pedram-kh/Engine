<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Enums;

/**
 * Where an erasure run stands. `started → db_done → completed`; `incomplete` is
 * a run that stopped with a step refused or failed (for example the audit scrub
 * with the audit scrub unavailable) and must be resumed, never read as done.
 */
enum ErasureLedgerState: string
{
    case Started = 'started';
    case DbDone = 'db_done';
    case Completed = 'completed';
    case Incomplete = 'incomplete';
}
