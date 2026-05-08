<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted on every failed login attempt — both "user not found" and
 * "wrong password". The `$user` field is null when no user with the
 * submitted email exists. The `$email` is always set so listeners can
 * audit the attempt without leaking enumerable user existence.
 *
 * Triggers an `auth.login.failed` audit row via
 * {@see WriteAuthAuditLog}.
 */
final readonly class LoginFailed
{
    use Dispatchable;

    public function __construct(
        public string $email,
        public ?User $user,
        public ?string $ip,
        public ?string $userAgent,
        public string $reason,
    ) {}
}
