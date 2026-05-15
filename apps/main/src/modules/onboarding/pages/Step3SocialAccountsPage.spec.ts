import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    connectSocial: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step3SocialAccountsPage from './Step3SocialAccountsPage.vue'

let teardown: (() => void) | null = null

function makeBootstrapWith(
  social: ReadonlyArray<{ platform: 'instagram' | 'tiktok' | 'youtube'; handle: string }>,
): never {
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
        kyc_status: 'none',
        kyc_verified_at: null,
        tax_profile_complete: false,
        payout_method_set: false,
        has_signed_master_contract: false,
        click_through_accepted_at: null,
        social_accounts: social.map((s) => ({
          platform: s.platform,
          handle: s.handle,
          profile_url: `https://${s.platform}.com/${s.handle}`,
          is_primary: false,
        })),
        portfolio: [],
        profile_completeness_score: 0,
        submitted_at: null,
        approved_at: null,
        created_at: '2026-05-14T00:00:00+00:00',
        updated_at: '2026-05-14T00:00:00+00:00',
      },
      wizard: {
        next_step: 'social',
        is_submitted: false,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: false,
          creator_payout_method_enabled: false,
          contract_signing_enabled: false,
        },
      },
    },
  } as never
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrapWith([]))
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('Step3SocialAccountsPage', () => {
  it('renders three per-platform forms', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step3SocialAccountsPage, {
      initialRoute: { path: '/onboarding/social' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="social-form-instagram"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-form-tiktok"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-form-youtube"]').exists()).toBe(true)
  })

  it('disables the advance button when no accounts are connected', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step3SocialAccountsPage, {
      initialRoute: { path: '/onboarding/social' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const advance = wrapper.find('[data-testid="social-advance"]')
    expect(advance.attributes('disabled')).toBeDefined()
  })

  it('enables the advance button when at least one social account is connected', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrapWith([{ platform: 'instagram', handle: 'creator_x' }]),
    )
    const { wrapper, unmount } = await mountAuthPage(Step3SocialAccountsPage, {
      initialRoute: { path: '/onboarding/social' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const advance = wrapper.find('[data-testid="social-advance"]')
    expect(advance.attributes('disabled')).toBeUndefined()
  })

  it('renders connected accounts via the shared SocialAccountList', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrapWith([
        { platform: 'instagram', handle: 'creator_x' },
        { platform: 'tiktok', handle: 'creator_y' },
      ]),
    )
    const { wrapper, unmount } = await mountAuthPage(Step3SocialAccountsPage, {
      initialRoute: { path: '/onboarding/social' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="social-account-list"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-account-row-instagram"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-account-row-tiktok"]').exists()).toBe(true)
  })
})
