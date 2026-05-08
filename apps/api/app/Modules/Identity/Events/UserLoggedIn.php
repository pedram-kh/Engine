<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\StampUserLastLogin;
use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuthService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see AuthService} after a
 * successful credential check (and 2FA gate clearance, when applicable).
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog}
 *     records `auth.login.succeeded` against the user.
 *   - {@see StampUserLastLogin}
 *     persists `last_login_at` and `last_login_ip` on the user row.
 */
final readonly class UserLoggedIn
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
        public string $guard,
    ) {}
}
