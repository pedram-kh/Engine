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
  // D1: region is part of the six-field floor. The base fixture fills it so
  // the "happy" gate paths satisfy the full floor; the avatar-missing tests
  // still isolate on the single missing avatar, and the region-gates test
  // overrides it back to null with the avatar present.
  region: 'Lazio',
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

function mockBootstrap(attrOverrides: Record<string, unknown> = {}): void {
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
    data: {
      id: '01',
      type: 'creators',
      attributes: { ...baseAttributes, ...attrOverrides },
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
}

beforeEach(() => {
  vi.clearAllMocks()
  // Default fixture satisfies every floor field EXCEPT the avatar — Step 2's
  // full-floor gate (D2) therefore starts unmet on the avatar alone. Tests
  // that need to exercise the submit path opt in via mockBootstrap({ avatar_path }).
  mockBootstrap()
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
    // Completion gate must be satisfied (avatar + category) so the
    // submit actually reaches the server and surfaces the 422.
    mockBootstrap({ avatar_path: 'creators/seed/avatar/x.jpg' })
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

  // D2 (was: May 29, 2026 stabilization): the forward gate mirrors the FULL
  // backend `isProfileComplete` floor. A creator can't "Save and continue"
  // with any floor field missing — here, the avatar.
  it('disables Save and continue and shows the requirements hint when the avatar is missing', async () => {
    // Default fixture: every floor field present EXCEPT the avatar → gate unmet.
    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="profile-submit"]').classes()).toContain('v-btn--disabled')
    expect(wrapper.find('[data-testid="profile-requirements-hint"]').exists()).toBe(true)
  })

  it('does not call updateProfile when submitted with the avatar still missing', async () => {
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

    expect(onboardingApi.updateProfile).not.toHaveBeenCalled()
  })

  it('hydrates and persists the AH-005 contact details', async () => {
    mockBootstrap({
      avatar_path: 'creators/seed/avatar/x.jpg',
      categories: ['lifestyle'],
      phone: '+1 555 0100',
      whatsapp: '+1 555 0142',
      address_street: '12 Market Street',
      address_postal_code: 'D02 XY45',
    })
    vi.mocked(onboardingApi.updateProfile).mockResolvedValue({
      data: {
        id: '01',
        type: 'creators',
        attributes: { ...baseAttributes },
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

    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    // Hydrated from the bootstrap state — phone is split into dial + local.
    const phoneLocalInput = wrapper.find('[data-testid="profile-phone"] input')
    expect((phoneLocalInput.element as HTMLInputElement).value).toBe('555 0100')

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(onboardingApi.updateProfile).toHaveBeenCalledWith(
      expect.objectContaining({
        phone: '+1 555 0100',
        whatsapp: '+1 555 0142',
        address_street: '12 Market Street',
        address_postal_code: 'D02 XY45',
      }),
    )
  })

  it('sends null for a cleared (empty) contact field', async () => {
    mockBootstrap({
      avatar_path: 'creators/seed/avatar/x.jpg',
      categories: ['lifestyle'],
      phone: null,
      whatsapp: null,
      address_street: null,
      address_postal_code: null,
    })
    vi.mocked(onboardingApi.updateProfile).mockResolvedValue({
      data: {
        id: '01',
        type: 'creators',
        attributes: { ...baseAttributes },
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

    expect(vi.mocked(onboardingApi.updateProfile).mock.calls.at(-1)?.[0]).toMatchObject({
      phone: null,
      whatsapp: null,
      address_street: null,
      address_postal_code: null,
    })
  })

  it('enables Save and continue and hides the hint once the full floor is met', async () => {
    // Base fixture fills display_name/country/region/language/category; adding
    // the avatar completes the six-field floor → gate met.
    mockBootstrap({ avatar_path: 'creators/seed/avatar/x.jpg', categories: ['lifestyle'] })
    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="profile-submit"]').classes()).not.toContain(
      'v-btn--disabled',
    )
    expect(wrapper.find('[data-testid="profile-requirements-hint"]').exists()).toBe(false)
  })

  // D1 + D2: region is a floor field, so it gates step 2 on its own — a
  // creator with every OTHER floor field (incl. avatar) still can't advance
  // until region is filled. This is the FE half of the floor-mirror.
  it('disables Save and continue when only region is missing (D1 floor)', async () => {
    mockBootstrap({ avatar_path: 'creators/seed/avatar/x.jpg', region: null })
    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="profile-submit"]').classes()).toContain('v-btn--disabled')
    expect(wrapper.find('[data-testid="profile-requirements-hint"]').exists()).toBe(true)
  })

  it('enables Save and continue once region is filled with the rest of the floor met (D1 floor)', async () => {
    mockBootstrap({ avatar_path: 'creators/seed/avatar/x.jpg', region: 'Lombardy' })
    const { wrapper, unmount } = await mountAuthPage(Step2ProfileBasicsPage, {
      initialRoute: { path: '/onboarding/profile' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="profile-submit"]').classes()).not.toContain(
      'v-btn--disabled',
    )
  })
})
