/**
 * Pinia store backing the creator onboarding wizard (Sprint 3 Chunk 3
 * sub-step 2).
 *
 * Scope contract (parallel to `useAuthStore`):
 *   - `creator` is the authoritative state. Every mutation route
 *     re-reads from `bootstrap()` to refresh the resource — the
 *     SPA does NOT derive wizard state locally, because the backend
 *     computes completeness + next_step + flag state.
 *   - Per-action loading flags drive form-disabled / loading UI.
 *
 * Decision B (Refinement 1 — session-vs-fresh hybrid):
 *   The signal "is this the wizard's first entrance in this tab" is
 *   tracked locally as {@link wasBootstrappedThisSession}, NOT
 *   piggybacked on `useAuthStore.bootstrapStatus`. Reasoning:
 *
 *     - `useAuthStore.bootstrapStatus` becomes `'ready'` on the very
 *       first router-guard-triggered bootstrap (i.e. before the user
 *       can reach the wizard route). It can NOT distinguish "fresh
 *       tab landed straight on the wizard" from "user has been
 *       navigating around for a while and now opens the wizard".
 *     - The Welcome Back decision needs precisely that distinction:
 *       fresh entrance ⇒ show Welcome Back, mid-session navigation ⇒
 *       auto-advance to `next_step`.
 *
 *   The flag flips to `true` after the FIRST `bootstrap()` call in
 *   this Pinia singleton's lifetime. The singleton is constructed
 *   once per SPA tab (Pinia's module-scoped contract); a fresh tab
 *   resets the flag to false on next mount. A sign-out + sign-in
 *   in the same tab resets the auth store's `bootstrapStatus` to
 *   'idle' via `clearUser()`, but this onboarding flag persists —
 *   which is the right semantic: the same browser session has
 *   already engaged with the wizard once, so auto-advance is the
 *   continuous-flow choice on the second sign-in.
 *
 *   Defense-in-depth (#40) break-revert: temporarily setting
 *   `wasBootstrappedThisSession` to always-`true` makes the
 *   "fresh-load Welcome Back" spec fail, which is the regression
 *   mode this flag exists to catch. See
 *   `useOnboardingStore.spec.ts`.
 */

import { ApiError } from '@catalyst/api-client'
import type {
  ContractInitiateResponse,
  CreatorProfileUpdatePayload,
  CreatorResource,
  CreatorSocialConnectPayload,
  CreatorTaxUpdatePayload,
  CreatorWizardStepId,
  KycInitiateResponse,
  PayoutInitiateResponse,
} from '@catalyst/api-client'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { onboardingApi } from '../api/onboarding.api'

export type OnboardingBootstrapStatus = 'idle' | 'loading' | 'ready' | 'error'

