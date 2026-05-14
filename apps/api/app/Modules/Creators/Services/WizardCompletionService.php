<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Creators\Enums\EsignStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\AccountStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Status-poll + state-transition pipeline for the three vendor-
 * gated wizard steps (KYC, payout, contract).
 *
 * Sprint 3 Chunk 2 sub-step 6. Used by both the status-poll
 * endpoints (`GET /wizard/{step}/status`) and the redirect-return
 * endpoints (`GET /wizard/{step}/return`). The two endpoint shapes
 * call the same service method; only the controller-layer
 * response shape differs (Chunk 3 frontend decides whether to
 * render or redirect).
 *
 * Each method:
 *
 *   1. Calls the provider's getVerificationStatus / getEnvelopeStatus
 *      / getAccountStatus with the creator as the lookup key.
 *
 *   2. Compares against the creator's denormalised status column.
 *
 *   3. On a first-successful transition to the terminal "done"
 *      value, updates the row inside a transaction and emits the
 *      matching `creator.wizard.{kyc|contract|payout}_completed`
 *      audit row (#5 transactional).
 *
 *   4. Idempotent (#6): re-polling after completion does NOT
 *      re-emit the audit row.
 */
final class WizardCompletionService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{status: KycStatus, transitioned: bool}
     */
    public function pollKyc(Creator $creator, KycProvider $provider): array
    {
        $this->guardFeatureEnabled(KycVerificationEnabled::NAME);

        $current = $provider->getVerificationStatus($creator);
        $previous = $creator->kyc_status;

        if ($current === $previous) {
            return ['status' => $current, 'transitioned' => false];
        }

        return DB::transaction(function () use ($creator, $current, $previous): array {
            $creator->forceFill([
                'kyc_status' => $current,
                'kyc_verified_at' => $current === KycStatus::Verified ? now() : null,
            ])->save();

            // Completion audit fires only on the success edge —
            // rejection / pending transitions are state changes
            // but not "completion" pairs. The
            // CreatorWizardKycInitiated audit is the start half;
            // CreatorWizardKycCompleted is the success-only end
            // half. Other terminal states are surfaced through
            // the kyc_status column itself; the wizard's submit-
            // validation reads the column directly.
            if ($current === KycStatus::Verified && $previous !== KycStatus::Verified) {
                $this->auditLogger->log(
                    action: AuditAction::CreatorWizardKycCompleted,
                    subject: $creator,
                    metadata: ['previous_status' => $previous->value],
                );
            }

            return ['status' => $current, 'transitioned' => true];
        });
    }

    /**
     * @return array{status: EsignStatus, transitioned: bool}
     */
    public function pollContract(Creator $creator, EsignProvider $provider): array
    {
        $this->guardFeatureEnabled(ContractSigningEnabled::NAME);

        $current = $provider->getEnvelopeStatus($creator);

        // The denormalised contract status on Creator is implicit:
        // `signed_master_contract_id IS NOT NULL` means signed.
        // Translate the column into an EsignStatus for the
        // comparison below.
        $previous = $creator->signed_master_contract_id !== null
            ? EsignStatus::Signed
            : EsignStatus::Sent;

        if ($current === $previous) {
            return ['status' => $current, 'transitioned' => false];
        }

        if ($current !== EsignStatus::Signed) {
            // Non-success transitions (declined / expired) are
            // surfaced by the wizard re-rendering the step; we
            // don't persist them on the Creator row in Sprint 3.
            return ['status' => $current, 'transitioned' => true];
        }

        return DB::transaction(function () use ($creator, $current): array {
            // Sentinel value (until Sprint 4 ships the real
            // `contracts` table). The Process*WebhookJob path
            // uses integration_events.id as the sentinel; the
            // status-poll path uses now()->timestamp so the
            // origin is distinguishable in the audit metadata.
            // Either non-NULL value satisfies the wizard's
            // contract-completion check.
            if ($creator->signed_master_contract_id === null) {
                $creator->forceFill([
                    'signed_master_contract_id' => now()->timestamp,
                ])->save();

                $this->auditLogger->log(
                    action: AuditAction::CreatorWizardContractCompleted,
                    subject: $creator,
                    metadata: ['origin' => 'status_poll'],
                );
            }

            return ['status' => $current, 'transitioned' => true];
        });
    }

    /**
     * @return array{status: AccountStatus, transitioned: bool}
     */
    public function pollPayout(Creator $creator, PaymentProvider $provider): array
    {
        $this->guardFeatureEnabled(CreatorPayoutMethodEnabled::NAME);

        $current = $provider->getAccountStatus($creator);

        $alreadyComplete = (bool) $creator->payout_method_set;

        if (! $current->isFullyOnboarded()) {
            return ['status' => $current, 'transitioned' => false];
        }

        if ($alreadyComplete) {
            return ['status' => $current, 'transitioned' => false];
        }

        return DB::transaction(function () use ($creator, $current): array {
            $creator->forceFill([
                'payout_method_set' => true,
            ])->save();

            $this->auditLogger->log(
                action: AuditAction::CreatorWizardPayoutCompleted,
                subject: $creator,
                metadata: [
                    'charges_enabled' => $current->chargesEnabled,
                    'payouts_enabled' => $current->payoutsEnabled,
                ],
            );

            return ['status' => $current, 'transitioned' => true];
        });
    }

    /**
     * Defence-in-depth alongside the Skipped*Provider binding —
     * the provider would also throw FeatureDisabledException if
     * we let the call through, but pre-checking here gives the
     * controller a clean RuntimeException to translate to a 409
     * `creator.wizard.feature_disabled` response and avoids the
     * wasted provider round-trip.
     */
    private function guardFeatureEnabled(string $flagName): void
    {
        if (! Feature::active($flagName)) {
            throw new RuntimeException("creator.wizard.feature_disabled:{$flagName}");
        }
    }
}
