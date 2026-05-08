<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorEnrollmentService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see TwoFactorEnrollmentService::regenerateRecoveryCodes()}
 * after a fresh batch of recovery codes has been generated, hashed, and
 * stored. The plaintext codes are returned to the user once at this
 * moment and never retrievable again.
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog} records `mfa.recovery_codes_regenerated`
 *     against the user. The audit row records the COUNT of codes, not
 *     their values (chunk 5 priority #6).
 */
final readonly class TwoFactorRecoveryCodesRegenerated
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public int $codeCount,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
