<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/**
 * Outcome of {@see TwoFactorChallengeService::verify()}.
 *
 * `usedRecoveryCode` is set when the verifier succeeded by consuming a
 * recovery code rather than a TOTP — the login flow uses this to set
 * the `mfa: true` LoginSucceeded metadata accurately and to know that
 * the dedicated `mfa.recovery_code_consumed` audit row has already
 * been written.
 */
final readonly class TwoFactorChallengeResult
{
    private function __construct(
        public bool $passed,
        public bool $usedRecoveryCode,
    ) {}

    public static function totp(): self
    {
        return new self(passed: true, usedRecoveryCode: false);
    }

    public static function recoveryCode(): self
    {
        return new self(passed: true, usedRecoveryCode: true);
    }

    public static function failed(): self
    {
        return new self(passed: false, usedRecoveryCode: false);
    }
}
