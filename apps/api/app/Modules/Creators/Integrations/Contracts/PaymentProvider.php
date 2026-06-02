<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Contracts;

use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentsWebhookEvent;
use App\Modules\Creators\Integrations\Stripe\StripePaymentProvider;
use App\Modules\Creators\Integrations\Stubs\DeferredPaymentProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Payment-provider contract (Stripe-Connect-style).
 *
 * Sprint 3 Chunk 2 completed the wizard's payout-method onboarding
 * surface (createConnectedAccount + getAccountStatus, status-poll
 * completion). Sprint 4 Chunk 2 adds the inbound-webhook pair
 * (verifyWebhookSignature + parseWebhookEvent) so the real Stripe
 * adapter's `account.updated` event can drive
 * `creator_payout_methods.status` authoritatively — the first real
 * adapter behind this seam.
 *
 * Lives under `Modules/Creators/Integrations/` because the wizard owns
 * the connected-account onboarding lifecycle (D-c2-1). The real
 * adapter ({@see StripePaymentProvider})
 * implements THIS contract — it deliberately does NOT stand up the
 * spec's broad escrow-bearing `Modules\Payments\Contracts\PaymentProviderContract`
 * (06-INTEGRATIONS.md § 2.2), which is deferred to Sprint 10. When
 * Sprint 10 builds escrow / release / refund, the Payments module
 * defines the broader contract and the adapter migrates with it
 * (see docs/tech-debt.md).
 *
 * ## Onboarding surface (Sprint 3 — 2 methods)
 *   - {@see self::createConnectedAccount()}: creates account; returns onboarding URL.
 *   - {@see self::getAccountStatus()}: status-poll for post-redirect UX.
 *
 * ## Inbound-webhook surface (Sprint 4 Chunk 2 — 2 methods)
 *   - {@see self::verifyWebhookSignature()}: inbound-webhook signature check.
 *   - {@see self::parseWebhookEvent()}: parse vendor payload to internal DTO.
 *
 * ## Future-extension methods (Sprint 10 in Modules/Payments/)
 *   - `fundEscrow(Payment $payment, FundingRequest $r): EscrowResult`
 *   - `releaseEscrow(Payment $payment, ReleaseRequest $r): ReleaseResult`
 *   - `refundEscrow(Payment $payment, RefundRequest $r): RefundResult`
 *   - the remaining 8 money-movement webhooks (charge.*, transfer.*, payout.*).
 *
 * @see DeferredPaymentProvider
 * @see AccountStatus
 * @see PaymentsWebhookEvent
 */
interface PaymentProvider
{
    /**
     * Create a connected-account record for the creator and return the
     * hosted onboarding URL the creator's browser is redirected to.
     */
    public function createConnectedAccount(Creator $creator): PaymentAccountResult;

    /**
     * Poll the provider for the current account status of the
     * creator's connected account.
     *
     * Used by the wizard's status-poll endpoint
     * (`GET /api/v1/creators/me/wizard/payout/status`) when the
     * creator returns from Stripe's hosted Express onboarding flow.
     * The wizard checks {@see AccountStatus::isFullyOnboarded()} to
     * decide whether to flip `creators.payout_method_set` true and
     * advance the wizard.
     */
    public function getAccountStatus(Creator $creator): AccountStatus;

    /**
     * Verify the signature on an inbound webhook payload.
     *
     * Returns true iff the signature is valid for the given payload.
     * The webhook handler returns 401 Unauthorized on false. Single
     * boolean return on purpose — the security envelope MUST NOT
     * differentiate between failure modes (mirrors the binding
     * decision in {@see KycProvider::verifyWebhookSignature()}).
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Parse an inbound webhook payload into the internal
     * {@see PaymentsWebhookEvent} DTO. Called only after
     * {@see self::verifyWebhookSignature()} returns true.
     *
     * Throws on malformed payloads (the handler converts to
     * `processing_error` on the integration_events row).
     */
    public function parseWebhookEvent(string $payload): PaymentsWebhookEvent;
}
