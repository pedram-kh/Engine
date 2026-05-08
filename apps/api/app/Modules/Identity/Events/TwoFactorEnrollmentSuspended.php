<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorVerificationThrottle;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by {@see TwoFactorVerificationThrottle} when the 10-invalid-
 * attempts-in-15-minutes threshold is crossed. The user's
 * `two_factor_enrollment_suspended_at` timestamp has been set in the
 * same call so the verifier fails closed on the next attempt.
 *
 * Listeners:
 *   - {@see WriteAuthAuditLog} records `mfa.enrollment_suspended`
 *     against the user.
 */
final readonly class TwoFactorEnrollmentSuspended
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
