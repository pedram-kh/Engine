import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    initiateKyc: vi.fn(),
    pollKycStatus: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step5KycPage from './Step5KycPage.vue'

let teardown: (() => void) | null = null

function makeBootstrap(opts: {
  kycEnabled: boolean
  kycStatus?: 'none' | 'pending' | 'verified' | 'rejected' | 'not_required'
}): never {
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
        verification_level: 'unverified',
        application_status: 'incomplete',
        tier: null,
        kyc_status: opts.kycStatus ?? 'none',
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
        next_step: 'kyc',
        is_submitted: false,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: opts.kycEnabled,
          creator_payout_method_enabled: true,
          contract_signing_enabled: true,
        },
      },
    },
  } as never
}

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('Step5KycPage', () => {
  it('flag ON + status=none shows the begin-verification CTA', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ kycEnabled: true, kycStatus: 'none' }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step5KycPage, {
      initialRoute: { path: '/onboarding/kyc' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="kyc-flag-on"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="kyc-begin"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="kyc-status-badge-none"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="kyc-advance"]').attributes('disabled')).toBeDefined()
  })

  it('flag OFF renders the skipped-with-explanation alert and enables advance', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ kycEnabled: false, kycStatus: 'none' }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step5KycPage, {
      initialRoute: { path: '/onboarding/kyc' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="kyc-flag-off"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="kyc-begin"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="kyc-advance"]').attributes('disabled')).toBeUndefined()
  })

  it('flag ON + status=verified enables advance and hides the begin CTA', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ kycEnabled: true, kycStatus: 'verified' }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step5KycPage, {
      initialRoute: { path: '/onboarding/kyc' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="kyc-status-badge-verified"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="kyc-begin"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="kyc-advance"]').attributes('disabled')).toBeUndefined()
  })

  it('begin-verification calls initiateKyc and navigates to the hosted URL', async () => {
    const originalLocation = window.location
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { href: '' },
    })

    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ kycEnabled: true, kycStatus: 'none' }),
    )
    vi.mocked(onboardingApi.initiateKyc).mockResolvedValue({
      data: {
        session_id: 'kyc-1',
        hosted_flow_url: 'https://vendor.example/kyc/session-1',
        expires_at: '2026-05-14T01:00:00+00:00',
      },
    })

    const { wrapper, unmount } = await mountAuthPage(Step5KycPage, {
      initialRoute: { path: '/onboarding/kyc' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    await wrapper.find('[data-testid="kyc-begin"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.initiateKyc).toHaveBeenCalledTimes(1)
    expect(window.location.href).toBe('https://vendor.example/kyc/session-1')

    Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
  })
})
