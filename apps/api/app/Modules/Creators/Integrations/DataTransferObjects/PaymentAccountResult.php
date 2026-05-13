<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\DataTransferObjects;

use App\Modules\Creators\Integrations\Contracts\PaymentProvider;

/**
 * Result of {@see PaymentProvider::createConnectedAccount()}.
 *
 *   - accountId:        provider-side connected-account identifier (Stripe acct_*).
 *   - onboardingUrl:    hosted onboarding flow URL for the creator's browser.
 *   - expiresAt:        ISO 8601 timestamp when the onboarding URL stops being valid.
 *
 * Stored on `creator_payout_methods.account_id` and used by the wizard's
 * payout step to redirect the creator's browser.
 */
final readonly class PaymentAccountResult
{
    public function __construct(
        public string $accountId,
        public string $onboardingUrl,
        public string $expiresAt,
    ) {}
}
