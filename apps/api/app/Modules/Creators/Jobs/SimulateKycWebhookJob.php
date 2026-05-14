<?php

declare(strict_types=1);

namespace App\Modules\Creators\Jobs;

use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Services\InboundWebhookIngestor;
use App\Modules\Creators\Services\InboundWebhookPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Simulate an inbound KYC webhook from the local mock-vendor flow.
 *
 * Q-mock-webhook-dispatch decision = (b) in the chunk-2 plan: a
 * queued job calls the production webhook controller's logic
 * directly (not over HTTP) so the test surface remains
 * deterministic + the production code path is exercised end-to-end.
 *
 * Dispatched by {@see \App\Modules\Creators\Http\Controllers\
 * MockVendorController::completeKyc} when the creator clicks
 * "Complete (success)" or "Complete (fail)" on the local
 * `/_mock-vendor/kyc/{session}` page (sub-step 5 Blade template).
 *
 * The job synthesises a payload + HMAC signature using
 * {@see MockKycProvider::webhookSecret()} (single source of truth
 * with the {@see MockKycProvider::verifyWebhookSignature()}
 * implementation), then drives the same
 * {@see InboundWebhookIngestor} pipeline the real webhook
 * controller uses. From the ingestor's perspective the simulated
 * receipt is indistinguishable from a real one.
 */
final class SimulateKycWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $outcome  one of 'verified' | 'rejected' (the
     *                           mock-vendor page maps "success" →
     *                           'verified' and "fail" → 'rejected').
     */
    public function __construct(
        public readonly string $creatorUlid,
        public readonly string $outcome,
    ) {}

    public function handle(
        InboundWebhookIngestor $ingestor,
        KycProvider $provider,
    ): void {
        $eventId = 'mock_evt_kyc_'.Str::ulid()->toBase32();

        $payload = json_encode([
            'event_id' => $eventId,
            'event_type' => 'verification.completed',
            'creator_ulid' => $this->creatorUlid,
            'verification_result' => $this->outcome,
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, MockKycProvider::webhookSecret());

        $ingestor->ingest(
            provider: 'kyc',
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
                ProcessKycWebhookJob::dispatch($event->id);
            },
        );
    }
}
