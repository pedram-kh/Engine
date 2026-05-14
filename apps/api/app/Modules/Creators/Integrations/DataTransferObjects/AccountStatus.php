<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\DataTransferObjects;

use App\Modules\Creators\Integrations\Contracts\PaymentProvider;

/**
 * Result of {@see PaymentProvider::getAccountStatus()}.
 *
 * Mirrors the canonical Stripe Connect account fields (per
 * docs/06-INTEGRATIONS.md § 2.2) the wizard's payout step needs to
 * decide whether onboarding is "complete enough" to flip
 * creators.payout_method_set true:
 *
 *   - chargesEnabled:           account can charge / receive funds.
 *   - payoutsEnabled:           account can pay out to its bank.
 *   - detailsSubmitted:         creator finished the vendor flow.
 *   - requirementsCurrentlyDue: vendor-side outstanding requirements,
 *     e.g. ["external_account", "tos_acceptance.date"]. Empty array
 *     means "fully onboarded — no further inputs required".
 *
 * The wizard treats `chargesEnabled && payoutsEnabled && detailsSubmitted
 * && requirementsCurrentlyDue === []` as the completion gate. Any
 * other state leaves the wizard at the payout step and surfaces
 * `requirementsCurrentlyDue` to the creator (Chunk 3 frontend).
 *
 * Sprint 3 Chunk 2 introduces this DTO alongside the PaymentProvider
 * contract extension. Sprint 10's real Stripe Connect adapter
 * populates the fields directly from the Stripe `Account` object
 * (1:1 field correspondence by design).
 *
 * @param  array<int, string>  $requirementsCurrentlyDue
 */
final readonly class AccountStatus
{
    /**
     * @param  array<int, string>  $requirementsCurrentlyDue
     */
    public function __construct(
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public bool $detailsSubmitted,
        public array $requirementsCurrentlyDue,
    ) {}

    /**
     * Convenience predicate the wizard's status-poll endpoint uses to
     * decide whether the payout step transitions to "complete". Kept
     * here so the rule is co-located with the field definitions —
     * any change to "what counts as fully onboarded" is a change to
     * this method (and the corresponding tests).
     */
    public function isFullyOnboarded(): bool
    {
        return $this->chargesEnabled
            && $this->payoutsEnabled
            && $this->detailsSubmitted
            && $this->requirementsCurrentlyDue === [];
    }
}
