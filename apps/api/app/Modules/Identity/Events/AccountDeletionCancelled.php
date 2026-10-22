<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;

/**
 * A creator cancelled their deletion request by signing in again, and the cancellation
 * is committed (AH-104c C1-5). Dispatched AFTER the transaction commits.
 *
 * The one thing that listens today: the Campaigns module re-runs the automatic post checks
 * that were held back while the account was pending, so a verification is delayed, never lost.
 */
final readonly class AccountDeletionCancelled
{
    public function __construct(public User $user) {}
}
