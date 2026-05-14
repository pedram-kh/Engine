<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Integrations\Mock\MockPaymentProvider;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('createConnectedAccount returns a result + caches a pending account keyed by creator', function (): void {
    $creator = makePaymentCreator();
    $provider = new MockPaymentProvider;

    $result = $provider->createConnectedAccount($creator);

    expect($result)->toBeInstanceOf(PaymentAccountResult::class)
        ->and($result->accountId)->toStartWith('acct_mock_')
        ->and($result->onboardingUrl)->toContain('/_mock-vendor/stripe/'.$result->accountId);

    expect(Cache::get(MockPaymentProvider::accountCacheKey($result->accountId)))
        ->toMatchArray(['state' => 'pending', 'creator_ulid' => $creator->ulid]);
});

it('getAccountStatus returns pending placeholder when no onboarding has started', function (): void {
    $creator = makePaymentCreator();
    $status = (new MockPaymentProvider)->getAccountStatus($creator);

    expect($status->chargesEnabled)->toBeFalse()
        ->and($status->payoutsEnabled)->toBeFalse()
        ->and($status->detailsSubmitted)->toBeFalse()
        ->and($status->requirementsCurrentlyDue)->not->toBe([]);
    expect($status->isFullyOnboarded())->toBeFalse();
});

it('getAccountStatus returns fully-onboarded when the cached account state is complete', function (): void {
    $creator = makePaymentCreator();
    $accountId = 'acct_mock_test_'.uniqid();
    Cache::put(MockPaymentProvider::latestAccountPointerKey($creator->ulid), $accountId, 60);
    Cache::put(MockPaymentProvider::accountCacheKey($accountId), [
        'state' => 'complete',
        'creator_ulid' => $creator->ulid,
        'account_id' => $accountId,
    ], 60);

    $status = (new MockPaymentProvider)->getAccountStatus($creator);

    expect($status->chargesEnabled)->toBeTrue()
        ->and($status->payoutsEnabled)->toBeTrue()
        ->and($status->detailsSubmitted)->toBeTrue()
        ->and($status->requirementsCurrentlyDue)->toBe([]);
    expect($status->isFullyOnboarded())->toBeTrue();
});

it('getAccountStatus returns pending+requirements when the cached account state is cancelled', function (): void {
    $creator = makePaymentCreator();
    $accountId = 'acct_mock_test_'.uniqid();
    Cache::put(MockPaymentProvider::latestAccountPointerKey($creator->ulid), $accountId, 60);
    Cache::put(MockPaymentProvider::accountCacheKey($accountId), [
        'state' => 'cancelled',
        'creator_ulid' => $creator->ulid,
        'account_id' => $accountId,
    ], 60);

    $status = (new MockPaymentProvider)->getAccountStatus($creator);

    expect($status->isFullyOnboarded())->toBeFalse()
        ->and($status->requirementsCurrentlyDue)->toContain('external_account');
});

function makePaymentCreator(): Creator
{
    $user = User::factory()->createOne();

    return CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);
}
