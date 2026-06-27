/**
 * Wizard step registry — the single runtime source of truth for which
 * onboarding steps are BUILD-TIME HIDDEN, shared by the SPA and mirrored
 * on the backend.
 *
 * {@link WIZARD_HIDDEN_STEPS} mirrors the PHP
 * `App\Modules\Creators\Enums\WizardStep::WIZARD_HIDDEN_STEPS` constant.
 * A hidden step is not rendered in the wizard and is excluded from the
 * rail, the step numbering, the completeness denominator, and the submit
 * gate (the backend drops it from `wizard.steps[]` and the completion map).
 *
 * This is a build-time "not ready yet" hide, deliberately NOT a Pennant
 * feature flag: a flag implies runtime / per-tenant toggling, whereas the
 * correct semantic here is "the platform cannot collect this yet" — it
 * flips when Sprint 10 (payments) + automated KYC land. Re-introduction =
 * remove the step from this list (and, for kyc / payout, flip the
 * corresponding Pennant flag ON on the backend).
 *
 * The TS list and the PHP constant are held in lockstep by the TS<->PHP
 * parity architecture test (standing standard 5.25) in `wizard.spec.ts`.
 */

import type { CreatorWizardStepId } from './types/creator'

/**
 * The onboarding steps currently hidden at build time (ad-hoc AH-003):
 * Identity verification (kyc), Tax information (tax), Payout method
 * (payout). Mirrors the backend `WizardStep::WIZARD_HIDDEN_STEPS`.
 */
export const WIZARD_HIDDEN_STEPS: readonly CreatorWizardStepId[] = ['kyc', 'tax', 'payout']

/** Whether a wizard step is build-time hidden (see {@link WIZARD_HIDDEN_STEPS}). */
export function isWizardStepHidden(step: CreatorWizardStepId): boolean {
  return WIZARD_HIDDEN_STEPS.includes(step)
}
