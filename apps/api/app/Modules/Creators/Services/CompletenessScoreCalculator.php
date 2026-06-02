<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\WizardStep;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Models\Creator;
use Laravel\Pennant\Feature;

/**
 * Computes the 0–100 profile-completeness score stored on
 * `creators.profile_completeness_score`.
 *
 * The weighting reflects the relative effort + verification value of
 * each wizard step. Profile basics is the floor (25 — without it the
 * profile is a stub); contract is the cap (15 — the master agreement
 * gates payouts and approval).
 *
 * Step weights (sum = 100):
 *
 *   profile      25  display_name + country + primary_language + categories + avatar
 *   social       15  at least one connected account
 *   portfolio    10  at least one portfolio item
 *   kyc          15  kyc_status = verified
 *   tax          10  tax_profile_complete = true
 *   payout       10  payout_method_set = true
 *   contract     15  signed_master_contract_id is not null
 *
 * Source-inspection regression test (#1):
 * tests/Feature/Modules/Creators/CompletenessScoreCalculatorTest.php
 * pins the weights AND the sum-to-100 invariant. If the weights change,
 * the test fails — forcing reviewer attention to the weighting choice.
 *
 * Q2 (resume UX): the per-step completion flags are exposed on the
 * bootstrap response, so the frontend can render the wizard without a
 * round-trip per step.
 */
final class CompletenessScoreCalculator
{
    /**
     * @return array<string, int>
     */
    public function weights(): array
    {
        return [
            WizardStep::Profile->value => 25,
            WizardStep::Social->value => 15,
            WizardStep::Portfolio->value => 10,
            WizardStep::Kyc->value => 15,
            WizardStep::Tax->value => 10,
            WizardStep::Payout->value => 10,
            WizardStep::Contract->value => 15,
        ];
    }

    /**
     * Compute the score for the given creator. Triggers no extra
     * queries beyond the relationships the caller has eager-loaded.
     *
     * The score is renormalised over the steps that actually apply in
     * the current environment. A vendor-gated step whose feature flag
     * is OFF is "skipped" (Decision E1=a) — it requires no creator
     * action — so it is excluded from BOTH the numerator AND the
     * denominator. Previously such steps were credited their full
     * weight up front, which inflated the score for a creator who had
     * done nothing (e.g. a fresh creator reading 40% with KYC/payout/
     * contract all flag-OFF). Now the percentage reflects only the work
     * the creator can actually do, starts at 0, and still reaches 100
     * once every applicable step is complete (the all-flags-OFF
     * "score = 100 when the four enabled steps are done" contract in
     * CreatorWizardFlagOffTest is preserved: 60/60 = 100).
     */
    public function score(Creator $creator): int
    {
        $weights = $this->weights();
        $applicable = $this->applicableSteps();

        $earned = 0;
        $total = 0;

        foreach ($this->stepCompletion($creator) as $step => $isComplete) {
            if (! isset($weights[$step]) || ! in_array($step, $applicable, true)) {
                continue;
            }

            $total += $weights[$step];

            if ($isComplete) {
                $earned += $weights[$step];
            }
        }

        if ($total === 0) {
            return 0;
        }

        // Cap at 100 defensively — earned can never exceed total, but a
        // future weight change without a matching test update should
        // never produce > 100 user-facing.
        return min((int) round($earned / $total * 100), 100);
    }

