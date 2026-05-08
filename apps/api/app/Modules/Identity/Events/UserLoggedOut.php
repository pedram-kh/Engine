<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuthService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see AuthService} after a
 * session is invalidated and the user logged out.
 *
 * Triggers an `auth.logout` audit row via
 * {@see WriteAuthAuditLog}.
 */
final readonly class UserLoggedOut
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
        public string $guard,
    ) {}
}
