<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\KycVerificationStatus;
use App\Modules\Creators\Enums\PayoutStatus;
use App\Modules\Creators\Enums\SocialPlatform;
use App\Modules\Creators\Enums\TaxFormType;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Integrations\DataTransferObjects\EsignEnvelopeResult;
use App\Modules\Creators\Integrations\DataTransferObjects\KycInitiationResult;
use App\Modules\Creators\Integrations\DataTransferObjects\PaymentAccountResult;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKycVerification;
use App\Modules\Creators\Models\CreatorPayoutMethod;
use App\Modules\Creators\Models\CreatorSocialAccount;
use App\Modules\Creators\Models\CreatorTaxProfile;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Orchestrates wizard step transitions for a Creator.
 *
 * Each public method is wrapped in a transaction so partial failure
 * never leaves a Creator in a half-saved state. Audit emission for
 * wizard step completions is idempotent (#6) — re-submitting a
 * completed step does NOT re-emit the wizard.*_completed audit row.
 *
 * Provider-backed steps (KYC initiate, payout initiate, contract sign)
 * delegate to {@see KycProvider}, {@see PaymentProvider}, and
 * {@see EsignProvider} respectively. During Sprint 3 Chunk 1 those
 * contracts resolve to Deferred*Provider stubs that throw — wiring
 * the real Mock providers is Chunk 2's job.
 */
final class CreatorWizardService
{
    public function __construct(
        private readonly CompletenessScoreCalculator $calculator,
        private readonly KycProvider $kycProvider,
        private readonly PaymentProvider $paymentProvider,
        private readonly EsignProvider $esignProvider,
    ) {}

    /**
     * Step 2 — Profile basics. PATCH semantics: only the fields the
     * creator submitted are updated. Audit fires once per first-time
     * profile completion.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateProfile(Creator $creator, array $attributes): Creator
    {
        return DB::transaction(function () use ($creator, $attributes): Creator {
            $wasComplete = $this->calculator->stepCompletion($creator)['profile'];

            $creator->fill($attributes);
            $this->refreshCompleteness($creator);
            $creator->save();

            $isComplete = $this->calculator->stepCompletion($creator->refresh())['profile'];
            if (! $wasComplete && $isComplete) {
                Audit::log(
                    action: AuditAction::CreatorWizardProfileCompleted,
                    actor: $creator->user,
                    subject: $creator,
                );
            }

            return $creator;
        });
    }

    /**
     * Step 3 — Connect a social account. Stub OAuth: real OAuth
     * exchange is Sprint 4. Sprint 3 Chunk 1 records the platform +
     * handle so the wizard can proceed; the metrics/refresh tokens
     * land in Chunk 4.
     *
     * @param  array{platform: SocialPlatform, handle: string, profile_url: string}  $payload
     */
    public function connectSocial(Creator $creator, array $payload): CreatorSocialAccount
    {
        return DB::transaction(function () use ($creator, $payload): CreatorSocialAccount {
            $wasComplete = $this->calculator->stepCompletion($creator)['social'];

            // Sprint 3 Chunk 1 records the platform + handle without the
            // OAuth exchange (real OAuth lands in Chunk 4). The
            // platform_user_id placeholder is the lower-case handle —
            // Chunk 4's OAuth code path overwrites this with the
            // provider-issued numeric/string id. The unique
            // (creator_id, platform) index keeps the row count sane.
            $account = $creator->socialAccounts()->updateOrCreate(
                ['platform' => $payload['platform']->value],
                [
                    'platform_user_id' => mb_strtolower($payload['handle']),
                    'handle' => $payload['handle'],
                    'profile_url' => $payload['profile_url'],
                    'is_primary' => $creator->socialAccounts()->where('is_primary', true)->doesntExist(),
                ],
            );

            $this->refreshCompleteness($creator);
            $creator->save();

            if (! $wasComplete) {
                Audit::log(
                    action: AuditAction::CreatorWizardSocialCompleted,
                    actor: $creator->user,
                    subject: $creator,
                );
            }

            return $account;
        });
    }

    /**
     * Step 5 — Initiate hosted KYC flow. Persists the session +
     * provider identifier so we can correlate the eventual webhook.
     */
    public function initiateKyc(Creator $creator): KycInitiationResult
    {
        $this->guardFeatureEnabled(KycVerificationEnabled::NAME);

        return DB::transaction(function () use ($creator): KycInitiationResult {
            $result = $this->kycProvider->initiateVerification($creator);

            CreatorKycVerification::create([
                'creator_id' => $creator->id,
                'provider' => 'mock',
                'provider_session_id' => $result->sessionId,
                'status' => KycVerificationStatus::Pending,
            ]);

            $creator->forceFill(['kyc_status' => KycStatus::Pending->value])->save();

            Audit::log(
                action: AuditAction::CreatorWizardKycInitiated,
                actor: $creator->user,
                subject: $creator,
            );

            return $result;
        });
    }

    /**
     * Step 6 — Tax profile (PATCH semantics). PII is encrypted at the
     * Eloquent cast layer — we never write plaintext to the database.
     *
     * @param  array{tax_form_type: TaxFormType, legal_name: string, tax_id: string, address: array<string, mixed>}  $payload
     */
    public function upsertTaxProfile(Creator $creator, array $payload): CreatorTaxProfile
    {
        return DB::transaction(function () use ($creator, $payload): CreatorTaxProfile {
            $profile = $creator->taxProfile()->updateOrCreate(
                ['creator_id' => $creator->id],
                [
                    'tax_form_type' => $payload['tax_form_type']->value,
                    'legal_name' => $payload['legal_name'],
                    'tax_id' => $payload['tax_id'],
                    'tax_id_country' => mb_strtoupper(
                        (string) ($payload['address']['country_code'] ?? ''),
                    ) ?: 'XX',
                    'address' => $payload['address'],
                    'submitted_at' => now(),
                ],
            );

            $creator->forceFill(['tax_profile_complete' => true])->save();
            $this->refreshCompleteness($creator);
            $creator->save();

            Audit::log(
                action: AuditAction::CreatorWizardTaxCompleted,
                actor: $creator->user,
                subject: $creator,
            );

            return $profile;
        });
    }

    /**
     * Step 7 — Initiate payout-method onboarding (Stripe-Connect-style).
     * Persists the connected-account id so subsequent webhook events
     * can correlate. The creator-facing onboardingUrl is returned for
     * the frontend redirect.
     */
    public function initiatePayout(Creator $creator): PaymentAccountResult
    {
        $this->guardFeatureEnabled(CreatorPayoutMethodEnabled::NAME);

        return DB::transaction(function () use ($creator): PaymentAccountResult {
            $result = $this->paymentProvider->createConnectedAccount($creator);

            CreatorPayoutMethod::updateOrCreate(
                ['creator_id' => $creator->id, 'is_default' => true],
                [
                    'provider' => 'mock',
                    'provider_account_id' => $result->accountId,
                    'currency' => 'EUR',
                    'status' => PayoutStatus::Pending,
                    'is_default' => true,
                ],
            );

            Audit::log(
                action: AuditAction::CreatorWizardPayoutInitiated,
                actor: $creator->user,
                subject: $creator,
            );

            return $result;
        });
    }

    /**
     * Step 8 — Master-contract signature (e-sign). The signed
     * envelope's signed_master_contract_id is set by the webhook
     * handler in Sprint 4; Chunk 1 only initiates the signing flow.
     */
    public function initiateContract(Creator $creator): EsignEnvelopeResult
    {
        $this->guardFeatureEnabled(ContractSigningEnabled::NAME);

        return DB::transaction(function () use ($creator): EsignEnvelopeResult {
            $result = $this->esignProvider->sendEnvelope($creator);

            Audit::log(
                action: AuditAction::CreatorWizardContractInitiated,
                actor: $creator->user,
                subject: $creator,
                metadata: ['envelope_id' => $result->envelopeId],
            );

            return $result;
        });
    }

    /**
     * Step 9 — Submit for approval. Requires every preceding step to
     * be complete; otherwise throws and the controller translates to
     * a 409 with `creator.wizard.incomplete`.
     */
    public function submit(Creator $creator): Creator
    {
        return DB::transaction(function () use ($creator): Creator {
            // Sprint 3 Chunk 2 sub-step 9 — flag-OFF skip-path on
            // submit. When kyc_verification_enabled is OFF and the
            // creator's kyc_status is still the default `none`,
            // stamp `not_required` so the row tells the forensic
            // story "operator-bypassed at submit time"
            // (Q-flag-off-1 = (a)). The transition is one-way: a
            // Verified creator stays Verified even if the flag
            // later flips off.
            if (
                ! Feature::active(KycVerificationEnabled::NAME)
                && $creator->kyc_status === KycStatus::None
            ) {
                $creator->forceFill([
                    'kyc_status' => KycStatus::NotRequired->value,
                ])->save();
                $creator->refresh();
            }

            $completion = $this->calculator->stepCompletion($creator);
            foreach ($completion as $isComplete) {
                if (! $isComplete) {
                    throw new RuntimeException('creator.wizard.incomplete');
                }
            }

            $creator->forceFill([
                'application_status' => ApplicationStatus::Pending->value,
                'submitted_at' => now(),
            ])->save();

            Audit::log(
                action: AuditAction::CreatorSubmitted,
                actor: $creator->user,
                subject: $creator,
            );

            return $creator;
        });
    }

    /**
     * Step 8 alternate path — click-through-accept fallback.
     *
     * Available only when `contract_signing_enabled` is OFF
     * (Q-flag-off-2 = (a) in the chunk-2 plan). Stamps
     * `creators.click_through_accepted_at` so the wizard's
     * submit-validation treats the contract step as satisfied
     * without an envelope. Idempotent: a second accept does NOT
     * re-stamp the timestamp or re-emit the audit row.
     */
    public function acceptClickThroughContract(Creator $creator): Creator
    {
        if (Feature::active(ContractSigningEnabled::NAME)) {
            throw new RuntimeException('creator.wizard.feature_enabled');
        }

        return DB::transaction(function () use ($creator): Creator {
            if ($creator->click_through_accepted_at !== null) {
                return $creator;
            }

            $creator->forceFill([
                'click_through_accepted_at' => now(),
            ])->save();

            Audit::log(
                action: AuditAction::CreatorWizardClickThroughAccepted,
                actor: $creator->user,
                subject: $creator,
            );

            return $creator;
        });
    }

    private function refreshCompleteness(Creator $creator): void
    {
        $creator->profile_completeness_score = $this->calculator->score($creator);
    }

    /**
     * Throw the RuntimeException the controller translates to a
     * 409 `creator.wizard.feature_disabled` response when an
     * initiate endpoint is hit while its gating flag is OFF.
     * Defence-in-depth alongside the Skipped*Provider binding —
     * the provider would also throw FeatureDisabledException, but
     * pre-checking at the service layer gives a clearer error
     * surface and avoids the wasted provider round-trip.
     */
    private function guardFeatureEnabled(string $flagName): void
    {
        if (! Feature::active($flagName)) {
            throw new RuntimeException("creator.wizard.feature_disabled:{$flagName}");
        }
    }
}
