<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\DataTransferObjects;

use App\Modules\Creators\Enums\PayoutStatus;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;

/**
 * Structured payment webhook event returned from
 * {@see PaymentProvider::parseWebhookEvent()}.
 *
 * Sprint 4 Chunk 2 introduces this DTO (the contract's docblock named
 * it as a future-extension type since Sprint 3). It is the payment
 * analogue of {@see KycWebhookEvent} — same idempotency-key shape,
 * same "nullable subject so the handler can record-with-error rather
 * than drop" convention.
 *
 *   - providerEventId:     Stripe `evt_*` id; the
 *                          (provider, providerEventId) pair is the
 *                          unique-index idempotency key on
 *                          integration_events (D-c2-4).
 *   - eventType:           Stripe event type string (e.g.
 *                          "account.updated"). Not constrained to an
 *                          enum — Stripe evolves its taxonomy
 *                          independently; the handler keys off
 *                          {@see self::$payoutStatus}.
 *   - accountId:           the Stripe connected-account id
 *                          (`data.object.id`, `acct_*`). The handler
 *                          looks the CreatorPayoutMethod row up by
 *                          this against `provider_account_id`.
 *                          Nullable for the "unknown subject" case.
 *   - payoutStatus:        Stripe account flags mapped onto the
 *                          internal {@see PayoutStatus} (D-c2-5):
 *                          charges_enabled + payouts_enabled and no
 *                          outstanding requirements → Verified;
 *                          requirements due → Restricted; otherwise
 *                          Pending. Null when the event communicates
 *                          no payout-readiness transition Sprint 4
 *                          cares about.
 *   - chargesEnabled /
 *     payoutsEnabled:      carried verbatim so the handler can emit
 *                          the same audit metadata the status-poll
 *                          path does (WizardCompletionService::pollPayout).
 *   - rawPayload:          the original parsed JSON, stored verbatim
 *                          on integration_events.payload so downstream
 *                          debugging / replay has the full vendor view.
 *
 * D-c2-5 separation: this event NEVER carries identity-KYC state. It
 * drives `creator_payout_methods.status` (payout-KYC), not
 * `creators.kyc_status`.
 *
 * @phpstan-type PaymentEventPayload array<string, mixed>
 */
final readonly class PaymentsWebhookEvent
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public ?string $accountId,
        public ?PayoutStatus $payoutStatus,
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public array $rawPayload,
    ) {}

    /**
     * Map Stripe connected-account flags onto the internal
     * {@see PayoutStatus} (D-c2-5). Co-located here so the rule is a
     * single source of truth shared by every adapter (mock + real)
     * and so any change to "what Stripe state means what payout
     * status" is a change to this method (and its tests):
     *
     *   - charges_enabled && payouts_enabled && no currently-due
     *     requirements        → Verified  (payout-ready)
     *   - any currently-due requirements → Restricted (Stripe needs more)
     *   - otherwise            → Pending  (still processing)
     *
     * Mirrors {@see AccountStatus::isFullyOnboarded()}'s completion
     * gate. The `disabled` status (provider-side payout suspension) is
     * NOT mapped here — it requires interpreting Stripe's
     * `disabled_reason`, deferred to Sprint 10's full webhook suite.
     *
     * @param  array<int, string>  $requirementsCurrentlyDue
     */
    public static function mapPayoutStatus(
        bool $chargesEnabled,
        bool $payoutsEnabled,
        array $requirementsCurrentlyDue,
    ): PayoutStatus {
        if ($chargesEnabled && $payoutsEnabled && $requirementsCurrentlyDue === []) {
            return PayoutStatus::Verified;
        }

        if ($requirementsCurrentlyDue !== []) {
            return PayoutStatus::Restricted;
        }

        return PayoutStatus::Pending;
    }
}
