<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Wizard step identifiers used in the GET /api/v1/creators/me bootstrap
 * response (the next_step hint and the per-step completion map).
 *
 * Step 1 (account creation) is NOT in this enum — it's handled by the
 * Identity module's sign-up endpoint and is "complete" by definition the
 * moment the creator can authenticate.
 *
 * Q2 (resume UX): the bootstrap response keys per-step status by these
 * identifiers (not by step number) so the order can change without
 * breaking the API contract. The frontend (Chunk 3) decides whether to
 * auto-advance, show an intermediate page, or hybrid.
 *
 * Backend step ordering is:
 *   profile → social → portfolio → kyc → tax → payout → contract →
 *   review (the submit action)
 *
 * NOTE (ad-hoc AH-003): kyc / tax / payout are currently BUILD-TIME
 * HIDDEN (see {@see self::WIZARD_HIDDEN_STEPS}). The SPA merges social +
 * portfolio into a single "connections" step for presentation, but the
 * backend keeps them as distinct completion units (their APIs and weights
 * are unchanged) — the merge is purely a frontend concern.
 */
enum WizardStep: string
{
    case Profile = 'profile';
    case Social = 'social';
    case Portfolio = 'portfolio';
    case Kyc = 'kyc';
    case Tax = 'tax';
    case Payout = 'payout';
    case Contract = 'contract';
    case Review = 'review';

    /**
     * Build-time "not ready yet" hidden steps (ad-hoc AH-003). A hidden
     * step is NOT rendered in the wizard and is excluded from the rail,
     * the step numbering, the completeness denominator, and the submit
     * gate. Hidden takes precedence over the Pennant skip-path: the
     * KYC / payout flags still govern skip-vs-active for VISIBLE steps,
     * but a step in this list is removed from the flow entirely.
     *
     * This is a build-time hide, deliberately NOT a Pennant feature flag:
     * a flag implies runtime / per-tenant toggling, whereas the correct
     * semantic here is "the platform cannot collect this yet" — it flips
     * when Sprint 10 (payments) + automated KYC land. Re-introduction =
     * remove the step from this list (and, for kyc / payout, flip the
     * corresponding Pennant flag ON).
     *
     * Re-introduction obligation (recorded, not built): creators who
     * onboard during the hidden window have no tax profile, and tax is
     * legally required before a first payout — so Sprint 10 must collect
     * tax from those creators (a backfill) before anyone is paid. See
     * docs/tech-debt.md "Hidden onboarding steps (kyc/tax/payout)".
     *
     * Held in lockstep with the TS `WIZARD_HIDDEN_STEPS` registry in
     * packages/api-client by the TS<->PHP parity architecture test
     * (standing standard 5.25).
     *
     * @var list<string>
     */
    public const array WIZARD_HIDDEN_STEPS = ['kyc', 'tax', 'payout'];

    /**
     * Wizard steps in display order. Used by the bootstrap response and
     * by CompletenessScoreCalculator's source-inspection regression test.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Profile,
            self::Social,
            self::Portfolio,
            self::Kyc,
            self::Tax,
            self::Payout,
            self::Contract,
            self::Review,
        ];
    }

    /**
     * Whether this step is build-time hidden (see {@see self::WIZARD_HIDDEN_STEPS}).
     */
    public function isHidden(): bool
    {
        return in_array($this->value, self::WIZARD_HIDDEN_STEPS, true);
    }

    /**
     * {@see self::ordered()} minus the build-time hidden steps. This is
     * the set the wizard actually surfaces (review still included as the
     * submit action). Reversible: shrink {@see self::WIZARD_HIDDEN_STEPS}
     * and a step reappears here with no other change.
     *
     * @return list<self>
     */
    public static function visibleOrdered(): array
    {
        return array_values(array_filter(
            self::ordered(),
            static fn (self $step): bool => ! $step->isHidden(),
        ));
    }
}
