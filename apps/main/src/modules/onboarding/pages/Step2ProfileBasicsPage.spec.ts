import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    updateProfile: vi.fn(),
    uploadAvatar: vi.fn(),
    deleteAvatar: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step2ProfileBasicsPage from './Step2ProfileBasicsPage.vue'

let teardown: (() => void) | null = null

const baseAttributes = {
  display_name: 'Existing Name',
  bio: null,
  country_code: 'IT',
  region: null,
  primary_language: 'en',
  secondary_languages: [] as string[],
  categories: ['lifestyle'] as string[],
  avatar_path: null,
  cover_path: null,
  verification_level: 'unverified' as const,
  application_status: 'incomplete' as const,
  tier: null,
  kyc_status: 'none' as const,
  kyc_verified_at: null,
  tax_profile_complete: false,
  payout_method_set: false,
  has_signed_master_contract: false,
  click_through_accepted_at: null,
  social_accounts: [],
  portfolio: [],
  profile_completeness_score: 35,
  submitted_at: null,
  approved_at: null,
  created_at: '2026-05-14T00:00:00+00:00',
  updated_at: '2026-05-14T00:00:00+00:00',
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
    data: {
      id: '01',
      type: 'creators',
      attributes: baseAttributes,
      wizard: {
        next_step: 'profile',
        is_submitted: false,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: false,
          creator_payout_method_enabled: false,
          contract_signing_enabled: false,
        },
      },
    } as never,
  })
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('Step2ProfileBasicsPage', () => {
  it('renders the form heading and description', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="step-profile-basics"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Tell us about yourself')
  })

  it('hydrates form fields from the bootstrap state', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const displayNameField = wrapper.find('[data-testid="profile-display-name"] input')
    expect((displayNameField.element as HTMLInputElement).value).toBe('Existing Name')
  })

  it('renders the country preview from the shared CountryDisplay', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="country-display"]').exists()).toBe(true)
  })

  it('renders the category chips from the shared CategoryChips', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="category-chips"]').exists()).toBe(true)
  })
})
