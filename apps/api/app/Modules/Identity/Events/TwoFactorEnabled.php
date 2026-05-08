<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorEnrollmentService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see TwoFactorEnrollmentService::start()} when a user
 * begins the two-step 2FA enrollment flow. The user's row has NOT yet
 * been mutated; the provisional secret lives only in cache.
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog} records `mfa.enabled` (intent to enroll).
 *
 * The follow-up `mfa.confirmed` row is what actually proves a working
 * authenticator app — see {@see TwoFactorConfirmed}.
 */
final readonly class TwoFactorEnabled
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
