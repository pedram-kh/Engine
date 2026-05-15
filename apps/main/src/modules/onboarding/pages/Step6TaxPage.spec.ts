import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    updateTax: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step6TaxPage from './Step6TaxPage.vue'

let teardown: (() => void) | null = null

function makeBootstrap(taxComplete: boolean): never {
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
        tax_profile_complete: taxComplete,
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
        next_step: 'tax',
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
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('Step6TaxPage', () => {
  it('renders the form and the incomplete status badge', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap(false))

    const { wrapper, unmount } = await mountAuthPage(Step6TaxPage, {
      initialRoute: { path: '/onboarding/tax' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="step-tax"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tax-form"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tax-profile-display-incomplete"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tax-advance"]').attributes('disabled')).toBeDefined()
  })

  it('renders the complete status badge and enables advance when tax_profile_complete=true', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap(true))

    const { wrapper, unmount } = await mountAuthPage(Step6TaxPage, {
      initialRoute: { path: '/onboarding/tax' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="tax-profile-display-complete"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tax-advance"]').attributes('disabled')).toBeUndefined()
  })

  it('save is disabled until all required fields are filled', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap(false))

    const { wrapper, unmount } = await mountAuthPage(Step6TaxPage, {
      initialRoute: { path: '/onboarding/tax' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const saveBtn = wrapper.find('[data-testid="tax-save"]')
    expect(saveBtn.attributes('disabled')).toBeDefined()
  })

  it('calls updateTax with trimmed values on submit', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap(false))
    vi.mocked(onboardingApi.updateTax).mockResolvedValue(makeBootstrap(true))

    const { wrapper, unmount } = await mountAuthPage(Step6TaxPage, {
      initialRoute: { path: '/onboarding/tax' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    await wrapper.find('[data-testid="tax-legal-name"] input').setValue('  Acme Studio S.r.l.  ')
    await wrapper.find('[data-testid="tax-id"] input').setValue('IT12345678901')
    await wrapper.find('[data-testid="tax-address-street"] input').setValue('Via Roma 1')
    await wrapper.find('[data-testid="tax-address-city"] input').setValue('Milano')
    await wrapper.find('[data-testid="tax-address-postal"] input').setValue('20100')
    await wrapper.find('[data-testid="tax-address-country"] input').setValue('IT')

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(onboardingApi.updateTax).toHaveBeenCalledWith({
      tax_form_type: 'eu_self_employed',
      legal_name: 'Acme Studio S.r.l.',
      tax_id: 'IT12345678901',
      address: {
        country: 'IT',
        city: 'Milano',
        postal_code: '20100',
        street: 'Via Roma 1',
      },
    })
  })
})
