<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Mock;

use App\Modules\Creators\CreatorsServiceProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentsWebhookEvent;
use App\Modules\Creators\Models\Creator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * Mock Stripe-Connect-style payment provider.
 *
 * Bound by {@see CreatorsServiceProvider} when
 * `creator_payout_method_enabled` is ON and the driver is `mock`
 * (Sprint 3 default). Sprint 4 Chunk 2 adds the inbound-webhook pair
 * here too so flag-on/mock-driver dev exercises the `account.updated`
 * pipeline end-to-end without a real Stripe account (D-c2-3).
 *
 * Onboarding state transitions are driven by the local
 * `/_mock-vendor/stripe/{session}` Blade page — the creator clicks
 * "Complete onboarding" / "Cancel"; the page's POST handler updates
 * the cached session state, and the wizard's status-poll endpoint
 * picks up the change on the next call.
 *
 * @phpstan-type MockPaymentSession array{state: 'pending'|'complete'|'cancelled', creator_ulid: string, account_id: string}
 */
final class MockPaymentProvider implements PaymentProvider
{
    public const SESSION_TTL_SECONDS = 24 * 60 * 60;

    public static function accountCacheKey(string $accountId): string
    {
        return 'mock:payment:account:'.$accountId;
    }

    public static function latestAccountPointerKey(string $creatorUlid): string
    {
        return 'mock:payment:latest:'.$creatorUlid;
    }

    public function createConnectedAccount(Creator $creator): PaymentAccountResult
    {
        $accountId = 'acct_mock_'.Str::ulid()->toBase32();

        Cache::put(
            self::accountCacheKey($accountId),
            [
                'state' => 'pending',
                'creator_ulid' => $creator->ulid,
                'account_id' => $accountId,
            ],
            self::SESSION_TTL_SECONDS,
        );

        Cache::put(
            self::latestAccountPointerKey($creator->ulid),
            $accountId,
            self::SESSION_TTL_SECONDS,
        );

        return new PaymentAccountResult(
            accountId: $accountId,
            onboardingUrl: url('/_mock-vendor/stripe/'.$accountId),
            expiresAt: now()->addSeconds(self::SESSION_TTL_SECONDS)->toIso8601String(),
        );
    }

    public function getAccountStatus(Creator $creator): AccountStatus
    {
        $latestAccountId = Cache::get(self::latestAccountPointerKey($creator->ulid));

        if (! is_string($latestAccountId)) {
            return $this->pendingStatus();
        }

        $account = Cache::get(self::accountCacheKey($latestAccountId));

        if (! is_array($account)) {
            return $this->pendingStatus();
        }

        return match ($account['state'] ?? null) {
            // Fully-onboarded: all gates true, no outstanding
            // requirements — wizard's payout-step completion gate
            // (AccountStatus::isFullyOnboarded()) flips true.
            'complete' => new AccountStatus(
                chargesEnabled: true,
                payoutsEnabled: true,
                detailsSubmitted: true,
                requirementsCurrentlyDue: [],
            ),
            // Cancelled: the creator backed out of the hosted flow.
            // Surface a representative outstanding-requirements list
            // so the frontend can render the "resume onboarding"
            // CTA without the wizard advancing.
            'cancelled' => new AccountStatus(
                chargesEnabled: false,
                payoutsEnabled: false,
                detailsSubmitted: false,
                requirementsCurrentlyDue: ['external_account', 'tos_acceptance.date'],
            ),
            default => $this->pendingStatus(),
        };
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, self::webhookSecret());

        // Constant-time comparison defends against timing attacks even
        // in the mock — keeps the test surface honest about how the
        // real adapter must behave (#40). Mirrors MockKycProvider.
        return hash_equals($expected, $signature);
    }

    public function parseWebhookEvent(string $payload): PaymentsWebhookEvent
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('MockPaymentProvider received malformed JSON payload: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('MockPaymentProvider expects a JSON object payload; got '.gettype($decoded));
        }

        $eventId = $decoded['event_id'] ?? null;
        $eventType = $decoded['event_type'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            throw new InvalidArgumentException('MockPaymentProvider payload missing required string field: event_id');
        }

        if (! is_string($eventType) || $eventType === '') {
            throw new InvalidArgumentException('MockPaymentProvider payload missing required string field: event_type');
        }

        $accountId = is_string($decoded['account_id'] ?? null) && $decoded['account_id'] !== ''
            ? $decoded['account_id']
            : null;

        $chargesEnabled = (bool) ($decoded['charges_enabled'] ?? false);
        $payoutsEnabled = (bool) ($decoded['payouts_enabled'] ?? false);

        $requirementsCurrentlyDue = is_array($decoded['requirements_currently_due'] ?? null)
            ? array_values(array_filter($decoded['requirements_currently_due'], 'is_string'))
            : [];

        // `account.updated` is the only Sprint 4 payout event; other
        // event types carry no payout-readiness transition and resolve
        // to a null payoutStatus so the handler records-and-skips.
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
     * The HMAC-SHA256 secret for the mock webhook path. Single source
     * of truth so any future SimulatePaymentWebhookJob can sign with
     * the same secret. Mirrors {@see MockKycProvider::webhookSecret()}.
     */
    public static function webhookSecret(): string
    {
        $secret = config('integrations.payment.mock_webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new LogicException(
                'integrations.payment.mock_webhook_secret must be a non-empty string in config/integrations.php.',
            );
        }

        return $secret;
    }

    /**
     * Mid-flow placeholder — the creator started but hasn't returned
     * yet. Surfaces "still pending" requirements so the frontend can
     * render a "you're not done yet" message.
     */
    private function pendingStatus(): AccountStatus
    {
        return new AccountStatus(
            chargesEnabled: false,
            payoutsEnabled: false,
            detailsSubmitted: false,
            requirementsCurrentlyDue: ['external_account', 'tos_acceptance.date'],
        );
    }
}
