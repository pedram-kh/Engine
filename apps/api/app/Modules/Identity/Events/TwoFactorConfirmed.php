<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorEnrollmentService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see TwoFactorEnrollmentService::confirm()} after the
 * user's first valid TOTP code is verified. At this point the secret
 * has been persisted to `users.two_factor_secret`,
 * `users.two_factor_confirmed_at` is set, and the recovery codes have
 * been hashed + stored.
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog} records `mfa.confirmed` against the user.
 */
final readonly class TwoFactorConfirmed
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
