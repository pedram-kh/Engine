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
 *   profile      25  six-field floor (display_name + country + region +
 *                    primary_language + categories + avatar) worth 13, plus
 *                    per-optional-field credit (bio/accent/phone/whatsapp/
 *                    street/postal) totalling 12 (AH-026 D1 + D4). The unit
 *                    weight is still 25; only the numerator is now partial —
 *                    see {@see self::PROFILE_OPTIONAL_WEIGHTS} + {@see self::profileEarned()}.
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
     * D4 — the profile unit's 25 points split into the always-required
     * six-field floor + per-optional-field credit. The floor is a single
     * all-or-nothing block; each optional adds its points INDEPENDENTLY of
     * the floor (Q2 = award-regardless), so the completeness meter never
     * "lies" by refusing to move when a creator fills an optional field.
     *
     * INVARIANT (pinned by CompletenessScoreCalculatorTest): the floor
     * weight + the sum of the optional weights equals weights()[profile]
     * (25), so the profile unit's contribution to the denominator, the
     * sum-to-100 total, and every other unit's ratio are all unchanged.
     */
    public const int PROFILE_FLOOR_WEIGHT = 13;

    /**
     * Optional profile fields and their individual score credit, keyed by
     * the Creator attribute name so {@see self::profileEarned()} can read
     * them generically. "Filled" is trimmed-non-empty ({@see self::isFilled()})
     * — the SAME definition the FE uses, so BE-earned points never diverge
     * from any FE display on whitespace.
     *
     * @var array<string, int>
     */
    public const array PROFILE_OPTIONAL_WEIGHTS = [
        'bio' => 4,
        'accent' => 2,
        'phone' => 2,
        'whatsapp' => 2,
        'address_street' => 1,
        'address_postal_code' => 1,
    ];

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
     * is OFF AND that requires no creator action (KYC, payout) is
     * "skipped" (Decision E1=a) — it is excluded from BOTH the
     * numerator AND the denominator, so the percentage reflects only
     * the work the creator can actually do and starts at 0.
     *
     * The contract step is the deliberate exception: even when
     * `contract_signing_enabled` is OFF, the creator STILL performs an
     * action — they accept the master agreement via the click-through.
     * So contract always counts toward the score (numerator AND
     * denominator), and is credited only once the agreement is actually
     * accepted (`signed_master_contract_id` set — by a signature OR the
     * click-through tick; see {@see scoreCompletion()}). Ticking the
     * click-through therefore earns the contract weight exactly as a
     * full signature does, instead of being invisible to the score.
     */
    public function score(Creator $creator): int
    {
        $weights = $this->weights();
        $applicable = $this->applicableSteps();

        $earned = 0;
        $total = 0;

        foreach ($this->scoreCompletion($creator) as $step => $isComplete) {
            if (! isset($weights[$step]) || ! in_array($step, $applicable, true)) {
                continue;
            }

            $total += $weights[$step];

            // The profile unit earns PARTIAL credit (D4): the six-field floor
            // is worth PROFILE_FLOOR_WEIGHT and each filled optional field adds
            // its own points, independent of whether the floor is met. Every
            // other unit stays all-or-nothing on its boolean. The profile
            // unit's total weight (25) is unchanged, so the denominator, the
            // sum-to-100 total, and external unit ratios are untouched.
            if ($step === WizardStep::Profile->value) {
                $earned += $this->profileEarned($creator);
            } elseif ($isComplete) {
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

        $completion = [
            WizardStep::Profile->value => $this->isProfileComplete($creator),
            WizardStep::Social->value => $creator->socialAccounts()->exists(),
            WizardStep::Portfolio->value => $creator->portfolioItems()->exists(),
            WizardStep::Kyc->value => $kycSatisfied,
            WizardStep::Tax->value => $creator->tax_profile_complete === true,
            WizardStep::Payout->value => $payoutSatisfied,
            WizardStep::Contract->value => $contractSatisfied,
        ];

        // Build-time hidden steps (WIZARD_HIDDEN_STEPS, ad-hoc AH-003) are
        // not collected and MUST NOT gate submit or score — drop them from
        // the completion map entirely. In particular this is what stops the
        // always-required `tax_profile_complete` check from dead-locking
        // submit while the Tax step is hidden (Q1). Reversible: shrink the
        // list and the step's completion check reappears here untouched.
        return array_filter(
            $completion,
            static fn (string $step): bool => ! in_array($step, WizardStep::WIZARD_HIDDEN_STEPS, true),
            ARRAY_FILTER_USE_KEY,
        );
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
            // Review is the submit action, not a navigable step; hidden
            // steps (WIZARD_HIDDEN_STEPS) are not part of the flow.
            if ($step === WizardStep::Review || $step->isHidden()) {
                continue;
            }

            if (($completion[$step->value] ?? false) === false) {
                return $step;
            }
        }

        return WizardStep::Review;
    }

    /**
     * The profile unit's earned score (0–25): the floor block plus each
     * filled optional field's credit. Optional credit is awarded
     * INDEPENDENTLY of the floor (Q2) — a creator below the floor still
     * sees the meter move as they fill optionals; a creator at the floor
     * with no optionals earns exactly PROFILE_FLOOR_WEIGHT (13). This is the
     * numerator half of D4's gate/score separation: the boolean in
     * {@see self::stepCompletion()} still gates on the floor alone.
     */
    public function profileEarned(Creator $creator): int
    {
        $earned = $this->isProfileComplete($creator) ? self::PROFILE_FLOOR_WEIGHT : 0;

        foreach (self::PROFILE_OPTIONAL_WEIGHTS as $field => $weight) {
            if ($this->isFilled($creator->{$field})) {
                $earned += $weight;
            }
        }

        return $earned;
    }

    /**
     * Per-step completion as it counts TOWARD THE SCORE.
     *
     * Identical to {@see stepCompletion()} except for the contract step:
     * {@see stepCompletion()} auto-satisfies contract whenever
     * `contract_signing_enabled` is OFF (so submit-validation never
     * dead-locks on a vendor flow the operator has turned off). For the
     * score, however, the contract weight must be EARNED by actually
     * accepting the agreement — a signature or the click-through tick,
     * both of which set `signed_master_contract_id`. Otherwise a
     * flag-OFF creator who never accepted would be handed the contract
     * weight for free, which defeats the "the tick scores like signing"
     * intent. Flag-ON behaviour is identical between the two maps.
     *
     * @return array<string, bool>
     */
    private function scoreCompletion(Creator $creator): array
    {
        $completion = $this->stepCompletion($creator);

        if (array_key_exists(WizardStep::Contract->value, $completion)) {
            $completion[WizardStep::Contract->value] = $creator->signed_master_contract_id !== null;
        }

        return $completion;
    }

    /**
     * The subset of weighted steps that count toward the score in the
     * current environment. The no-action vendor-gated steps (KYC,
     * payout) drop out when their feature flag is OFF (see {@see score()}
     * for the renormalisation rationale). Non-gated steps (profile /
     * social / portfolio / tax) always apply.
     *
     * Contract is intentionally NOT gated out here: even with
     * `contract_signing_enabled` OFF the creator still accepts the
     * agreement via the click-through, so it remains in the denominator
     * and is credited once accepted (see {@see scoreCompletion()}).
     *
     * Kept in lockstep with {@see scoreCompletion()} and the SPA's
     * `resolveStepStatus` / `FLAG_BY_STEP` maps.
     *
     * @return list<string>
     */
    private function applicableSteps(): array
    {
        $gatedFlagByStep = [
            WizardStep::Kyc->value => KycVerificationEnabled::NAME,
            WizardStep::Payout->value => CreatorPayoutMethodEnabled::NAME,
        ];

        $applicable = [];

        foreach (array_keys($this->weights()) as $step) {
            // Build-time hidden steps (WIZARD_HIDDEN_STEPS) drop out of the
            // denominator entirely so the percentage reflects only the
            // steps the creator can actually see and complete.
            if (in_array($step, WizardStep::WIZARD_HIDDEN_STEPS, true)) {
                continue;
            }

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
        // included to incentivise upload. Region joined the floor (AH-026 D1):
        // the six-field floor is mirrored 1:1 by the FE `floorMet` in
        // ProfileBasicsForm.vue, pinned by the floor-mirror parity spec.
        return $creator->display_name !== null
            && $creator->country_code !== null
            && $creator->region !== null
            && $creator->primary_language !== null
            && is_array($creator->categories) && count($creator->categories) > 0
            && $creator->avatar_path !== null;
    }

    /**
     * A nullable string attribute counts as "filled" only when it is
     * non-null AND non-empty after trimming — mirrors the FE's trimmed-
     * non-empty rule so the two layers agree on whitespace.
     */
    private function isFilled(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
