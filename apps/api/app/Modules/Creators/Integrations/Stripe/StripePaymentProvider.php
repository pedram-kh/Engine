<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Stripe;

use App\Modules\Creators\CreatorsServiceProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentsWebhookEvent;
use App\Modules\Creators\Integrations\Stubs\SkippedPaymentProvider;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\WebhookSignature;

/**
 * Real Stripe Connect (Express) payment provider — test-mode adapter.
 *
 * Sprint 4 Chunk 2. The first concrete implementation behind the
 * Sprint-3 {@see PaymentProvider} seam. Implements the SAME 2-method
 * onboarding contract the mock targets, plus the Chunk-2 webhook pair
 * (verifyWebhookSignature + parseWebhookEvent) for the `account.updated`
 * event.
 *
 * ## Deliberate divergence from 06-INTEGRATIONS.md § 2 (D-c2-2)
 * The spec places the adapter at
 * `app/Modules/Payments/Integrations/Stripe/StripePaymentProvider.php`
 * (line 122), presuming the spec's broad escrow-bearing
 * `Modules\Payments\Contracts\PaymentProviderContract`. We deliberately
 * stay on the narrower `Creators` contract (D-c2-1) and so the adapter
 * lives alongside the mock under `Modules/Creators/Integrations/Stripe/`.
 * When Sprint 10 builds `Modules\Payments` (escrow + the 8 money-movement
 * webhooks), this adapter migrates with the contract. Recorded in
 * docs/tech-debt.md.
 *
 * ## Reachability (D-c2-9)
 * Bound by {@see CreatorsServiceProvider} only when
 * `creator_payout_method_enabled` is ON AND `PAYMENT_PROVIDER=stripe`.
 * The flag is OFF in every environment this chunk, so this adapter is
 * bound-but-unreachable in production — exercised via test/staging
 * against Stripe test-mode. Flag OFF still routes to
 * {@see SkippedPaymentProvider}
 * (the "no vendor call when flag off" guarantee, § 52).
 *
 * ## Testability
 * The {@see StripeClient} is constructor-injected so feature tests swap
 * a fake client (no live API calls in CI). Webhook signature
 * verification uses Stripe's deterministic HMAC scheme, so tests
 * compute a real `Stripe-Signature` header offline.
 */
final class StripePaymentProvider implements PaymentProvider
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly string $webhookSecret,
        private readonly string $returnUrl,
        private readonly string $refreshUrl,
        private readonly int $webhookTolerance = 300,
    ) {}

    public function createConnectedAccount(Creator $creator): PaymentAccountResult
    {
        // Express connected account — Stripe hosts the onboarding +
        // collects KYC/payout details. `metadata.creator_ulid` lets
        // inbound webhooks correlate back even if the local row is
        // gone; the durable correlation is provider_account_id on
        // creator_payout_methods (set by CreatorWizardService).
        $account = $this->client->accounts->create([
            'type' => 'express',
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'metadata' => [
                'creator_ulid' => $creator->ulid,
            ],
        ]);

        $accountLink = $this->client->accountLinks->create([
            'account' => $account->id,
            'refresh_url' => $this->refreshUrl,
            'return_url' => $this->returnUrl,
            'type' => 'account_onboarding',
        ]);

        return new PaymentAccountResult(
            accountId: $account->id,
            onboardingUrl: $accountLink->url,
            expiresAt: Carbon::createFromTimestampUTC($accountLink->expires_at)->toIso8601String(),
        );
    }

    public function getAccountStatus(Creator $creator): AccountStatus
    {
        $accountId = $this->resolveAccountId($creator);

        $account = $this->client->accounts->retrieve($accountId);

        $requirementsCurrentlyDue = [];

        if (isset($account->requirements->currently_due) && is_iterable($account->requirements->currently_due)) {
            foreach ($account->requirements->currently_due as $requirement) {
                if (is_string($requirement)) {
                    $requirementsCurrentlyDue[] = $requirement;
                }
            }
        }

        return new AccountStatus(
            chargesEnabled: (bool) $account->charges_enabled,
            payoutsEnabled: (bool) $account->payouts_enabled,
            detailsSubmitted: (bool) $account->details_submitted,
            requirementsCurrentlyDue: $requirementsCurrentlyDue,
        );
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        try {
            // Stripe's verifyHeader both checks the v1 HMAC and (with a
            // non-zero tolerance) the timestamp replay window. Returns
            // true or throws — single-boolean contract per
            // KycProvider::verifyWebhookSignature(): the handler maps
            // false → 401 with one opaque error code (no oracle for an
            // attacker probing for valid signatures).
            return WebhookSignature::verifyHeader(
                $payload,
                $signature,
                $this->webhookSecret,
                $this->webhookTolerance,
            );
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    public function parseWebhookEvent(string $payload): PaymentsWebhookEvent
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('StripePaymentProvider received malformed JSON payload: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('StripePaymentProvider expects a JSON object payload; got '.gettype($decoded));
        }

        $eventId = $decoded['id'] ?? null;
        $eventType = $decoded['type'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            throw new InvalidArgumentException('StripePaymentProvider payload missing required string field: id');
        }

        if (! is_string($eventType) || $eventType === '') {
            throw new InvalidArgumentException('StripePaymentProvider payload missing required string field: type');
        }

        $object = $decoded['data']['object'] ?? null;
        $object = is_array($object) ? $object : [];

        $accountId = is_string($object['id'] ?? null) && $object['id'] !== ''
            ? $object['id']
            : null;

        $chargesEnabled = (bool) ($object['charges_enabled'] ?? false);
        $payoutsEnabled = (bool) ($object['payouts_enabled'] ?? false);

        $requirementsCurrentlyDue = is_array($object['requirements']['currently_due'] ?? null)
            ? array_values(array_filter($object['requirements']['currently_due'], 'is_string'))
            : [];

        // Only `account.updated` carries a payout-readiness transition
        // Sprint 4 acts on; any other event type resolves to a null
        // payoutStatus so ProcessStripeWebhookJob records-and-skips
        // (D-c2-4 — no early money-movement events this chunk).
        $payoutStatus = $eventType === 'account.updated'
            ? PaymentsWebhookEvent::mapPayoutStatus($chargesEnabled, $payoutsEnabled, $requirementsCurrentlyDue)
            : null;

        return new PaymentsWebhookEvent(
            providerEventId: $eventId,
            eventType: $eventType,
            accountId: $accountId,
            payoutStatus: $payoutStatus,
            chargesEnabled: $chargesEnabled,
            payoutsEnabled: $payoutsEnabled,
            rawPayload: $decoded,
        );
    }

    /**
     * Resolve the creator's Stripe connected-account id from the
     * default payout-method row (created at initiatePayout time).
     * The status-poll path only runs after onboarding has been
     * initiated, so the row + provider_account_id always exist; a
     * missing row is a genuine bug we surface loudly rather than
     * masking as "pending".
     */
    private function resolveAccountId(Creator $creator): string
    {
        $payoutMethod = $creator->payoutMethod()->first();

        if (! $payoutMethod instanceof CreatorPayoutMethod || $payoutMethod->provider_account_id === '') {
            throw new RuntimeException(
                "No Stripe connected-account id on file for creator {$creator->ulid}; cannot poll account status before onboarding is initiated.",
            );
        }

        return $payoutMethod->provider_account_id;
    }
}
