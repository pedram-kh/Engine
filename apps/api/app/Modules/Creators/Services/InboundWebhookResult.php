<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Audit\Models\IntegrationEvent;

/**
 * Result envelope from {@see InboundWebhookIngestor::ingest()}.
 *
 * Three terminal states:
 *
 *   - accepted:        new event accepted, audit emitted, job
 *                      dispatched. Controller returns 200.
 *   - duplicate:       second receipt of an event with the same
 *                      (provider, provider_event_id). No audit /
 *                      no job dispatch (the original receipt
 *                      already did both). Controller returns 200.
 *   - signatureFailed: invalid signature. The signature_failed
 *                      audit row was emitted; no IntegrationEvent
 *                      was created. Controller returns 401 with
 *                      the single `integration.webhook.signature_failed`
 *                      error code (per the chunk-2 plan's
 *                      "Decisions documented for future chunks").
 */
final readonly class InboundWebhookResult
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_SIGNATURE_FAILED = 'signature_failed';

    private function __construct(
        public string $status,
        public ?IntegrationEvent $event,
    ) {}

    public static function accepted(IntegrationEvent $event): self
    {
        return new self(self::STATUS_ACCEPTED, $event);
    }

    public static function duplicate(): self
    {
        return new self(self::STATUS_DUPLICATE, null);
    }

    public static function signatureFailed(): self
    {
        return new self(self::STATUS_SIGNATURE_FAILED, null);
    }

    public function wasAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_DUPLICATE;
    }

    public function signatureFailedFlag(): bool
    {
        return $this->status === self::STATUS_SIGNATURE_FAILED;
    }
}
