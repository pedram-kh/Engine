import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn() },
}))

import { useOnboardingStore } from '../stores/useOnboardingStore'
import { resolveStepStatus, useFeatureFlags } from './useFeatureFlags'

beforeEach(() => {
  setActivePinia(createPinia())
})

function setFlags(flags: Partial<Record<string, boolean>>): void {
  const store = useOnboardingStore()
  store.creator = {
    id: '01',
    type: 'creators',
    attributes: {} as never,
    wizard: {
      next_step: 'profile',
      is_submitted: false,
      steps: [],
      weights: {},
      flags: {
        kyc_verification_enabled: flags.kyc_verification_enabled ?? false,
        creator_payout_method_enabled: flags.creator_payout_method_enabled ?? false,
        contract_signing_enabled: flags.contract_signing_enabled ?? false,
      },
    },
  } as never
}

describe('useFeatureFlags', () => {
  it('defaults to disabled when creator has not bootstrapped yet', () => {
    const { kyc, payout, contract } = useFeatureFlags()
    expect(kyc.value.enabled).toBe(false)
    expect(payout.value.enabled).toBe(false)
    expect(contract.value.enabled).toBe(false)
  })

  it('reflects the bootstrap flag state for each vendor', () => {
    setFlags({
      kyc_verification_enabled: true,
      creator_payout_method_enabled: false,
      contract_signing_enabled: true,
    })
    const { kyc, payout, contract } = useFeatureFlags()
    expect(kyc.value.enabled).toBe(true)
    expect(payout.value.enabled).toBe(false)
    expect(contract.value.enabled).toBe(true)
  })

  it('exposes per-step skipExplanationKey i18n keys', () => {
    setFlags({})
    const { kyc, payout, contract } = useFeatureFlags()
    expect(kyc.value.skipExplanationKey).toBe('creator.ui.wizard.steps.kyc.skipped_explanation')
    expect(payout.value.skipExplanationKey).toBe(
      'creator.ui.wizard.steps.payout.skipped_explanation',
    )
    expect(contract.value.skipExplanationKey).toBe(
      'creator.ui.wizard.steps.contract.skipped_explanation',
    )
  })

  it('reacts to store mutations (computed dependency)', () => {
    setFlags({ kyc_verification_enabled: false })
    const { kyc } = useFeatureFlags()
    expect(kyc.value.enabled).toBe(false)
    setFlags({ kyc_verification_enabled: true })
    expect(kyc.value.enabled).toBe(true)
  })
})

// Sprint 3 stabilization (May 19, 2026): pure helper extracted from
// the OnboardingProgress component so the Review surface can apply
// the same status semantics (Completed / Skipped / Not started). The
// "skipped" branch is the subtle one — it requires BOTH `is_complete`
// AND the relevant feature flag being OFF; flipping the flag on but
// leaving is_complete unchanged should fall back to "completed".
describe('resolveStepStatus', () => {
  const flagsOn = {
    kyc_verification_enabled: true,
    creator_payout_method_enabled: true,
    contract_signing_enabled: true,
  }
  const flagsOff = {
    kyc_verification_enabled: false,
    creator_payout_method_enabled: false,
    contract_signing_enabled: false,
  }

  it('returns `not-started` when the step is incomplete regardless of flag state', () => {
    expect(resolveStepStatus('profile', false, flagsOn)).toBe('not-started')
    expect(resolveStepStatus('kyc', false, flagsOff)).toBe('not-started')
    expect(resolveStepStatus('tax', false, null)).toBe('not-started')
  })

  it('returns `completed` for non-flag-gated steps that are complete', () => {
    expect(resolveStepStatus('profile', true, flagsOn)).toBe('completed')
    expect(resolveStepStatus('social', true, flagsOff)).toBe('completed')
    expect(resolveStepStatus('portfolio', true, null)).toBe('completed')
    expect(resolveStepStatus('tax', true, flagsOn)).toBe('completed')
  })

  it('returns `skipped` for a flag-gated step when its flag is OFF and is_complete is true', () => {
    expect(resolveStepStatus('kyc', true, flagsOff)).toBe('skipped')
    expect(resolveStepStatus('payout', true, flagsOff)).toBe('skipped')
    // Contract with the agreement NOT yet accepted is still "skipped".
    expect(resolveStepStatus('contract', true, flagsOff)).toBe('skipped')
    expect(resolveStepStatus('contract', true, flagsOff, false)).toBe('skipped')
  })

  it('returns `completed` for a flag-OFF contract once the agreement is accepted (AH-004)', () => {
    // The click-through acceptance is genuine work, so it reads
    // "completed" — mirroring the backend score crediting the weight.
    expect(resolveStepStatus('contract', true, flagsOff, true)).toBe('completed')
    // The accepted flag is contract-specific: it must NOT promote kyc /
    // payout out of "skipped".
    expect(resolveStepStatus('kyc', true, flagsOff, true)).toBe('skipped')
    expect(resolveStepStatus('payout', true, flagsOff, true)).toBe('skipped')
  })

  it('returns `completed` for a flag-gated step when the flag is ON (vendor-cleared)', () => {
    // This is the chunk-2 forensic distinction: when the flag is on,
    // is_complete only flips true via real vendor clearance.
    expect(resolveStepStatus('kyc', true, flagsOn)).toBe('completed')
    expect(resolveStepStatus('payout', true, flagsOn)).toBe('completed')
    expect(resolveStepStatus('contract', true, flagsOn)).toBe('completed')
  })

  it('treats null flags as on-by-default (the fresh-boot case)', () => {
    // Until bootstrap resolves, the SPA has no flag info; assume the
    // strict path so flag-gated steps render "completed" rather than
    // mis-labelling them "skipped" before the truth lands.
    expect(resolveStepStatus('kyc', true, null)).toBe('completed')
    expect(resolveStepStatus('payout', true, null)).toBe('completed')
  })
})
