<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Carbon\CarbonInterface;

/**
 * A creator asked for their account to be deleted and the request is
 * committed. Dispatched AFTER the transaction commits, so a listener never
 * acts on a request that was rolled back. The confirmation mail (A6) listens
 * here; nothing else may assume this event means the account is erased.
 */
final readonly class AccountDeletionRequested
{
    public function __construct(
        public User $user,
        public CarbonInterface $scheduledFor,
    ) {}
}
