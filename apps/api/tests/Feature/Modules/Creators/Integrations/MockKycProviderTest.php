<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\DataTransferObjects\KycWebhookEvent;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 2 sub-step 4 — MockKycProvider regression
|--------------------------------------------------------------------------
|
| Pins:
|
|   1. initiateVerification() returns a KycInitiationResult shaped per
|      the contract + writes a cache entry the status-poll path can
|      read back.
|   2. getVerificationStatus() maps each cached state to the right
|      KycStatus enum case (defence-in-depth #40 — silent state-
|      mapping drift would let the wizard advance creators with
|      rejected verifications).
|   3. verifyWebhookSignature() succeeds for HMAC-SHA256 of the
|      payload using the configured secret + fails for any other
|      signature — break-revert pinned via the explicit "wrong
|      secret" case.
|   4. parseWebhookEvent() returns a KycWebhookEvent populated from
|      the payload + rejects malformed payloads with a clear
|      InvalidArgumentException.
|
*/

beforeEach(function (): void {
    Cache::flush();
});

it('initiateVerification returns a result + caches a pending session keyed by creator', function (): void {
    $creator = makeKycCreator();
    $provider = new MockKycProvider;

    $result = $provider->initiateVerification($creator);

    expect($result)->toBeInstanceOf(KycInitiationResult::class)
        ->and($result->sessionId)->toStartWith('mock_kyc_')
        ->and($result->hostedFlowUrl)->toContain('/_mock-vendor/kyc/'.$result->sessionId)
        ->and($result->expiresAt)->not->toBeEmpty();

    $cached = Cache::get(MockKycProvider::sessionCacheKey($result->sessionId));
    expect($cached)->toBe([
        'state' => 'pending',
        'creator_ulid' => $creator->ulid,
        'completed_at' => null,
    ]);

    expect(Cache::get(MockKycProvider::latestSessionPointerKey($creator->ulid)))
        ->toBe($result->sessionId);
});

it('getVerificationStatus maps cached state to KycStatus', function (string $state, KycStatus $expected): void {
    $creator = makeKycCreator();
    $provider = new MockKycProvider;

    $sessionId = 'mock_kyc_test_'.uniqid();
    Cache::put(MockKycProvider::latestSessionPointerKey($creator->ulid), $sessionId, 60);
    Cache::put(MockKycProvider::sessionCacheKey($sessionId), [
        'state' => $state,
        'creator_ulid' => $creator->ulid,
        'completed_at' => null,
    ], 60);

    expect($provider->getVerificationStatus($creator))->toBe($expected);
})->with([
    'pending stays pending' => ['pending', KycStatus::Pending],
    'success → verified' => ['success', KycStatus::Verified],
    'fail → rejected' => ['fail', KycStatus::Rejected],
    'cancelled → none (creator can retry)' => ['cancelled', KycStatus::None],
]);

it('getVerificationStatus returns None when no session has been initiated', function (): void {
    $creator = makeKycCreator();

    expect((new MockKycProvider)->getVerificationStatus($creator))->toBe(KycStatus::None);
});

it('verifyWebhookSignature accepts a correctly-signed payload', function (): void {
    $payload = '{"event_id":"evt_1","event_type":"verification.completed"}';
    $signature = hash_hmac('sha256', $payload, MockKycProvider::webhookSecret());

    expect((new MockKycProvider)->verifyWebhookSignature($payload, $signature))->toBeTrue();
});

it('verifyWebhookSignature rejects every kind of bad signature with a single boolean false', function (string $bad): void {
    // Decision: webhook signature failures collapse to a single
    // false return (and a single error code at the controller
    // layer). The controller does NOT differentiate "wrong secret"
    // vs "stale timestamp" vs "malformed HMAC" — recorded in
    // chunk-2 review's "Decisions documented for future chunks".
    $payload = '{"event_id":"evt_1","event_type":"verification.completed"}';

    expect((new MockKycProvider)->verifyWebhookSignature($payload, $bad))->toBeFalse();
})->with([
    'empty signature' => [''],
    'wrong-length hex' => ['deadbeef'],
    'right-length but wrong content' => [str_repeat('a', 64)],
    'signed with wrong secret' => [hash_hmac('sha256', '{"event_id":"evt_1","event_type":"verification.completed"}', 'wrong-secret')],
]);

it('parseWebhookEvent returns a KycWebhookEvent populated from a well-formed payload', function (): void {
    $payload = json_encode([
        'event_id' => 'evt_mock_42',
        'event_type' => 'verification.completed',
        'creator_ulid' => '01HAAAAAAAAAAAAAAAAAAAAAAA',
        'verification_result' => 'verified',
        'extra' => 'preserved-on-rawPayload',
    ], JSON_THROW_ON_ERROR);

    $event = (new MockKycProvider)->parseWebhookEvent($payload);

    expect($event)->toBeInstanceOf(KycWebhookEvent::class)
        ->and($event->providerEventId)->toBe('evt_mock_42')
        ->and($event->eventType)->toBe('verification.completed')
        ->and($event->creatorUlid)->toBe('01HAAAAAAAAAAAAAAAAAAAAAAA')
        ->and($event->verificationResult)->toBe(KycStatus::Verified)
        ->and($event->rawPayload['extra'])->toBe('preserved-on-rawPayload');
});

it('parseWebhookEvent rejects malformed payloads', function (string $payload, string $expectedFragment): void {
    expect(fn () => (new MockKycProvider)->parseWebhookEvent($payload))
        ->toThrow(InvalidArgumentException::class, $expectedFragment);
})->with([
    'invalid JSON' => ['{not json', 'malformed JSON payload'],
    'JSON array (not object)' => ['[1,2,3]', 'missing required string field: event_id'],
    'missing event_id' => ['{"event_type":"x"}', 'missing required string field: event_id'],
    'missing event_type' => ['{"event_id":"x"}', 'missing required string field: event_type'],
]);

function makeKycCreator(): Creator
{
    $user = User::factory()->createOne();

    return CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);
}