    /**
     * Per-step completion map keyed by WizardStep::value.
     *
     * @return array<string, bool>
     */
    public function stepCompletion(Creator $creator): array
    {
        // Sprint 3 Chunk 2 sub-step 9 — flag-OFF skip-path. When a
        // vendor-gated step's feature flag is OFF, the wizard treats
        // the step as satisfied for submit-validation purposes:
        //
        //   kyc_verification_enabled OFF       → KycStatus::NotRequired
        //                                        also satisfies the step
        //                                        (Q-flag-off-1 = (a))
        //   creator_payout_method_enabled OFF  → step is implicitly satisfied
        //                                        (no column sentinel — the
        //                                        absence of payout_method_set
        //                                        is fine when the operator
        //                                        has skipped payout entirely)
        //   contract_signing_enabled OFF       → signed_master_contract_id
        //                                        non-null also satisfies
        //                                        (Q-flag-off-2 = (a))
        //
        // The flag-on path stays the strict check on the column. This
        // matters for forensic clarity: a `kyc_status='not_required'`
        // creator's row tells you "operator-bypassed", while a
        // `kyc_status='verified'` creator's row tells you "vendor-cleared",
        // even after the flag is later flipped on or off.
        $kycSatisfied = $creator->kyc_status === KycStatus::Verified
            || $creator->kyc_status === KycStatus::NotRequired
            || ! Feature::active(KycVerificationEnabled::NAME);

        $payoutSatisfied = $creator->payout_method_set === true
            || ! Feature::active(CreatorPayoutMethodEnabled::NAME);

        // Sprint 4 Chunk 4 (D-c4-3): the `contracts` row is the source of
        // truth for "contract step satisfied" — satisfaction keys off
        // `signed_master_contract_id` only. The click-through path now sets
        // that FK (to a real contracts.id) alongside the denormalised
        // `click_through_accepted_at`, so the legacy timestamp clause is no
        // longer load-bearing and has been removed: a creator with the FK
        // set but no timestamp passes; one with neither fails.
        $contractSatisfied = $creator->signed_master_contract_id !== null
            || ! Feature::active(ContractSigningEnabled::NAME);

        return [
            WizardStep::Profile->value => $this->isProfileComplete($creator),
            WizardStep::Social->value => $creator->socialAccounts()->exists(),
            WizardStep::Portfolio->value => $creator->portfolioItems()->exists(),
            WizardStep::Kyc->value => $kycSatisfied,
            WizardStep::Tax->value => $creator->tax_profile_complete === true,
            WizardStep::Payout->value => $payoutSatisfied,
            WizardStep::Contract->value => $contractSatisfied,
        ];
    }

    /**
     * The first step in WizardStep::ordered() that is incomplete, or
     * `WizardStep::Review` if the creator has finished all seven
     * substantive steps. Used by the bootstrap response's `next_step`.
     */
    public function nextStep(Creator $creator): WizardStep
    {
        $completion = $this->stepCompletion($creator);

        foreach (WizardStep::ordered() as $step) {
            if ($step === WizardStep::Review) {
                continue;
            }

            if (($completion[$step->value] ?? false) === false) {
                return $step;
            }
        }

        return WizardStep::Review;
    }

    /**
     * The subset of weighted steps that count toward the score in the
     * current environment. Vendor-gated steps drop out when their
     * feature flag is OFF (see {@see score()} for the renormalisation
     * rationale). Non-gated steps (profile / social / portfolio / tax)
     * always apply.
     *
     * Kept in lockstep with {@see stepCompletion()}'s flag-OFF branches
     * and the SPA's `resolveStepStatus` / `FLAG_BY_STEP` maps.
     *
     * @return list<string>
     */
    private function applicableSteps(): array
    {
        $gatedFlagByStep = [
            WizardStep::Kyc->value => KycVerificationEnabled::NAME,
            WizardStep::Payout->value => CreatorPayoutMethodEnabled::NAME,
            WizardStep::Contract->value => ContractSigningEnabled::NAME,
        ];

        $applicable = [];

        foreach (array_keys($this->weights()) as $step) {
            $flag = $gatedFlagByStep[$step] ?? null;

            if ($flag !== null && ! Feature::active($flag)) {
                continue;
            }

            $applicable[] = $step;
        }

        return $applicable;
    }

    private function isProfileComplete(Creator $creator): bool
    {
        // Categories is the discriminating field — a creator can fill
        // every other text field but if they haven't picked at least one
        // category we can't surface them on agency search. Avatar is
        // included to incentivise upload.
        return $creator->display_name !== null
            && $creator->country_code !== null
            && $creator->primary_language !== null
            && is_array($creator->categories) && count($creator->categories) > 0
            && $creator->avatar_path !== null;
    }
}
