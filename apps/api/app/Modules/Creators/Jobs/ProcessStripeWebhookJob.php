<?php

declare(strict_types=1);

namespace App\Modules\Creators\Jobs;

use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\IntegrationEvent;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Creators\Enums\PayoutStatus;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentsWebhookEvent;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Creators\Services\InboundWebhookIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Asynchronously process a verified Stripe `account.updated` webhook.
 *
 * Sprint 4 Chunk 2. Mirrors {@see ProcessKycWebhookJob}: dispatched by
 * {@see InboundWebhookIngestor} AFTER
 * signature verification + the IntegrationEvent insert. The controller
 * has already returned 200 to Stripe by the time this runs. The job:
 *
 *   1. Re-parses integration_events.payload back into a
 *      {@see PaymentsWebhookEvent}
 *      via the bound {@see PaymentProvider} (Mock in dev, Stripe real
 *      adapter in test/staging).
 *
 *   2. Looks the {@see CreatorPayoutMethod} row up by the Stripe
 *      connected-account id (`provider_account_id`) the payload
 *      carries — NOT by creator_ulid, because Stripe's account event
 *      is keyed on the account.
 *
 *   3. Maps the account flags onto {@see PayoutStatus} (done in
 *      parseWebhookEvent) and updates `creator_payout_methods.status`
 *      + `verified_at` inside a transaction. The {@see Audited}
 *      trait on the model auto-emits `creator_payout_method.updated`
 *      (D-c2-6). Idempotent (#6): re-running for the same terminal
 *      status leaves the row untouched + emits nothing.
 *
 *   4. On the first transition to {@see PayoutStatus::Verified}, flips
 *      `creators.payout_method_set` true (mirrors the existing
 *      WizardCompletionService::pollPayout rollup — flip-true only)
 *      and emits {@see AuditAction::CreatorWizardPayoutCompleted}.
 *
 *   5. CRITICALLY: this handler NEVER touches `creators.kyc_status`.
 *      The spec's "account.updated → update creator KYC status" line
 *      (06-INTEGRATIONS.md:127) means Stripe *payout*-KYC, which the
 *      data model deliberately separates from identity KYC (D-c2-5).
 *      Conflating them would corrupt identity-verification state.
 *
 *   6. On any update sets integration_events.processed_at; on a thrown
 *      exception sets processing_error and rethrows so Laravel's
 *      failed-jobs surface picks it up.
 */
final class ProcessStripeWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $integrationEventId) {}

    public function handle(PaymentProvider $provider, AuditLogger $auditLogger): void
    {
        $event = IntegrationEvent::query()->findOrFail($this->integrationEventId);

        try {
            $payloadJson = json_encode($event->payload, JSON_THROW_ON_ERROR);
            $parsed = $provider->parseWebhookEvent($payloadJson);

            // No payout-readiness transition (non-account.updated event)
            // or no account id to correlate → record + skip.
            if ($parsed->payoutStatus === null || $parsed->accountId === null) {
                $event->forceFill(['processed_at' => now()])->save();

                return;
            }

            $payoutMethod = CreatorPayoutMethod::query()
                ->where('provider_account_id', $parsed->accountId)
                ->first();

            if (! $payoutMethod instanceof CreatorPayoutMethod) {
                $event->forceFill([
                    'processed_at' => now(),
                    'processing_error' => 'Unknown provider_account_id: '.$parsed->accountId,
                ])->save();

                return;
            }

            DB::transaction(function () use ($payoutMethod, $parsed, $event, $auditLogger): void {
                $previousStatus = $payoutMethod->status;
                $newStatus = $parsed->payoutStatus;

                // Idempotency (#6): already at the terminal status this
                // event would set → skip the update + the audit. The
                // job can run any number of times for a given event.
                if ($previousStatus === $newStatus) {
                    $event->forceFill(['processed_at' => now()])->save();

                    return;
                }

                // Drives payout-KYC state ONLY. Audited trait auto-emits
                // creator_payout_method.updated on this save (D-c2-6).
                $payoutMethod->forceFill([
                    'status' => $newStatus,
                    'verified_at' => $newStatus === PayoutStatus::Verified ? now() : null,
                ])->save();

                $creator = $payoutMethod->creator;

                // Rollup mirrors WizardCompletionService::pollPayout —
                // flip-true only, on the first verified transition. The
                // completion audit pair fires on the same edge.
                if (
                    $creator !== null
                    && $newStatus === PayoutStatus::Verified
                    && ! $creator->payout_method_set
                ) {
                    $creator->forceFill(['payout_method_set' => true])->save();

                    $auditLogger->log(
                        action: AuditAction::CreatorWizardPayoutCompleted,
                        subject: $creator,
                        agencyId: null,
                        metadata: [
                            'integration_event_id' => $event->id,
                            'charges_enabled' => $parsed->chargesEnabled,
                            'payouts_enabled' => $parsed->payoutsEnabled,
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
                        'payout_status' => $newStatus->value,
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
