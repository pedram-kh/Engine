<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Mock;

use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Models\Creator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mock Stripe-Connect-style payment provider.
 *
 * No webhook surface in Sprint 3 — Stripe Connect's `account.updated`
 * webhook handler is deferred to Sprint 10 per
 * Q-stripe-no-webhook-acceptable in the chunk-2 plan. Status-poll
 * via {@see self::getAccountStatus()} is the only completion signal
 * the wizard reads.
 *
 * Mock state transitions are driven by the local
 * `/_mock-vendor/stripe/{session}` Blade page (sub-step 5) — the
 * creator clicks "Complete onboarding" / "Cancel"; the page's POST
 * handler updates the cached session state, and the wizard's
 * status-poll endpoint picks up the change on the next call.
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
