<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorEnrollmentService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see TwoFactorEnrollmentService::disable()} once the
 * user's two_factor_secret, two_factor_recovery_codes, and
 * two_factor_confirmed_at columns have been wiped in the same
 * transaction.
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog} records `mfa.disabled` against the user.
 */
final readonly class TwoFactorDisabled
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
