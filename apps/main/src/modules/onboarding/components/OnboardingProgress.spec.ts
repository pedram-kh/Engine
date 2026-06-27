import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn() },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import OnboardingProgress from './OnboardingProgress.vue'

let teardown: (() => void) | null = null

function makeBootstrap(): never {
  return {
    data: {
      id: '01',
      type: 'creators',
      attributes: {
        display_name: 'Test',
        bio: null,
        country_code: null,
        region: null,
        primary_language: null,
        secondary_languages: null,
        categories: null,
        avatar_path: null,
        cover_path: null,
        avatar_url: null,
        cover_url: null,
        verification_level: 'unverified',
        application_status: 'incomplete',
        tier: null,
        kyc_status: 'none',
        kyc_verified_at: null,
        tax_profile_complete: false,
        payout_method_set: false,
        has_signed_master_contract: false,
        click_through_accepted_at: null,
        social_accounts: [],
        portfolio: [],
        profile_completeness_score: 0,
        submitted_at: null,
        approved_at: null,
        created_at: '2026-05-14T00:00:00+00:00',
        updated_at: '2026-05-14T00:00:00+00:00',
      },
      wizard: {
        next_step: 'profile',
        is_submitted: false,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: true,
          creator_payout_method_enabled: true,
          contract_signing_enabled: true,
        },
      },
    },
  } as never
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap())
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('OnboardingProgress', () => {
  // The implicit Step 1 = sign-up is rendered as a non-navigable static
  // row so the numbering is self-consistent. Post-AH-003 the wizard has
  // 5 visible steps (account, profile, connections, contract, review),
  // so the static row reads "Step 1 of 5".
  it('renders a static "Step 1 of 5 — Account created" row at the top', async () => {
    const { wrapper, unmount } = await mountAuthPage(OnboardingProgress, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const row = wrapper.find('[data-test="progress-step-account-created"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('Step 1 of 5')
    expect(row.text()).toContain('Account created')
    expect(row.text()).toContain('Completed')
    // The static row must NOT be a button — clicking it has no
    // meaningful destination (there's no in-wizard route to sign-up).
    expect(row.find('button').exists()).toBe(false)
  })

  it('renders the visible substantive steps (profile, merged connections, contract)', async () => {
    const { wrapper, unmount } = await mountAuthPage(OnboardingProgress, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    for (const id of ['profile', 'connections', 'contract']) {
      expect(wrapper.find(`[data-test="progress-step-${id}"]`).exists()).toBe(true)
    }
    // Social + portfolio are merged; kyc/tax/payout are build-time hidden.
    for (const id of ['social', 'portfolio', 'kyc', 'tax', 'payout']) {
      expect(wrapper.find(`[data-test="progress-step-${id}"]`).exists()).toBe(false)
    }
  })
})
