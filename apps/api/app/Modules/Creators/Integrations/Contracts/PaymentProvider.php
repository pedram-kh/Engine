<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Contracts;

use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\Stubs\DeferredPaymentProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Payment-provider contract (Stripe-Connect-style).
 *
 * Sprint 3 Chunk 1 deliberately defines the **subset** of the full
 * {@see https://image.intervention.io 06-INTEGRATIONS.md § 2.2} surface
 * needed by the Creator wizard's payout-method step.
 *
 * Lives under `Modules/Creators/Integrations/` for Sprint 3 (not under
 * `Modules/Payments/`) because the wizard owns the connected-account
 * onboarding lifecycle. When Sprint 10 builds out
 * escrow / release / refund flows, the Payments module will define a
 * broader `PaymentProviderContract` (per 06-INTEGRATIONS.md § 2.2);
 * the wizard will continue depending on this narrower contract.
 *
 * ## Sprint 3 subset (this contract)
 *   - {@see self::createConnectedAccount()}: creates the connected
 *     account + returns the onboarding URL.
 *
 * ## Future-extension methods (Sprint 10 in Modules/Payments/)
 *   - `getOnboardingLink(Creator $creator): string`
 *   - `fundEscrow(Payment $payment, FundingRequest $r): EscrowResult`
 *   - `releaseEscrow(Payment $payment, ReleaseRequest $r): ReleaseResult`
 *   - `refundEscrow(Payment $payment, RefundRequest $r): RefundResult`
 *   - `getAccountStatus(Creator $creator): AccountStatus`
 *   - `verifyWebhookSignature(string $payload, string $signature): bool`
 *   - `parseWebhookEvent(string $payload): PaymentsWebhookEvent`
 *
 * @see DeferredPaymentProvider
 */
interface PaymentProvider
{
    /**
     * Create a connected-account record for the creator and return the
     * hosted onboarding URL the creator's browser is redirected to.
     */
    public function createConnectedAccount(Creator $creator): PaymentAccountResult;
}
