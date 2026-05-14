<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

/**
 * Vendor-agnostic shape returned by the per-provider payload
 * parser closure passed to {@see InboundWebhookIngestor::ingest()}.
 *
 *   - providerEventId: pairs with the integration_events.provider
 *     column to form the unique idempotency key.
 *   - eventType:       vendor-side event type string.
 *   - rawPayload:      the parsed JSON, stored verbatim on
 *                      integration_events.payload.
 */
final readonly class InboundWebhookPayload
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public array $rawPayload,
    ) {}
}