export const useOnboardingStore = defineStore('onboarding', () => {
  // ---------------------------------------------------------------
  // State
  // ---------------------------------------------------------------
  const creator = ref<CreatorResource | null>(null)
  const bootstrapStatus = ref<OnboardingBootstrapStatus>('idle')

  /**
   * Decision B signal — see docblock at the top of this file.
   * Flips to `true` after the first successful `bootstrap()` call.
   * Used by `OnboardingLayout` / `WelcomeBackPage` to branch.
   */
  const wasBootstrappedThisSession = ref(false)

  const isLoadingProfile = ref(false)
  const isLoadingSocial = ref(false)
  const isLoadingKyc = ref(false)
  const isLoadingTax = ref(false)
  const isLoadingPayout = ref(false)
  const isLoadingContract = ref(false)
  const isLoadingClickThrough = ref(false)
  const isSubmitting = ref(false)
  const isUploadingAvatar = ref(false)
  const isLoadingPortfolio = ref(false)

  // Dedupe cache for concurrent bootstrap() calls.
  let inFlightBootstrap: Promise<void> | null = null

  // ---------------------------------------------------------------
  // Getters
  // ---------------------------------------------------------------

  const isBootstrapped = computed(() => bootstrapStatus.value === 'ready' && creator.value !== null)

  const nextStep = computed<CreatorWizardStepId | null>(
    () => creator.value?.wizard.next_step ?? null,
  )

  const isSubmitted = computed(() => creator.value?.wizard.is_submitted ?? false)

  const stepCompletion = computed<Record<CreatorWizardStepId, boolean>>(() => {
    const empty: Record<CreatorWizardStepId, boolean> = {
      profile: false,
      social: false,
      portfolio: false,
      kyc: false,
      tax: false,
      payout: false,
      contract: false,
      review: false,
    }
    if (creator.value === null) {
      return empty
    }
    const out = { ...empty }
    for (const step of creator.value.wizard.steps) {
      out[step.id] = step.is_complete
    }
    return out
  })

  const flags = computed(() => creator.value?.wizard.flags ?? null)

  const completenessScore = computed(
    () => creator.value?.attributes.profile_completeness_score ?? 0,
  )

  const lastActivityAt = computed(() => creator.value?.attributes.updated_at ?? null)

  const applicationStatus = computed(() => creator.value?.attributes.application_status ?? null)

  // ---------------------------------------------------------------
  // Actions
  // ---------------------------------------------------------------

  async function bootstrap(): Promise<void> {
    if (inFlightBootstrap !== null) {
      return inFlightBootstrap
    }

    bootstrapStatus.value = 'loading'

    inFlightBootstrap = (async (): Promise<void> => {
      try {
        const envelope = await onboardingApi.bootstrap()
        creator.value = envelope.data
        bootstrapStatus.value = 'ready'
        wasBootstrappedThisSession.value = true
      } catch (error) {
        bootstrapStatus.value = 'error'
        // 404 creator.not_found → the SPA-level error layer surfaces
        // the i18n'd `creator.not_found` message. The store keeps
        // `creator = null` and signals the error via bootstrapStatus.
        if (!(error instanceof ApiError)) {
          throw error
        }
        throw error
      } finally {
        inFlightBootstrap = null
      }
    })()

    return inFlightBootstrap
  }

  /**
   * Reset the bootstrap state. Used by sign-out (router pop the
   * onboarding mount → next sign-in re-bootstraps). The
   * `wasBootstrappedThisSession` flag is intentionally NOT reset
   * here — that signal is tab-scoped and persists across
   * sign-out/sign-in within the same tab, which is the right
   * semantic for the Welcome Back decision.
   */
  function reset(): void {
    creator.value = null
    bootstrapStatus.value = 'idle'
  }

  async function updateProfile(payload: CreatorProfileUpdatePayload): Promise<void> {
    isLoadingProfile.value = true
    try {
      const envelope = await onboardingApi.updateProfile(payload)
      creator.value = envelope.data
    } finally {
      isLoadingProfile.value = false
    }
  }

  async function connectSocial(payload: CreatorSocialConnectPayload): Promise<void> {
    isLoadingSocial.value = true
    try {
      const envelope = await onboardingApi.connectSocial(payload)
      creator.value = envelope.data
    } finally {
      isLoadingSocial.value = false
    }
  }

  async function updateTax(payload: CreatorTaxUpdatePayload): Promise<void> {
    isLoadingTax.value = true
    try {
      const envelope = await onboardingApi.updateTax(payload)
      creator.value = envelope.data
    } finally {
      isLoadingTax.value = false
    }
  }

  /**
   * Initiate the vendor-bounce for the KYC saga. The response
   * carries a `hosted_flow_url` the consumer (Step5KycPage) is
   * expected to navigate to via `window.location.href`. The store
   * holds the loading flag during the round-trip so the button
   * surface can disable itself.
   */
  async function initiateKyc(): Promise<KycInitiateResponse> {
    isLoadingKyc.value = true
    try {
      return await onboardingApi.initiateKyc()
    } finally {
      isLoadingKyc.value = false
    }
  }

  async function initiatePayout(): Promise<PayoutInitiateResponse> {
    isLoadingPayout.value = true
    try {
      return await onboardingApi.initiatePayout()
    } finally {
      isLoadingPayout.value = false
    }
  }

  async function initiateContract(): Promise<ContractInitiateResponse> {
    isLoadingContract.value = true
    try {
      return await onboardingApi.initiateContract()
    } finally {
      isLoadingContract.value = false
    }
  }

  async function clickThroughAcceptContract(): Promise<void> {
    isLoadingClickThrough.value = true
    try {
      const envelope = await onboardingApi.clickThroughAccept()
      creator.value = envelope.data
    } finally {
      isLoadingClickThrough.value = false
    }
  }

  /**
   * Poll the KYC saga status. The endpoint returns
   * `{status, transitioned}`; if transitioned to a terminal state,
   * refresh the creator via bootstrap() so flags + step completion
   * mirror the new state. Returns the raw status payload so the
   * caller (typically {@link useVendorBounce}) can drive its
   * polling state machine.
   */
  async function pollKycStatus(): Promise<{ status: string; transitioned: boolean }> {
    isLoadingKyc.value = true
    try {
      const response = await onboardingApi.pollKycStatus()
      if (response.data.transitioned) {
        await bootstrapRefresh()
      }
      return response.data
    } finally {
      isLoadingKyc.value = false
    }
  }

  async function pollPayoutStatus(): Promise<{ status: string; transitioned: boolean }> {
    isLoadingPayout.value = true
    try {
      const response = await onboardingApi.pollPayoutStatus()
      if (response.data.transitioned) {
        await bootstrapRefresh()
      }
      return response.data
    } finally {
      isLoadingPayout.value = false
    }
  }

  async function pollContractStatus(): Promise<{ status: string; transitioned: boolean }> {
    isLoadingContract.value = true
    try {
      const response = await onboardingApi.pollContractStatus()
      if (response.data.transitioned) {
        await bootstrapRefresh()
      }
      return response.data
    } finally {
      isLoadingContract.value = false
    }
  }

  /**
   * Re-issue a bootstrap that bypasses the dedupe cache so a
   * transition-edge poll always sees fresh creator state — the
   * in-flight dedupe is a hot-path optimisation, not the right
   * semantic for "the backend just changed state, fetch again".
   */
  async function bootstrapRefresh(): Promise<void> {
    inFlightBootstrap = null
    await bootstrap()
  }

  async function submit(): Promise<void> {
    isSubmitting.value = true
    try {
      const envelope = await onboardingApi.submit()
      creator.value = envelope.data
    } finally {
      isSubmitting.value = false
    }
  }

  async function uploadAvatar(file: File): Promise<void> {
    isUploadingAvatar.value = true
    try {
      const envelope = await onboardingApi.uploadAvatar(file)
      creator.value = envelope.data
    } finally {
      isUploadingAvatar.value = false
    }
  }

  async function deleteAvatar(): Promise<void> {
    isUploadingAvatar.value = true
    try {
      const envelope = await onboardingApi.deleteAvatar()
      creator.value = envelope.data
    } finally {
      isUploadingAvatar.value = false
    }
  }

  /**
   * Delete a portfolio item by its ULID and re-bootstrap so the
   * `creator.attributes.portfolio` array reflects the deletion.
   * Used by `Step4PortfolioPage` (Sprint 3 Chunk 3 sub-step 6).
   *
   * The backend's DELETE is idempotent on a 404 (returns the same
   * 404 envelope shape) — this wrapper does NOT swallow the error
   * because the UI surface needs to surface a "not found" or
   * "rate limited" hint via the i18n'd `ApiError.code`.
   */
  async function removePortfolioItem(itemUlid: string): Promise<void> {
    isLoadingPortfolio.value = true
    try {
      await onboardingApi.deletePortfolioItem(itemUlid)
      await bootstrapRefresh()
    } finally {
      isLoadingPortfolio.value = false
    }
  }

  return {
    // state
    creator,
    bootstrapStatus,
    wasBootstrappedThisSession,
    isLoadingProfile,
    isLoadingSocial,
    isLoadingKyc,
    isLoadingTax,
    isLoadingPayout,
    isLoadingContract,
    isLoadingClickThrough,
    isSubmitting,
    isUploadingAvatar,
    isLoadingPortfolio,
    // getters
    isBootstrapped,
    nextStep,
    isSubmitted,
    stepCompletion,
    flags,
    completenessScore,
    lastActivityAt,
    applicationStatus,
    // actions
    bootstrap,
    reset,
    updateProfile,
    connectSocial,
    updateTax,
    initiateKyc,
    initiatePayout,
    initiateContract,
    clickThroughAcceptContract,
    pollKycStatus,
    pollPayoutStatus,
    pollContractStatus,
    submit,
    uploadAvatar,
    deleteAvatar,
    removePortfolioItem,
  }
})
