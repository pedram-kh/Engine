<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Database\Factories\CreatorPayoutMethodFactory;
use App\Modules\Creators\Enums\PayoutStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\Stripe\StripePaymentProvider;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Service\AccountLinkService;
use Stripe\Service\AccountService;
use Stripe\StripeClient;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 4 Chunk 2 — real Stripe Connect onboarding adapter (test-mode)
|--------------------------------------------------------------------------
|
| The adapter is exercised against a FAKED Stripe client (no live API
| calls in CI — the documented test seam from the kickoff's
| honest-deviation trigger). The StripeClient services (accounts,
| accountLinks) are accessed via __get on the real class, so the fake
| is an anonymous subclass exposing them as injectable public props.
|
| Pins:
|   1. createConnectedAccount → real Connect Express account + onboarding
|      link folded into a PaymentAccountResult.
|   2. getAccountStatus → Stripe account flags mapped onto AccountStatus
|      (charges+payouts, no requirements → fully onboarded; requirements
|      due → not onboarded).
|   3. Signature verification (valid + invalid) over Stripe's HMAC scheme.
|   4. parseWebhookEvent maps account.updated flags → PayoutStatus
|      (verified / restricted / pending) and ignores other event types.
|
*/

const STRIPE_TEST_WEBHOOK_SECRET = 'whsec_test_adapter_secret';

/**
 * Build a StripeClient whose `accounts` / `accountLinks` services are
 * the supplied fakes. The parent ctor needs a non-empty key but makes
 * no network call on construction.
 */
function fakeStripeClient(?AccountService $accounts = null, ?AccountLinkService $accountLinks = null): StripeClient
{
    $client = new class('sk_test_dummy_adapter') extends StripeClient
    {
        public mixed $accounts = null;

        public mixed $accountLinks = null;
    };

    $client->accounts = $accounts;
    $client->accountLinks = $accountLinks;

    return $client;
}

function makeStripeAdapter(StripeClient $client): StripePaymentProvider
{
    return new StripePaymentProvider(
        client: $client,
        webhookSecret: STRIPE_TEST_WEBHOOK_SECRET,
        returnUrl: 'https://app.test/onboarding/payout/return',
        refreshUrl: 'https://app.test/onboarding/payout/refresh',
        webhookTolerance: 300,
    );
}

function makeStripeCreator(): Creator
{
    $user = User::factory()->createOne();

    return CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);
}

/**
 * Sign a payload the way Stripe does: header `t=<ts>,v1=<hmac>` where
 * the HMAC is over "<ts>.<payload>". Deterministic + offline.
 */
function stripeSignature(string $payload, ?int $timestamp = null, string $secret = STRIPE_TEST_WEBHOOK_SECRET): string
{
    $timestamp ??= time();
    $signedPayload = $timestamp.'.'.$payload;
    $v1 = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$v1}";
}

it('createConnectedAccount creates an Express account + onboarding link and maps to PaymentAccountResult', function (): void {
    $creator = makeStripeCreator();

    $accounts = Mockery::mock(AccountService::class);
    $accounts->shouldReceive('create')
        ->once()
        ->andReturn(Account::constructFrom(['id' => 'acct_live_test_123']));

    $accountLinks = Mockery::mock(AccountLinkService::class);
    $accountLinks->shouldReceive('create')
        ->once()
        ->andReturn(AccountLink::constructFrom([
            'url' => 'https://connect.stripe.com/setup/e/acct_live_test_123/abc',
            'expires_at' => 1_900_000_000,
        ]));

    $result = makeStripeAdapter(fakeStripeClient($accounts, $accountLinks))
        ->createConnectedAccount($creator);

    expect($result)->toBeInstanceOf(PaymentAccountResult::class)
        ->and($result->accountId)->toBe('acct_live_test_123')
        ->and($result->onboardingUrl)->toBe('https://connect.stripe.com/setup/e/acct_live_test_123/abc')
        ->and($result->expiresAt)->toContain('2030-');
});

