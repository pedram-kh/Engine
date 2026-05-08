<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorChallengeService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see TwoFactorChallengeService::consumeRecoveryCode()}
 * inside the same DB transaction that removed the consumed hash from
 * the user's recovery code list.
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog} records `mfa.recovery_code_consumed`
 *     against the user. The audit row records the REMAINING code count,
 *     not the consumed value (chunk 5 priority #6).
 */
final readonly class TwoFactorRecoveryCodeConsumed
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public int $remainingCount,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
