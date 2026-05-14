<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Contracts;

use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\Stubs\DeferredPaymentProvider;
use App\Modules\Creators\Models\Creator;

/**
 * Payment-provider contract (Stripe-Connect-style).
 *
 * Sprint 3 Chunk 2 completes the wizard's payout-method integration
 * surface (hybrid completion architecture, Decision A = (c) in the
 * chunk-2 plan — but status-poll only for Stripe Connect; webhook
 * handling deferred to Sprint 10 per Q-stripe-no-webhook-acceptable).
 * Two methods, no inbound-webhook surface.
 *
 * Lives under `Modules/Creators/Integrations/` for Sprint 3 (not
 * under `Modules/Payments/`) because the wizard owns the connected-
 * account onboarding lifecycle. When Sprint 10 builds out
 * escrow / release / refund flows, the Payments module will define a
 * broader `PaymentProviderContract` (per 06-INTEGRATIONS.md § 2.2);
 * the wizard will continue depending on this narrower contract.
 *
 * ## Sprint 3 completion surface (this contract — 2 methods)
 *   - {@see self::createConnectedAccount()}: creates account; returns onboarding URL.
 *   - {@see self::getAccountStatus()}: status-poll for post-redirect UX.
 *
 * ## Future-extension methods (Sprint 10 in Modules/Payments/)
 *   - `verifyWebhookSignature(string $payload, string $signature): bool`
 *   - `parseWebhookEvent(string $payload): PaymentsWebhookEvent`
 *   - `fundEscrow(Payment $payment, FundingRequest $r): EscrowResult`
 *   - `releaseEscrow(Payment $payment, ReleaseRequest $r): ReleaseResult`
 *   - `refundEscrow(Payment $payment, RefundRequest $r): RefundResult`
 *
 * Sprint 3's status-poll-only choice is documented as accepted risk
 * in docs/tech-debt.md (Sprint 7 / Sprint 10 follow-up): the
 * `account.updated` webhook is the production-grade source of truth
 * for Connect account state, but Sprint 3's wizard only needs
 * onboarding-completion confirmation, which status-poll satisfies.
 *
 * @see DeferredPaymentProvider
 * @see AccountStatus
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
}
