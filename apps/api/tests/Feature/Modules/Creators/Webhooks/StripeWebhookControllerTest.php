<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\Mock\MockPaymentProvider;
use App\Modules\Creators\Jobs\ProcessStripeWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 4 Chunk 2 — Stripe webhook controller regression
|--------------------------------------------------------------------------
|
| Mirrors KycWebhookControllerTest. Runs against MockPaymentProvider
| (plain-HMAC signature) so the controller / ingestor / dedup wiring is
| exercised without a real Stripe client. Pins:
|   1. Valid signature → 200 + integration_events row + ProcessStripeWebhookJob
|      dispatched + IntegrationWebhookReceived audit (#5 transactional).
|   2. Invalid signature → 401 single error code, no event row, no dispatch.
|   3. Duplicate (same provider_event_id) → 200, no re-insert, no re-dispatch
|      (idempotency via the integration_events unique index, D-c2-4).
|   4. Empty payload → 400. Malformed JSON (signed) → 400.
|
*/

beforeEach(function (): void {
    Bus::fake();
    app()->bind(PaymentProvider::class, MockPaymentProvider::class);
});

function makeStripeWebhookPayload(string $eventId, string $accountId = 'acct_hook', bool $verified = true): string
{
    return json_encode([
        'event_id' => $eventId,
        'event_type' => 'account.updated',
        'account_id' => $accountId,
        'charges_enabled' => $verified,
        'payouts_enabled' => $verified,
        'requirements_currently_due' => $verified ? [] : ['external_account'],
    ], JSON_THROW_ON_ERROR);
}

function signStripeMockPayload(string $payload): string
{
    return hash_hmac('sha256', $payload, MockPaymentProvider::webhookSecret());
}

/**
 * @return array<string, string>
 */
function stripeWebhookHeaders(string $signature): array
{
    return [
        'HTTP_Stripe-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];
}

it('200s for a valid signed webhook + inserts integration_events + dispatches ProcessStripeWebhookJob + emits received audit', function (): void {
    $payload = makeStripeWebhookPayload('evt_stripe_first');

    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/stripe',
        server: stripeWebhookHeaders(signStripeMockPayload($payload)),
        content: $payload,
    );

    expect($response->status())->toBe(200);
    expect(IntegrationEvent::query()->where('provider', 'stripe')->count())->toBe(1);
    Bus::assertDispatched(ProcessStripeWebhookJob::class, 1);
    expect(AuditLog::query()->where('action', AuditAction::IntegrationWebhookReceived)->count())->toBe(1);
});

it('401s on invalid signature with single error code + no integration_events row + no job dispatch', function (): void {
    $payload = makeStripeWebhookPayload('evt_stripe_badsig');

    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/stripe',
        server: stripeWebhookHeaders('wrong-signature'),
        content: $payload,
    );

    expect($response->status())->toBe(401);
    expect($response->json('errors.0.code'))->toBe('integration.webhook.signature_failed');
    expect(IntegrationEvent::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
    expect(AuditLog::query()->where('action', AuditAction::IntegrationWebhookSignatureFailed)->count())->toBe(1);
});

it('200s on duplicate event + does NOT re-insert + does NOT re-dispatch (idempotency via integration_events)', function (): void {
    $payload = makeStripeWebhookPayload('evt_stripe_dup');
    $server = stripeWebhookHeaders(signStripeMockPayload($payload));

    $first = $this->call('POST', '/api/v1/webhooks/stripe', server: $server, content: $payload);
    $second = $this->call('POST', '/api/v1/webhooks/stripe', server: $server, content: $payload);

    expect($first->status())->toBe(200);
    expect($second->status())->toBe(200);
    expect(IntegrationEvent::query()->where('provider', 'stripe')->count())
        ->toBe(1, 'Unique constraint on (provider, provider_event_id) enforces single insert.');
    Bus::assertDispatched(ProcessStripeWebhookJob::class, 1);
});

it('400s on empty payload', function (): void {
    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/stripe',
        server: stripeWebhookHeaders('irrelevant'),
        content: '',
    );

    expect($response->status())->toBe(400);
    expect($response->json('errors.0.code'))->toBe('integration.webhook.payload_empty');
});

it('400s on signed-but-malformed JSON payload', function (): void {
    $payload = '{not json';

    $response = $this->call(
        method: 'POST',
        uri: '/api/v1/webhooks/stripe',
        server: stripeWebhookHeaders(signStripeMockPayload($payload)),
        content: $payload,
    );

    expect($response->status())->toBe(400);
    expect($response->json('errors.0.code'))->toBe('integration.webhook.payload_malformed');
});
