<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers\Webhooks;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\CreatorsServiceProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Jobs\ProcessKycWebhookJob;
use App\Modules\Creators\Services\InboundWebhookIngestor;
use App\Modules\Creators\Services\InboundWebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * POST /api/v1/webhooks/kyc — inbound KYC webhook receiver.
 *
 * Tenant-less by design (vendors fire from their own infrastructure,
 * not from inside our session/auth layer). Allowlisted in
 * docs/security/tenancy.md § 4 (sub-step 11). Rate-limited via the
 * `webhooks` named limiter at 1000 req/min/provider per
 * docs/04-API-DESIGN.md § 13 (registered in
 * {@see CreatorsServiceProvider}).
 *
 * Flow per the chunk-2 plan (sub-step 7) + docs/06-INTEGRATIONS.md
 * § 1.3:
 *
 *   1. Verify HMAC signature (header `X-Catalyst-Webhook-Signature`).
 *      Fail → 401 with single error code
 *      `integration.webhook.signature_failed` per Refinement 4 +
 *      the chunk-2 plan's "Decisions documented for future chunks"
 *      (no granular failure-mode codes; debugging happens via
 *      processing_error on integration_events).
 *
 *   2. Parse the payload through the bound {@see KycProvider}'s
 *      parseWebhookEvent. Malformed payloads → 400.
 *
 *   3. Insert an integration_events row keyed by
 *      (provider, provider_event_id). Unique violation = idempotent
 *      re-receipt — return 200 OK without dispatching the job a
 *      second time.
 *
 *   4. Emit IntegrationWebhookReceived audit (transactional with
 *      the insert per #5).
 *
 *   5. Dispatch {@see ProcessKycWebhookJob} so the actual state
 *      update happens asynchronously; webhook caller gets a fast
 *      200.
 */
final class KycWebhookController
{
    public const SIGNATURE_HEADER = 'X-Catalyst-Webhook-Signature';

    public function __construct(
        private readonly InboundWebhookIngestor $ingestor,
        private readonly KycProvider $provider,
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
                provider: 'kyc',
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
                    ProcessKycWebhookJob::dispatch($event->id);
                },
            );
        } catch (InvalidArgumentException $e) {
            // Payload-parse failure (verifyWebhookSignature already
            // returned true; the signature is authentic but the
            // body shape is malformed). Surface as 400 so the
            // vendor can correct + retry. Distinct from the
            // signature-failed 401 — this is a "we trust you, but
            // your payload is wrong" path.
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

        // Both accepted + duplicate map to 200 OK by design — the
        // duplicate path is the chosen idempotency mechanism
        // (Q-mock-2 = (a)). Vendors retrying a previously-accepted
        // event should see success.
        return new JsonResponse(['data' => ['status' => $result->status]], 200);
    }
}
