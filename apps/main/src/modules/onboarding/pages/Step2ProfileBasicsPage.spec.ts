import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@catalyst/api-client'

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
  avatar_url: null,
  cover_url: null,
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

  // Sprint 3 stabilization (May 19, 2026): per-field 422 rendering.
  // Same pattern as SignUpPage / Step 6 Tax — `validation.failed`
  // MUST NOT reach `t()` as a literal i18n key.
  it('binds per-field 422 messages to the matching input and hides the generic banner', async () => {
    vi.mocked(onboardingApi.updateProfile).mockRejectedValue(
      ApiError.fromEnvelope(422, {
        errors: [
          {
            id: 'err-1',
            status: '422',
            code: 'validation.failed',
            title: 'The display name field is required.',
            detail: 'The display name field is required.',
            source: { pointer: '/data/attributes/display_name' },
            meta: { field: 'display_name', rule: 'Required' },
          },
        ],
        meta: { request_id: 'req-1' },
      }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    const html = wrapper.html()
    expect(html).toContain('The display name field is required.')
    expect(html).not.toContain('validation.failed')
    expect(wrapper.find('[data-testid="profile-submit-error"]').exists()).toBe(false)
  })
})
