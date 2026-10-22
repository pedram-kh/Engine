<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Claim;

/**
 * How an account may be claimed for erasure.
 *
 * `Normal` is the only mode a person's request can satisfy: the account must have asked,
 * and its grace must have ended. `ForceNotRequested` is the shell command's escape hatch
 * (`erasure:run --force-not-requested`, AH-104c Q9) for an account that never asked: it
 * needs an operator and its own audit reason, and it never passes a hold.
 */
enum ClaimMode: string
{
    /** `reclaim()` only: an erasure the ledger mirror already records, done again after a restore. */
    case Reapply = 'reapply';

    case Normal = 'normal';
    case ForceNotRequested = 'force_not_requested';
}
