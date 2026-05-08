<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccountLockoutService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see AccountLockoutService}
 * when the long-window failure threshold is crossed and the account is
 * hard-suspended. Audit logging is performed inside the service itself
 * (so the row is written transactionally with the suspension); this event
 * is fan-out for any future side effects (admin notifications, security
 * channel ping, etc.) that should not block the user-facing 401 response.
 */
final readonly class AccountLocked
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public string $reason,
    ) {}
}
