<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\DataTransferObjects;

use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Integrations\Contracts\KycProvider;

/**
 * Structured KYC webhook event returned from
 * {@see KycProvider::parseWebhookEvent()}.
 *
 *   - providerEventId:     vendor-side unique event identifier; the
 *                          (provider, providerEventId) pair is the
 *                          unique-index idempotency key on
 *                          integration_events (Q-mock-2 = (a)).
 *   - eventType:           vendor-side event type string (e.g.,
 *                          "verification.completed"). Not constrained
 *                          to an enum because vendors evolve their
 *                          event taxonomies independently; the
 *                          handler maps this to internal
 *                          {@see KycStatus} via verificationResult.
 *   - creatorUlid:         the Creator the event is about (when the
 *                          adapter can recover it from the payload —
 *                          mock providers always can; real adapters
 *                          may need a lookup via session id).
 *                          Nullable for the rare "unknown subject"
 *                          case so the handler can record the event
 *                          with `processing_error` set rather than
 *                          dropping it on the floor.
 *   - verificationResult:  one of {@see KycStatus} values, or null if
 *                          the event does not communicate a status
 *                          transition (e.g., heartbeat / metadata
 *                          updates that Sprint 3 ignores).
 *   - rawPayload:          the original parsed JSON payload, stored
 *                          verbatim on integration_events.payload so
 *                          downstream debugging / replay has the
 *                          full vendor view.
 *
 * @phpstan-type KycEventPayload array<string, mixed>
 */
final readonly class KycWebhookEvent
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public ?string $creatorUlid,
        public ?KycStatus $verificationResult,
        public array $rawPayload,
    ) {}
}
