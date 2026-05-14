<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers\Webhooks;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Jobs\ProcessEsignWebhookJob;
use App\Modules\Creators\Services\InboundWebhookIngestor;
use App\Modules\Creators\Services\InboundWebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * POST /api/v1/webhooks/esign — inbound e-sign webhook receiver.
 *
 * Mirror of {@see KycWebhookController} for envelope events. See
 * that controller's docblock for the lifecycle narrative.
 */
final class EsignWebhookController
{
    public const SIGNATURE_HEADER = 'X-Catalyst-Webhook-Signature';

    public function __construct(
        private readonly InboundWebhookIngestor $ingestor,
        private readonly EsignProvider $provider,
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
                provider: 'esign',
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
                    ProcessEsignWebhookJob::dispatch($event->id);
                },
            );
        } catch (InvalidArgumentException $e) {
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

        return new JsonResponse(['data' => ['status' => $result->status]], 200);
    }
}
