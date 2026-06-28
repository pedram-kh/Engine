/**
 * Read-only accessor for the wizard-relevant feature flags exposed
 * by the backend in `CreatorResource.wizard.flags` (Sprint 3 Chunk 3
 * sub-step 1).
 *
 * The SPA does NOT evaluate Pennant directly — operators flip the
 * Phase-1 flags server-side and the next bootstrap call reflects
 * the change (`docs/feature-flags.md`). This composable just reads
 * the cached state from the onboarding store.
 *
 * Each flag exposes:
 *   - The boolean state itself.
 *   - A `skipExplanationKey` — the i18n key for the "this step is
 *     not required at this time" sentence the consumer shows in
 *     place of the disabled step's body (Q-flag-off-1 = (a)
 *     "Skipped-with-explanation").
 *
 * Decision E1=a: when a flag is OFF, the corresponding wizard step
 * is shown with a "Skipped" badge and an explanation. The step's
 * `is_complete` is true server-side so navigation is unblocked.
 */

import { computed, type ComputedRef } from 'vue'

import type { CreatorWizardFlags, CreatorWizardStepId } from '@catalyst/api-client'

import { useOnboardingStore } from '../stores/useOnboardingStore'

export interface FlagState {
  /** Operator state — true ⇒ step engages the provider, false ⇒ skipped. */
  enabled: boolean
  /** i18n key for the off-state explanation visible to the creator. */
  skipExplanationKey: string
}

export interface FeatureFlagsHandle {
  kyc: ComputedRef<FlagState>
  payout: ComputedRef<FlagState>
  contract: ComputedRef<FlagState>
}

/**
 * The three statuses a wizard row can be in on the review surface.
 *
 *   - `completed`   — server marked `is_complete: true` AND the step
 *                     was vendor-cleared (creator actually did the
 *                     work). The forensic distinction in the
 *                     `CompletenessScoreCalculator` docblock. The
 *                     contract step ALSO reads "completed" when the
 *                     creator accepts the master agreement via the
 *                     flag-OFF click-through (real work was performed),
 *                     mirroring the backend score (AH-004).
 *   - `skipped`     — server marked `is_complete: true` BUT the
 *                     feature flag is OFF and no creator action was
 *                     taken (kyc / payout, or contract not yet
 *                     accepted). Satisfied for submit-validation but
 *                     no work was performed.
 *   - `not-started` — server marked `is_complete: false`. Blocks submit.
 */
export type WizardStepStatus = 'completed' | 'skipped' | 'not-started'

/**
 * Map a wizard step to the feature flag whose OFF state turns the
 * step's `is_complete` into a "skipped" pseudo-completion. Kept in
 * lockstep with the backend's
 * `CompletenessScoreCalculator::stepCompletion()` flag-OFF branches
 * and with the {@link OnboardingProgress} component's `FLAG_BY_STEP`
 * map. Adding a new flag-gated step requires touching all three.
 */
const FLAG_BY_STEP: Partial<Record<CreatorWizardStepId, keyof CreatorWizardFlags>> = {
  kyc: 'kyc_verification_enabled',
  payout: 'creator_payout_method_enabled',
  contract: 'contract_signing_enabled',
}

/**
 * Resolve the visible status of a wizard row from the backend-supplied
 * `is_complete` boolean + the feature-flag state from the same
 * bootstrap response. Pure function — no reactivity, easy to test.
 *
 * @param stepId       Wizard step identifier.
 * @param isComplete   `creator.wizard.steps[i].is_complete`.
 * @param flags        `creator.wizard.flags`, or null on a fresh
 *                     boot before bootstrap has resolved.
 * @param contractAccepted Whether the master agreement was actually
 *                     accepted (`click_through_accepted_at` set). Only
 *                     meaningful for the contract step on the flag-OFF
 *                     path: an accepted agreement reads "completed"
 *                     rather than "skipped". Ignored for every other
 *                     step (kyc / payout have no creator-side fallback
 *                     action, so they remain "skipped").
 */
export function resolveStepStatus(
  stepId: CreatorWizardStepId,
  isComplete: boolean,
  flags: CreatorWizardFlags | null,
  contractAccepted = false,
): WizardStepStatus {
  const flagKey = FLAG_BY_STEP[stepId]
  const isFlagOff = flagKey !== undefined && flags !== null && flags[flagKey] === false
  if (isFlagOff && isComplete) {
    // Contract is the exception to the flag-OFF "skipped" rule: the
    // creator still accepts the master agreement via the click-through,
    // so an accepted agreement is genuine work and reads "completed"
    // (the backend score credits the contract weight on the same signal).
    if (stepId === 'contract' && contractAccepted) return 'completed'
    return 'skipped'
  }
  if (isComplete) return 'completed'
  return 'not-started'
}

export function useFeatureFlags(): FeatureFlagsHandle {
  const store = useOnboardingStore()

  const kyc = computed<FlagState>(() => ({
    enabled: store.flags?.kyc_verification_enabled ?? false,
    skipExplanationKey: 'creator.ui.wizard.steps.kyc.skipped_explanation',
  }))

  const payout = computed<FlagState>(() => ({
    enabled: store.flags?.creator_payout_method_enabled ?? false,
    skipExplanationKey: 'creator.ui.wizard.steps.payout.skipped_explanation',
  }))

  const contract = computed<FlagState>(() => ({
    enabled: store.flags?.contract_signing_enabled ?? false,
    skipExplanationKey: 'creator.ui.wizard.steps.contract.skipped_explanation',
  }))

  return { kyc, payout, contract }
}
