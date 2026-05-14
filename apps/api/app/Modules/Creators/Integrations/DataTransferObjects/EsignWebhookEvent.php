<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\DataTransferObjects;

use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;

/**
 * Structured e-sign webhook event returned from
 * {@see EsignProvider::parseWebhookEvent()}.
 *
 * Field semantics mirror {@see KycWebhookEvent} so the
 * Process{Kind}WebhookJob pipeline reads identically across vendors.
 *
 *   - providerEventId:    idempotency key (paired with provider on
 *                         integration_events.unique_index).
 *   - eventType:          vendor-side event type string.
 *   - creatorUlid:        nullable Creator pointer (rare unknown-subject
 *                         case allowed so the event is logged rather
 *                         than dropped).
 *   - envelopeStatus:     one of {@see EsignStatus}, or null if the
 *                         event communicates no status transition.
 *   - rawPayload:         original parsed JSON, stored verbatim on
 *                         integration_events.payload.
 *
 * @phpstan-type EsignEventPayload array<string, mixed>
 */
final readonly class EsignWebhookEvent
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public ?string $creatorUlid,
        public ?EsignStatus $envelopeStatus,
        public array $rawPayload,
    ) {}
}
