<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when a user completes a password reset. The reset path
 * invalidates all existing sessions per docs/05-SECURITY-COMPLIANCE.md
 * §6.6, audits as `auth.password.reset_completed`, and clears any
 * temporary lockout / failed-login counters for the email.
 */
final readonly class PasswordResetCompleted
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
