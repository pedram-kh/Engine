<?php

declare(strict_types=1);

namespace App\Modules\Creators\Jobs;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Models\Creator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Asynchronously process a verified e-sign webhook receipt.
 *
 * Mirror of {@see ProcessKycWebhookJob} for envelope-status
 * transitions. See that class's docblock for the lifecycle
 * narrative; this class follows the identical shape with the
 * envelope vocabulary substituted.
 *
 * State transitions handled in Sprint 3:
 *
 *   - {@see EsignStatus::Signed}: terminal success. Sets
 *     `creators.signed_master_contract_id` to a sentinel value
 *     keyed off the integration_events.id (Sprint 4 lands the real
 *     `contracts` table; until then the contracts surface is
 *     deferred and the wizard reads "contract step done" from
 *     `signed_master_contract_id IS NOT NULL`). Emits
 *     {@see AuditAction::CreatorWizardContractCompleted}.
 *
 *   - {@see EsignStatus::Declined}: surfaces in the wizard for
 *     creator follow-up but does NOT advance the step. Audit is
 *     emitted for operator visibility (no completion audit fires;
 *     that's the contract-completed pair's domain).
 *
 *   - {@see EsignStatus::Expired}: same shape as declined.
 *
 *   - {@see EsignStatus::Sent}: heartbeat / ack from the vendor
 *     side; no state change.
 */
final class ProcessEsignWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $integrationEventId) {}

    public function handle(EsignProvider $provider, AuditLogger $auditLogger): void
    {
        $event = IntegrationEvent::query()->findOrFail($this->integrationEventId);

        try {
            $payloadJson = json_encode($event->payload, JSON_THROW_ON_ERROR);
            $parsed = $provider->parseWebhookEvent($payloadJson);

            if ($parsed->envelopeStatus === null || $parsed->creatorUlid === null) {
                $event->forceFill(['processed_at' => now()])->save();

                return;
            }

            $creator = Creator::query()->where('ulid', $parsed->creatorUlid)->first();

            if (! $creator instanceof Creator) {
                $event->forceFill([
                    'processed_at' => now(),
                    'processing_error' => 'Unknown creator_ulid: '.$parsed->creatorUlid,
                ])->save();

                return;
            }

            DB::transaction(function () use ($creator, $parsed, $event, $auditLogger): void {
                if ($parsed->envelopeStatus !== EsignStatus::Signed) {
                    // Non-success terminal states (declined,
                    // expired) and heartbeats are recorded on the
                    // event but do not transition the wizard
                    // step. The frontend re-fetches the wizard
                    // state and surfaces the appropriate "contract
                    // signing failed — please re-attempt" UX.
                    $event->forceFill(['processed_at' => now()])->save();

                    return;
                }

                // Idempotency (#6): a creator who already has a
                // contract on file (or a click-through acceptance
                // recorded) does not get re-stamped.
                if ($creator->signed_master_contract_id !== null) {
                    $event->forceFill(['processed_at' => now()])->save();

                    return;
                }

                // Sentinel: until Sprint 4's contracts table lands,
                // any non-NULL value satisfies the wizard's
                // contract-step completion check. The
                // integration_events.id makes it auditable back to
                // the originating envelope.
                $creator->forceFill([
                    'signed_master_contract_id' => $event->id,
                ])->save();

                $auditLogger->log(
                    action: AuditAction::CreatorWizardContractCompleted,
                    subject: $creator,
                    agencyId: null,
                    metadata: [
                        'integration_event_id' => $event->id,
                    ],
                );

                $event->forceFill([
                    'processed_at' => now(),
                    'processing_error' => null,
                ])->save();

                $auditLogger->log(
                    action: AuditAction::IntegrationWebhookProcessed,
                    subject: $creator,
                    agencyId: null,
                    metadata: [
                        'integration_event_id' => $event->id,
                        'provider' => $event->provider,
                        'event_type' => $event->event_type,
                    ],
                );
            });
        } catch (Throwable $e) {
            $event->forceFill([
                'processing_error' => substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }
    }
}
