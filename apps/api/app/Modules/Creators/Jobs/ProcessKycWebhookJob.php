<?php

declare(strict_types=1);

namespace App\Modules\Creators\Jobs;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Models\Creator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Asynchronously process a verified KYC webhook receipt.
 *
 * Dispatched by the webhook controller AFTER signature verification
 * and the IntegrationEvent insert. The controller has already
 * returned 200 to the vendor by the time this job runs (Sprint 3
 * Chunk 2 sub-step 7). The job:
 *
 *   1. Re-parses the integration_events.payload back into a
 *      KycWebhookEvent via the bound KycProvider (Mock in Sprint 3,
 *      real in Sprint 4+).
 *
 *   2. If the event communicates a status transition (verified or
 *      rejected), updates the Creator row's `kyc_status` /
 *      `kyc_verified_at` columns inside a transaction. Idempotent
 *      (#6) — re-running this job for the same event leaves the
 *      Creator row untouched if the status is already terminal.
 *
 *   3. On a first-time transition to {@see KycStatus::Verified},
 *      emits {@see AuditAction::CreatorWizardKycCompleted} (#5
 *      transactional audit). Re-runs do not re-emit.
 *
 *   4. On any update, sets integration_events.processed_at = now().
 *      On a thrown exception, sets integration_events.processing_error
 *      and rethrows so Laravel's standard failed-jobs surface picks it
 *      up — operators inspect via the failed-jobs table (admin SPA
 *      surface lives in Sprint 13+).
 */
final class ProcessKycWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $integrationEventId) {}

    public function handle(KycProvider $provider, AuditLogger $auditLogger): void
    {
        $event = IntegrationEvent::query()->findOrFail($this->integrationEventId);

        try {
            $payloadJson = json_encode($event->payload, JSON_THROW_ON_ERROR);
            $parsed = $provider->parseWebhookEvent($payloadJson);

            if ($parsed->verificationResult === null || $parsed->creatorUlid === null) {
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
                $previousStatus = $creator->kyc_status;
                $newStatus = $parsed->verificationResult;

                // Idempotency (#6): if the column is already at the
                // terminal state we'd transition it to, skip the
                // update + skip the audit emission. The Process*
                // WebhookJob can run any number of times for a
                // given event without spurious re-emissions.
                if ($previousStatus === $newStatus) {
                    $event->forceFill(['processed_at' => now()])->save();

                    return;
                }

                $creator->forceFill([
                    'kyc_status' => $newStatus,
                    'kyc_verified_at' => $newStatus === KycStatus::Verified ? now() : null,
                ])->save();

                if ($newStatus === KycStatus::Verified) {
                    $auditLogger->log(
                        action: AuditAction::CreatorWizardKycCompleted,
                        subject: $creator,
                        agencyId: null,
                        metadata: [
                            'integration_event_id' => $event->id,
                            'previous_status' => $previousStatus->value,
                        ],
                    );
                }

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
