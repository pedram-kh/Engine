<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted whenever the forgot-password endpoint is hit for a known email.
 * Triggers `auth.password.reset_requested` audit. Unknown emails do NOT
 * emit this event — that path returns 204 silently to avoid user
 * enumeration.
 */
final readonly class PasswordResetRequested
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
