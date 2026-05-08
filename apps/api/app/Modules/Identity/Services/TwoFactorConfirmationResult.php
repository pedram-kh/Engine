<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/**
 * Outcome of {@see TwoFactorEnrollmentService::confirm()}.
 *
 * The variant tag drives the controller's HTTP envelope; the recovery
 * codes are populated only on the {@see TwoFactorConfirmationStatus::Confirmed}
 * branch (they are the one-time output the user must save).
 */
final readonly class TwoFactorConfirmationResult
{
    /**
     * @param  list<string>  $recoveryCodes  plaintext, only on Confirmed
     */
    private function __construct(
        public TwoFactorConfirmationStatus $status,
        public array $recoveryCodes = [],
    ) {}

    /**
     * @param  list<string>  $recoveryCodes
     */
    public static function confirmed(array $recoveryCodes): self
    {
        return new self(TwoFactorConfirmationStatus::Confirmed, $recoveryCodes);
    }

    public static function invalidCode(): self
    {
        return new self(TwoFactorConfirmationStatus::InvalidCode);
    }

    public static function provisionalNotFound(): self
    {
        return new self(TwoFactorConfirmationStatus::ProvisionalNotFound);
    }

    public static function alreadyConfirmed(): self
    {
        return new self(TwoFactorConfirmationStatus::AlreadyConfirmed);
    }
}
