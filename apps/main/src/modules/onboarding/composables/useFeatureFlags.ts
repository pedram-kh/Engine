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
