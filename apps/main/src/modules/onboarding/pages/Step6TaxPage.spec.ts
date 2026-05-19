import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@catalyst/api-client'

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

/**
 * Drive a Vuetify <v-select> programmatically by emitting its
 * `update:modelValue` event. Vuetify's combobox-style inner input
 * is JSDOM-hostile (see BulkInvitePage.spec.ts for the same
 * friction): selecting an option through the DOM requires a
 * fully-rendered overlay we can't realistically drive here.
 *
 * `findComponent(selector)` is typed as `WrapperLike` (selector
 * MIGHT not match a component); the cast to `VueWrapper` reflects
 * that v-select IS a component — the existence guard below catches
 * any misuse.
 */
function findVSelectByTestId(wrapper: VueWrapper, testid: string): VueWrapper {
  const select = wrapper.findComponent(`[data-testid="${testid}"]`) as VueWrapper
  if (!select.exists()) throw new Error(`v-select with data-testid="${testid}" not found`)
  return select
}

function setSelectValue(wrapper: VueWrapper, testid: string, value: unknown): void {
  findVSelectByTestId(wrapper, testid).vm.$emit('update:modelValue', value)
}

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
        avatar_url: null,
        cover_url: null,
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
    // Country is now a <v-select> — drive it via update:modelValue.
    // Sprint 3 stabilization (May 19, 2026): swapped from a free-text
    // input to prevent users typing the country name ("Spain") and
    // bouncing off the backend's `size:2` ISO-code rule.
    setSelectValue(wrapper, 'tax-address-country', 'IT')
    await flushPromises()

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(onboardingApi.updateTax).toHaveBeenCalledWith({
      tax_form_type: 'eu_self_employed',
      legal_name: 'Acme Studio S.r.l.',
      tax_id: 'IT12345678901',
      address: {
        country_code: 'IT',
        city: 'Milano',
        postal_code: '20100',
        street: 'Via Roma 1',
      },
    })
  })

  // -------------------------------------------------------------------------
  // Sprint 3 stabilization (May 19, 2026): the `validation.failed` envelope
  // emitted by `ValidationExceptionRenderer` has no top-level bundle entry.
  // The old shortcut `submitErrorKey.value = error.code` rendered the
  // literal string "validation.failed" in red text. These specs pin the
  // per-field rendering pattern (mirrors SignUpPage + BrandCreatePage)
  // including the nested `address.*` dot-notation paths.
  // -------------------------------------------------------------------------
  it('binds per-field 422 messages to the matching input (legal_name, tax_id, address.country_code)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap(false))
    vi.mocked(onboardingApi.updateTax).mockRejectedValue(
      ApiError.fromEnvelope(422, {
        errors: [
          {
            id: 'err-1',
            status: '422',
            code: 'validation.failed',
            title: 'The legal name field is required.',
            detail: 'The legal name field is required.',
            source: { pointer: '/data/attributes/legal_name' },
            meta: { field: 'legal_name', rule: 'Required' },
          },
          {
            id: 'err-2',
            status: '422',
            code: 'validation.failed',
            title: 'The tax id field is required.',
            detail: 'The tax id field is required.',
            source: { pointer: '/data/attributes/tax_id' },
            meta: { field: 'tax_id', rule: 'Required' },
          },
          {
            id: 'err-3',
            status: '422',
            code: 'validation.failed',
            title: 'The address.country code must be 2 characters.',
            detail: 'The address.country code must be 2 characters.',
            source: { pointer: '/data/attributes/address.country_code' },
            meta: { field: 'address.country_code', rule: 'Size' },
          },
        ],
        meta: { request_id: 'req-1' },
      }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step6TaxPage, {
      initialRoute: { path: '/onboarding/tax' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    // Fill enough to make the submit button enabled — the actual values
    // don't matter because we're rejecting at the API mock level.
    await wrapper.find('[data-testid="tax-legal-name"] input').setValue('x')
    await wrapper.find('[data-testid="tax-id"] input').setValue('x')
    await wrapper.find('[data-testid="tax-address-street"] input').setValue('x')
    await wrapper.find('[data-testid="tax-address-city"] input').setValue('x')
    await wrapper.find('[data-testid="tax-address-postal"] input').setValue('x')
    setSelectValue(wrapper, 'tax-address-country', 'XX')
    await flushPromises()

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    const html = wrapper.html()
    expect(html).toContain('The legal name field is required.')
    expect(html).toContain('The tax id field is required.')
    expect(html).toContain('The address.country code must be 2 characters.')
    // The literal "validation.failed" key MUST NOT leak through into
    // the DOM as a translation key — that was the visible bug.
    expect(html).not.toContain('validation.failed')
    // Top-level banner should stay hidden when per-field errors fill the role.
    expect(wrapper.find('[data-testid="tax-submit-error"]').exists()).toBe(false)
  })

  it('falls back to the generic banner when the API rejects with no field details', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap(false))
    vi.mocked(onboardingApi.updateTax).mockRejectedValue(
      ApiError.fromEnvelope(500, {
        errors: [
          {
            id: 'err-1',
            status: '500',
            code: 'server.error',
            title: 'Something went wrong',
            detail: 'Something went wrong',
          },
        ],
        meta: { request_id: 'req-2' },
      }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step6TaxPage, {
      initialRoute: { path: '/onboarding/tax' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    await wrapper.find('[data-testid="tax-legal-name"] input').setValue('x')
    await wrapper.find('[data-testid="tax-id"] input').setValue('x')
    await wrapper.find('[data-testid="tax-address-street"] input').setValue('x')
    await wrapper.find('[data-testid="tax-address-city"] input').setValue('x')
    await wrapper.find('[data-testid="tax-address-postal"] input').setValue('x')
    setSelectValue(wrapper, 'tax-address-country', 'IT')
    await flushPromises()

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('[data-testid="tax-submit-error"]').exists()).toBe(true)
  })

  it('country picker exposes the shared ISO-code option list (no free-text fallback)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap(false))

    const { wrapper, unmount } = await mountAuthPage(Step6TaxPage, {
      initialRoute: { path: '/onboarding/tax' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const country = findVSelectByTestId(wrapper, 'tax-address-country')
    // Vuetify VSelect; a VTextField would have no `items` prop. `props()`
    // is untyped here because the component generic isn't carried through
    // the selector-based findComponent — index into the record directly.
    const items = (country.props() as Record<string, unknown>)['items'] as Array<{
      code: string
      label: string
    }>
    expect(items).toBeDefined()
    expect(items.length).toBeGreaterThan(0)
    expect(items.find((i) => i.code === 'ES' && i.label === 'Spain')).toBeDefined()
    expect(items.find((i) => i.code === 'IT' && i.label === 'Italy')).toBeDefined()
  })
})
