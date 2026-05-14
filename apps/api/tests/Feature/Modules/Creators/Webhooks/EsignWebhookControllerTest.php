<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Mock\MockEsignProvider;
use App\Modules\Creators\Jobs\ProcessEsignWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    app()->bind(EsignProvider::class, MockEsignProvider::class);
});

function makeEsignPayload(string $eventId, string $status = 'signed'): string
{
    return json_encode([
        'event_id' => $eventId,
        'event_type' => 'envelope.'.$status,
        'envelope_status' => $status,
    ], JSON_THROW_ON_ERROR);
}

function signEsignPayload(string $payload): string
{
    return hash_hmac('sha256', $payload, MockEsignProvider::webhookSecret());
}

/**
 * Build the server-header set the controller requires (signature
 * header + content-type). Used inline by each test via $this->call()
 * — extracting a wrapper helper for the call itself loses Pest's
 * implicit access to the TestCase $this context (PHPStan can't see
 * `call()` on `test()`'s return type).
 *
 * @return array<string, string>
 */
function esignWebhookHeaders(string $signature): array
{
    return [
        'HTTP_X-Catalyst-Webhook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];
}

it('200s for a valid signed esign webhook + inserts integration_events + dispatches ProcessEsignWebhookJob', function (): void {
    $payload = makeEsignPayload('evt_esign_first');
    $signature = signEsignPayload($payload);

    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/esign',
        server: esignWebhookHeaders($signature),
        content: $payload,
    );

    expect($response->status())->toBe(200);
    expect(IntegrationEvent::query()->where('provider', 'esign')->count())->toBe(1);
    Bus::assertDispatched(ProcessEsignWebhookJob::class, 1);
    expect(AuditLog::query()->where('action', AuditAction::IntegrationWebhookReceived)->count())->toBe(1);
});

it('401s on invalid esign signature with single error code', function (): void {
    $payload = makeEsignPayload('evt_esign_badsig');

    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/esign',
        server: esignWebhookHeaders('wrong-signature'),
        content: $payload,
    );

    expect($response->status())->toBe(401);
    expect($response->json('errors.0.code'))->toBe('integration.webhook.signature_failed');
    expect(IntegrationEvent::query()->count())->toBe(0);
    expect(AuditLog::query()->where('action', AuditAction::IntegrationWebhookSignatureFailed)->count())->toBe(1);
    Bus::assertNothingDispatched();
});

it('200s on duplicate esign event + does NOT re-insert + does NOT re-dispatch', function (): void {
    $payload = makeEsignPayload('evt_esign_dup');
    $signature = signEsignPayload($payload);

    $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/esign',
        server: esignWebhookHeaders($signature),
        content: $payload,
    );

    $second = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/esign',
        server: esignWebhookHeaders($signature),
        content: $payload,
    );

    expect($second->status())->toBe(200);
    expect(IntegrationEvent::query()->count())->toBe(1);
    Bus::assertDispatched(ProcessEsignWebhookJob::class, 1);
});
