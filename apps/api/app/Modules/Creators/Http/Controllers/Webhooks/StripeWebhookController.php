<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers\Webhooks;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\CreatorsServiceProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Jobs\ProcessStripeWebhookJob;
use App\Modules\Creators\Services\InboundWebhookIngestor;
use App\Modules\Creators\Services\InboundWebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * POST /api/v1/webhooks/stripe — inbound Stripe Connect webhook receiver.
 *
 * Sprint 4 Chunk 2. Mirrors {@see KycWebhookController} exactly — same
 * ingestor, same `integration_events` `(provider, provider_event_id)`
 * dedup, same single-error-code signature envelope. The only in-scope
 * event this chunk is `account.updated` (D-c2-4); other event types
 * are accepted + deduped but resolve to a null payout transition in
 * {@see ProcessStripeWebhookJob} (Sprint 10 handles money-movement).
 *
 * Tenant-less by design (Stripe fires from its own infrastructure).
 * Rate-limited via the `webhooks` named limiter (1000 req/min/provider,
 * keyed on the `stripe` URL segment in {@see CreatorsServiceProvider}).
 * Provider key is `'stripe'`.
 *
 * Flow:
 *   1. Verify the `Stripe-Signature` header via the bound
 *      {@see PaymentProvider} (the real adapter uses Stripe's HMAC +
 *      timestamp-tolerance scheme; the mock uses plain HMAC). Fail →
 *      401 `integration.webhook.signature_failed` (no granular codes).
 *   2. Parse via parseWebhookEvent. Malformed → 400.
 *   3. Insert integration_events keyed on (stripe, evt_id). Unique
 *      violation = idempotent re-receipt → 200, no re-dispatch.
 *   4. Emit IntegrationWebhookReceived audit (transactional, #5).
 *   5. Dispatch {@see ProcessStripeWebhookJob}; Stripe gets a fast 200.
 */
final class StripeWebhookController
{
    public const SIGNATURE_HEADER = 'Stripe-Signature';

    public function __construct(
        private readonly InboundWebhookIngestor $ingestor,
        private readonly PaymentProvider $provider,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();

        if (! is_string($payload) || $payload === '') {
            return ErrorResponse::single(
                $request,
                400,
                'integration.webhook.payload_empty',
                'Webhook payload was empty.',
            );
        }

        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        try {
            $result = $this->ingestor->ingest(
                provider: 'stripe',
                payload: $payload,
                signature: $signature,
                signatureVerifier: fn (string $p, string $s): bool => $this->provider->verifyWebhookSignature($p, $s),
                payloadParser: function (string $p): InboundWebhookPayload {
                    $event = $this->provider->parseWebhookEvent($p);

                    return new InboundWebhookPayload(
                        providerEventId: $event->providerEventId,
                        eventType: $event->eventType,
                        rawPayload: $event->rawPayload,
                    );
                },
                jobDispatcher: function ($event): void {
                    ProcessStripeWebhookJob::dispatch($event->id);
                },
            );
        } catch (InvalidArgumentException $e) {
            // Authentic signature, malformed body → 400 (distinct from
            // the signature-failed 401). Lets Stripe correct + retry.
            return ErrorResponse::single(
                $request,
                400,
                'integration.webhook.payload_malformed',
                $e->getMessage(),
            );
        }

        if ($result->signatureFailedFlag()) {
            return ErrorResponse::single(
                $request,
                401,
                'integration.webhook.signature_failed',
                'Webhook signature verification failed.',
            );
        }

        // accepted + duplicate both map to 200 — duplicate is the
        // chosen idempotency mechanism (D-c2-4).
        return new JsonResponse(['data' => ['status' => $result->status]], 200);
    }
}
