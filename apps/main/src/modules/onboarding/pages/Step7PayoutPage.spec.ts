import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    initiatePayout: vi.fn(),
    pollPayoutStatus: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step7PayoutPage from './Step7PayoutPage.vue'

let teardown: (() => void) | null = null

function makeBootstrap(opts: { payoutEnabled: boolean; payoutSet?: boolean }): never {
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
        kyc_status: 'verified',
        kyc_verified_at: null,
        tax_profile_complete: false,
        payout_method_set: opts.payoutSet ?? false,
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
        next_step: 'payout',
        is_submitted: false,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: true,
          creator_payout_method_enabled: opts.payoutEnabled,
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

describe('Step7PayoutPage', () => {
  it('flag ON + unset shows the begin-setup CTA and disabled advance', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ payoutEnabled: true, payoutSet: false }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step7PayoutPage, {
      initialRoute: { path: '/onboarding/payout' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="payout-flag-on"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payout-begin"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payout-method-status-unset"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payout-advance"]').attributes('disabled')).toBeDefined()
  })

  it('flag OFF renders the skipped alert and enables advance', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap({ payoutEnabled: false }))

    const { wrapper, unmount } = await mountAuthPage(Step7PayoutPage, {
      initialRoute: { path: '/onboarding/payout' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="payout-flag-off"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payout-advance"]').attributes('disabled')).toBeUndefined()
  })

  it('flag ON + payoutSet=true hides the begin CTA and enables advance', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ payoutEnabled: true, payoutSet: true }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step7PayoutPage, {
      initialRoute: { path: '/onboarding/payout' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="payout-method-status-set"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="payout-begin"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="payout-advance"]').attributes('disabled')).toBeUndefined()
  })

  it('begin-setup calls initiatePayout and navigates to the hosted URL', async () => {
    const originalLocation = window.location
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { href: '' },
    })

    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ payoutEnabled: true, payoutSet: false }),
    )
    vi.mocked(onboardingApi.initiatePayout).mockResolvedValue({
      data: {
        account_id: 'acct_1',
        onboarding_url: 'https://stripe.example/connect/onboarding-1',
        expires_at: '2026-05-14T01:00:00+00:00',
      },
    })

    const { wrapper, unmount } = await mountAuthPage(Step7PayoutPage, {
      initialRoute: { path: '/onboarding/payout' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    await wrapper.find('[data-testid="payout-begin"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.initiatePayout).toHaveBeenCalledTimes(1)
    expect(window.location.href).toBe('https://stripe.example/connect/onboarding-1')

    Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
  })
})
