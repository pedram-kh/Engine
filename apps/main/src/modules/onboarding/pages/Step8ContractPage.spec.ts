import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    initiateContract: vi.fn(),
    pollContractStatus: vi.fn(),
    getContractTerms: vi.fn(),
    clickThroughAccept: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step8ContractPage from './Step8ContractPage.vue'

let teardown: (() => void) | null = null

function makeBootstrap(opts: {
  contractEnabled: boolean
  hasSigned?: boolean
  clickThroughAcceptedAt?: string | null
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
        kyc_status: 'verified',
        kyc_verified_at: null,
        tax_profile_complete: false,
        payout_method_set: false,
        has_signed_master_contract: opts.hasSigned ?? false,
        click_through_accepted_at: opts.clickThroughAcceptedAt ?? null,
        social_accounts: [],
        portfolio: [],
        profile_completeness_score: 0,
        submitted_at: null,
        approved_at: null,
        created_at: '2026-05-14T00:00:00+00:00',
        updated_at: '2026-05-14T00:00:00+00:00',
      },
      wizard: {
        next_step: 'contract',
        is_submitted: false,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: true,
          creator_payout_method_enabled: true,
          contract_signing_enabled: opts.contractEnabled,
        },
      },
    },
  } as never
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.getContractTerms).mockResolvedValue({
    data: {
      version: '1.0.0',
      locale: 'en',
      html: '<p>Master agreement terms.</p>',
    },
  })
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('Step8ContractPage', () => {
  it('flag ON + nothing signed shows the begin-sign CTA and disabled advance', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ contractEnabled: true, hasSigned: false }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step8ContractPage, {
      initialRoute: { path: '/onboarding/contract' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="contract-flag-on"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="contract-begin"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="contract-status-badge-none"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="contract-advance"]').attributes('disabled')).toBeDefined()
  })

  it('flag ON + hasSigned=true enables advance and renders the signed badge', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ contractEnabled: true, hasSigned: true }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step8ContractPage, {
      initialRoute: { path: '/onboarding/contract' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="contract-status-badge-signed"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="contract-begin"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="contract-advance"]').attributes('disabled')).toBeUndefined()
  })

  it('flag OFF renders the click-through accept surface', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap({ contractEnabled: false }))

    const { wrapper, unmount } = await mountAuthPage(Step8ContractPage, {
      initialRoute: { path: '/onboarding/contract' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="contract-flag-off"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="click-through-accept"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="contract-begin"]').exists()).toBe(false)
  })

  it('flag ON + click_through_accepted_at set still treats the step as complete', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({
        contractEnabled: true,
        hasSigned: false,
        clickThroughAcceptedAt: '2026-05-14T00:30:00+00:00',
      }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step8ContractPage, {
      initialRoute: { path: '/onboarding/contract' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(
      wrapper.find('[data-testid="contract-status-badge-click_through_accepted"]').exists(),
    ).toBe(true)
    expect(wrapper.find('[data-testid="contract-advance"]').attributes('disabled')).toBeUndefined()
  })

  it('begin-sign calls initiateContract and navigates to the signing URL', async () => {
    const originalLocation = window.location
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { href: '' },
    })

    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({ contractEnabled: true, hasSigned: false }),
    )
    vi.mocked(onboardingApi.initiateContract).mockResolvedValue({
      data: {
        envelope_id: 'env_1',
        signing_url: 'https://esign.example/sign/env-1',
        expires_at: '2026-05-14T01:00:00+00:00',
      },
    })

    const { wrapper, unmount } = await mountAuthPage(Step8ContractPage, {
      initialRoute: { path: '/onboarding/contract' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    await wrapper.find('[data-testid="contract-begin"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.initiateContract).toHaveBeenCalledTimes(1)
    expect(window.location.href).toBe('https://esign.example/sign/env-1')

    Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
  })
})