it('getAccountStatus maps a fully-onboarded Stripe account to a fully-onboarded AccountStatus', function (): void {
    $creator = makeStripeCreator();
    CreatorPayoutMethodFactory::new()->createOne([
        'creator_id' => $creator->id,
        'provider' => 'stripe',
        'provider_account_id' => 'acct_status_ok',
        'is_default' => true,
    ]);

    $accounts = Mockery::mock(AccountService::class);
    $accounts->shouldReceive('retrieve')
        ->once()
        ->andReturn(Account::constructFrom([
            'id' => 'acct_status_ok',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements' => ['currently_due' => []],
        ]));

    $status = makeStripeAdapter(fakeStripeClient($accounts))->getAccountStatus($creator);

    expect($status->chargesEnabled)->toBeTrue()
        ->and($status->payoutsEnabled)->toBeTrue()
        ->and($status->detailsSubmitted)->toBeTrue()
        ->and($status->requirementsCurrentlyDue)->toBe([])
        ->and($status->isFullyOnboarded())->toBeTrue();
});

it('getAccountStatus surfaces outstanding requirements (not fully onboarded)', function (): void {
    $creator = makeStripeCreator();
    CreatorPayoutMethodFactory::new()->createOne([
        'creator_id' => $creator->id,
        'provider' => 'stripe',
        'provider_account_id' => 'acct_status_due',
        'is_default' => true,
    ]);

    $accounts = Mockery::mock(AccountService::class);
    $accounts->shouldReceive('retrieve')
        ->once()
        ->andReturn(Account::constructFrom([
            'id' => 'acct_status_due',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'requirements' => ['currently_due' => ['external_account', 'tos_acceptance.date']],
        ]));

    $status = makeStripeAdapter(fakeStripeClient($accounts))->getAccountStatus($creator);

    expect($status->isFullyOnboarded())->toBeFalse()
        ->and($status->requirementsCurrentlyDue)->toContain('external_account');
});

it('verifyWebhookSignature accepts a valid Stripe-format signature and rejects a tampered one', function (): void {
    $adapter = makeStripeAdapter(fakeStripeClient());
    $payload = '{"id":"evt_sig","type":"account.updated"}';

    expect($adapter->verifyWebhookSignature($payload, stripeSignature($payload)))->toBeTrue();
    expect($adapter->verifyWebhookSignature($payload, stripeSignature($payload, secret: 'whsec_wrong')))->toBeFalse();
    expect($adapter->verifyWebhookSignature($payload, 't=1,v1=deadbeef'))->toBeFalse();
});

it('parseWebhookEvent maps account.updated flags onto PayoutStatus', function (): void {
    $adapter = makeStripeAdapter(fakeStripeClient());

    $verified = $adapter->parseWebhookEvent(json_encode([
        'id' => 'evt_verified',
        'type' => 'account.updated',
        'data' => ['object' => [
            'id' => 'acct_v',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'requirements' => ['currently_due' => []],
        ]],
    ], JSON_THROW_ON_ERROR));

    expect($verified->payoutStatus)->toBe(PayoutStatus::Verified)
        ->and($verified->accountId)->toBe('acct_v')
        ->and($verified->providerEventId)->toBe('evt_verified');

    $restricted = $adapter->parseWebhookEvent(json_encode([
        'id' => 'evt_restricted',
        'type' => 'account.updated',
        'data' => ['object' => [
            'id' => 'acct_r',
            'charges_enabled' => true,
            'payouts_enabled' => false,
            'requirements' => ['currently_due' => ['external_account']],
        ]],
    ], JSON_THROW_ON_ERROR));

    expect($restricted->payoutStatus)->toBe(PayoutStatus::Restricted);
});

it('parseWebhookEvent leaves payoutStatus null for non-account.updated events (no early money-movement, D-c2-4)', function (): void {
    $adapter = makeStripeAdapter(fakeStripeClient());

    $event = $adapter->parseWebhookEvent(json_encode([
        'id' => 'evt_charge',
        'type' => 'charge.succeeded',
        'data' => ['object' => ['id' => 'ch_1']],
    ], JSON_THROW_ON_ERROR));

    expect($event->payoutStatus)->toBeNull()
        ->and($event->eventType)->toBe('charge.succeeded');
});
