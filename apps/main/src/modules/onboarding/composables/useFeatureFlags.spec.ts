import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn() },
}))

import { useOnboardingStore } from '../stores/useOnboardingStore'
import { useFeatureFlags } from './useFeatureFlags'

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
