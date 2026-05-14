<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Audit\Services\AuditLogger;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cross-provider inbound-webhook ingestion pipeline.
 *
 * Concentrates the security + idempotency + audit + dispatch
 * sequence so the per-provider controllers (KYC, eSign) stay thin
 * and so any future provider (Sprint 4 real KYC, Sprint 9 real
 * e-sign, Sprint 10 Stripe Connect) reuses the exact pattern.
 *
 * The pipeline:
 *
 *   1. Verify the signature via {@see $signatureVerifier}. Failure →
 *      emit `integration.webhook.signature_failed` audit (no
 *      IntegrationEvent insert; the unsigned bytes are not vendor-
 *      authentic so we deliberately do NOT keep them) + return
 *      Result::signatureFailed(). The controller maps that to a
 *      401 with the single `integration.webhook.signature_failed`
 *      error code (Refinement 4 + chunk-2 plan's "Decisions
 *      documented for future chunks").
 *
 *   2. Insert an IntegrationEvent row. If the
 *      (provider, provider_event_id) unique index throws, the
 *      event is a re-receipt — return Result::duplicate() so the
 *      controller maps to 200 OK without dispatching the job a
 *      second time (Q-mock-2 = (a) idempotency).
 *
 *   3. Emit `integration.webhook.received` audit (explicit; not
 *      auto-emitted by the IntegrationEvent insert per Refinement
 *      5 — the two writes serve different audiences).
 *
 *   4. Dispatch the per-provider Process*WebhookJob via
 *      {@see $jobDispatcher} so the actual state-update work
 *      happens asynchronously (the webhook caller gets a fast 200).
 *
 * Pre-parsing of the payload + extracting the provider_event_id +
 * event_type happens inside the per-provider closure so the
 * ingestor doesn't need to know vendor-specific shapes.
 */
final class InboundWebhookIngestor
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  Closure(string $payload, string $signature): bool  $signatureVerifier
     * @param  Closure(string $payload): InboundWebhookPayload  $payloadParser
     * @param  Closure(IntegrationEvent $event): void  $jobDispatcher
     */
    public function ingest(
        string $provider,
        string $payload,
        string $signature,
        Closure $signatureVerifier,
        Closure $payloadParser,
        Closure $jobDispatcher,
    ): InboundWebhookResult {
        if (! $signatureVerifier($payload, $signature)) {
            // Single error code + no event row. Per the chunk-2
            // plan: debugging happens via processing_error on
            // future-recoverable failures — signature failures
            // are deliberately opaque to the caller (an attacker
            // probing for valid signatures gets no oracle).
            $this->auditLogger->log(
                action: AuditAction::IntegrationWebhookSignatureFailed,
                metadata: ['provider' => $provider],
            );

            return InboundWebhookResult::signatureFailed();
        }

        $parsed = $payloadParser($payload);

        // INSERT inside a transaction so a failure between the
        // IntegrationEvent insert and the audit write rolls both
        // back consistently (#5 transactional audit).
        try {
            $event = DB::transaction(function () use ($provider, $parsed) {
                $event = IntegrationEvent::query()->create([
                    'provider' => $provider,
                    'provider_event_id' => $parsed->providerEventId,
                    'event_type' => $parsed->eventType,
                    'payload' => $parsed->rawPayload,
                    'received_at' => now(),
                ]);

                $this->auditLogger->log(
                    action: AuditAction::IntegrationWebhookReceived,
                    metadata: [
                        'provider' => $provider,
                        'provider_event_id' => $parsed->providerEventId,
                        'event_type' => $parsed->eventType,
                        'integration_event_id' => $event->id,
                    ],
                );

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            return InboundWebhookResult::duplicate();
        } catch (QueryException $e) {
            // Some PostgreSQL drivers surface unique-violation as
            // a base QueryException with SQLSTATE 23505 instead of
            // the dedicated UniqueConstraintViolationException
            // shape; treat both as the idempotent re-receipt
            // path (the application can never legitimately mean
            // anything else by 23505 on this table).
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'unique_integration_provider_event')) {
                return InboundWebhookResult::duplicate();
            }
            throw $e;
        }

        $jobDispatcher($event);

        return InboundWebhookResult::accepted($event);
    }
}
