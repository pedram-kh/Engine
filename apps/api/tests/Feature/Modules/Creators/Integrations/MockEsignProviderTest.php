<?php

declare(strict_types=1);

use App\Modules\Creators\Database\Factories\CreatorFactory;
use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignWebhookEvent;
use App\Modules\Creators\Integrations\Mock\MockEsignProvider;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('sendEnvelope returns a result + caches a sent envelope keyed by creator', function (): void {
    $creator = makeEsignCreator();
    $provider = new MockEsignProvider;

    $result = $provider->sendEnvelope($creator);

    expect($result)->toBeInstanceOf(EsignEnvelopeResult::class)
        ->and($result->envelopeId)->toStartWith('mock_env_')
        ->and($result->signingUrl)->toContain('/_mock-vendor/esign/'.$result->envelopeId);

    expect(Cache::get(MockEsignProvider::envelopeCacheKey($result->envelopeId)))->toBe([
        'state' => 'sent',
        'creator_ulid' => $creator->ulid,
        'completed_at' => null,
    ]);
});

it('getEnvelopeStatus maps cached state to EsignStatus', function (string $state, EsignStatus $expected): void {
    $creator = makeEsignCreator();
    $envelopeId = 'mock_env_test_'.uniqid();
    Cache::put(MockEsignProvider::latestEnvelopePointerKey($creator->ulid), $envelopeId, 60);
    Cache::put(MockEsignProvider::envelopeCacheKey($envelopeId), [
        'state' => $state,
        'creator_ulid' => $creator->ulid,
        'completed_at' => null,
    ], 60);

    expect((new MockEsignProvider)->getEnvelopeStatus($creator))->toBe($expected);
})->with([
    'sent stays sent' => ['sent', EsignStatus::Sent],
    'signed → signed' => ['signed', EsignStatus::Signed],
    'declined → declined' => ['declined', EsignStatus::Declined],
    'expired → expired' => ['expired', EsignStatus::Expired],
    'cancelled collapses to sent' => ['cancelled', EsignStatus::Sent],
]);

it('getEnvelopeStatus returns Sent when no envelope has been sent', function (): void {
    $creator = makeEsignCreator();

    expect((new MockEsignProvider)->getEnvelopeStatus($creator))->toBe(EsignStatus::Sent);
});

it('verifyWebhookSignature accepts a correctly-signed payload + rejects bad signatures', function (): void {
    $payload = '{"event_id":"evt_1","event_type":"envelope.signed"}';
    $signature = hash_hmac('sha256', $payload, MockEsignProvider::webhookSecret());

    expect((new MockEsignProvider)->verifyWebhookSignature($payload, $signature))->toBeTrue();
    expect((new MockEsignProvider)->verifyWebhookSignature($payload, 'wrong'))->toBeFalse();
    expect((new MockEsignProvider)->verifyWebhookSignature($payload, ''))->toBeFalse();
});

it('parseWebhookEvent returns an EsignWebhookEvent populated from a well-formed payload', function (): void {
    $payload = json_encode([
        'event_id' => 'evt_mock_42',
        'event_type' => 'envelope.signed',
        'creator_ulid' => '01HAAAAAAAAAAAAAAAAAAAAAAA',
        'envelope_status' => 'signed',
    ], JSON_THROW_ON_ERROR);

    $event = (new MockEsignProvider)->parseWebhookEvent($payload);

    expect($event)->toBeInstanceOf(EsignWebhookEvent::class)
        ->and($event->providerEventId)->toBe('evt_mock_42')
        ->and($event->envelopeStatus)->toBe(EsignStatus::Signed);
});

it('parseWebhookEvent rejects malformed payloads', function (): void {
    expect(fn () => (new MockEsignProvider)->parseWebhookEvent('{not json'))
        ->toThrow(InvalidArgumentException::class, 'malformed JSON payload');
});

function makeEsignCreator(): Creator
{
    $user = User::factory()->createOne();

    return CreatorFactory::new()->bootstrap()->createOne(['user_id' => $user->id]);
}
