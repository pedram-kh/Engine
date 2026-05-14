<?php

declare(strict_types=1);

namespace App\Modules\Creators\Jobs;

use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Mock\MockEsignProvider;
use App\Modules\Creators\Services\InboundWebhookIngestor;
use App\Modules\Creators\Services\InboundWebhookPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Mirror of {@see SimulateKycWebhookJob} for the e-sign mock flow.
 * See that class for the dispatch-decision rationale.
 */
final class SimulateEsignWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $outcome  one of 'signed' | 'declined' (the
     *                           mock-vendor page maps "success" →
     *                           'signed' and "fail" → 'declined').
     */
    public function __construct(
        public readonly string $creatorUlid,
        public readonly string $outcome,
    ) {}

    public function handle(
        InboundWebhookIngestor $ingestor,
        EsignProvider $provider,
    ): void {
        $eventId = 'mock_evt_esign_'.Str::ulid()->toBase32();

        $payload = json_encode([
            'event_id' => $eventId,
            'event_type' => 'envelope.'.$this->outcome,
            'creator_ulid' => $this->creatorUlid,
            'envelope_status' => $this->outcome,
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, MockEsignProvider::webhookSecret());

        $ingestor->ingest(
            provider: 'esign',
            payload: $payload,
            signature: $signature,
            signatureVerifier: fn (string $p, string $s): bool => $provider->verifyWebhookSignature($p, $s),
            payloadParser: function (string $p) use ($provider): InboundWebhookPayload {
                $event = $provider->parseWebhookEvent($p);

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
    }
}
