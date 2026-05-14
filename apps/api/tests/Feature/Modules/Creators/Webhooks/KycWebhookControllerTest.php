<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Jobs\ProcessKycWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 2 sub-step 7 — KYC webhook controller regression
|--------------------------------------------------------------------------
|
| Pins:
|   1. Valid HMAC signature → 200 + integration_events row inserted +
|      ProcessKycWebhookJob dispatched + IntegrationWebhookReceived
|      audit emitted (#5 transactional).
|   2. Invalid HMAC → 401 with single error code
|      `integration.webhook.signature_failed` (no granular failure
|      mode codes per the chunk-2 plan's "Decisions documented for
|      future chunks").
|   3. Duplicate event (same provider_event_id) → 200 + NO new
|      integration_events row + NO new audit row + NO new job
|      dispatch (Q-mock-2 = (a) idempotency).
|   4. Empty payload → 400 with `integration.webhook.payload_empty`.
|   5. Malformed JSON → 400 with `integration.webhook.payload_malformed`.
|
*/

beforeEach(function (): void {
    Bus::fake();
    app()->bind(KycProvider::class, MockKycProvider::class);
});

function makeKycPayload(string $eventId, ?string $creatorUlid = null, string $result = 'verified'): string
{
    return json_encode([
        'event_id' => $eventId,
        'event_type' => 'verification.completed',
        'creator_ulid' => $creatorUlid,
        'verification_result' => $result,
    ], JSON_THROW_ON_ERROR);
}

function signKycPayload(string $payload): string
{
    return hash_hmac('sha256', $payload, MockKycProvider::webhookSecret());
}

it('200s for a valid signed webhook + inserts integration_events + dispatches ProcessKycWebhookJob + emits received audit', function (): void {
    $payload = makeKycPayload('evt_kyc_first');
    $signature = signKycPayload($payload);

    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/kyc',
        server: [
            'HTTP_X-Catalyst-Webhook-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $payload,
    );

    expect($response->status())->toBe(200);
    expect(IntegrationEvent::query()->count())->toBe(1);
    Bus::assertDispatched(ProcessKycWebhookJob::class, 1);
    expect(AuditLog::query()->where('action', AuditAction::IntegrationWebhookReceived)->count())->toBe(1);
});

it('401s on invalid signature with single error code + no integration_events row + no job dispatch', function (): void {
    $payload = makeKycPayload('evt_kyc_baddsig');

    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/kyc',
        server: [
            'HTTP_X-Catalyst-Webhook-Signature' => 'wrong-signature',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $payload,
    );

    expect($response->status())->toBe(401);
    expect($response->json('errors.0.code'))->toBe('integration.webhook.signature_failed');
    expect(IntegrationEvent::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
    // Security event emitted for admin review (#5).
    expect(AuditLog::query()->where('action', AuditAction::IntegrationWebhookSignatureFailed)->count())->toBe(1);
});

it('200s on duplicate event + does NOT re-insert + does NOT re-dispatch (Q-mock-2 idempotency)', function (): void {
    $payload = makeKycPayload('evt_kyc_dup');
    $signature = signKycPayload($payload);

    $serverHeaders = [
        'HTTP_X-Catalyst-Webhook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    $first = $this->call('POST', '/api/v1/webhooks/kyc', server: $serverHeaders, content: $payload);
    $second = $this->call('POST', '/api/v1/webhooks/kyc', server: $serverHeaders, content: $payload);

    expect($first->status())->toBe(200);
    expect($second->status())->toBe(200);
    expect(IntegrationEvent::query()->count())->toBe(1, 'Unique constraint on (provider, provider_event_id) enforces single insert.');
    Bus::assertDispatched(ProcessKycWebhookJob::class, 1);
});

it('400s on empty payload', function (): void {
    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/kyc',
        server: [
            'HTTP_X-Catalyst-Webhook-Signature' => 'irrelevant',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: '',
    );

    expect($response->status())->toBe(400);
    expect($response->json('errors.0.code'))->toBe('integration.webhook.payload_empty');
});

it('400s on signed-but-malformed JSON payload', function (): void {
    $payload = '{not json';
    $signature = signKycPayload($payload);

    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/kyc',
        server: [
            'HTTP_X-Catalyst-Webhook-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $payload,
    );

    expect($response->status())->toBe(400);
    expect($response->json('errors.0.code'))->toBe('integration.webhook.payload_malformed');
});
