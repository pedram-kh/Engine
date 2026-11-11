/**
 * Vitest coverage for the shared campaign form's AH-069 posting toggle (D1).
 *
 * The form is shared between the create page and the detail page's Settings
 * tab, so the switch is asserted through the payload it emits rather than
 * through either page: what matters is that the toggle is always PRESENT in the
 * payload and reads in the positive direction of its label.
 *
 * The create page's product default (`false`) is pinned in CampaignCreatePage's
 * own spec — the two halves of the Q1 two-layer design are deliberately tested
 * where each one lives.
 */

import type { CreateCampaignPayload } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import CampaignForm from './CampaignForm.vue'

function basePayload(overrides: Partial<CreateCampaignPayload> = {}): CreateCampaignPayload {
  return {
    brand_id: 'brand-ulid',
    name: 'Autumn UGC',
    budget_minor_units: 500_000,
    budget_currency: 'EUR',
    ...overrides,
  }
}

function mountForm(modelValue: CreateCampaignPayload, extra: Record<string, unknown> = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(CampaignForm, {
    props: {
      modelValue,
      brands: [{ id: 'brand-ulid', name: 'Acme' }],
      submitting: false,
      submitLabel: 'Save',
      error: null,
      ...extra,
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.body,
  })
}

describe('CampaignForm — the posting toggle (AH-069 D1)', () => {
  it('renders the switch with the locked label and its explaining hint', () => {
    const wrapper = mountForm(basePayload({ creator_posts_content: false }))

    const control = wrapper.find('[data-test="campaign-creator-posts-content"]')
    expect(control.exists()).toBe(true)
    expect(wrapper.text()).toContain('Deliverables are posted by creators')
    // The hint is what stops the switch being a coin flip — it names both sides.
    expect(wrapper.text()).toContain('approving a draft completes the assignment')
  })

  it('reflects a stored false without inverting it', () => {
    const wrapper = mountForm(basePayload({ creator_posts_content: false }))

    const input = wrapper.find('[data-test="campaign-creator-posts-content"] input')
    expect((input.element as HTMLInputElement).checked).toBe(false)
  })

  it('reflects a stored true', () => {
    const wrapper = mountForm(basePayload({ creator_posts_content: true }))

    const input = wrapper.find('[data-test="campaign-creator-posts-content"] input')
    expect((input.element as HTMLInputElement).checked).toBe(true)
  })

  it('falls back to ON when the payload does not carry the key — the safety floor', () => {
    const wrapper = mountForm(basePayload())

    const input = wrapper.find('[data-test="campaign-creator-posts-content"] input')
    expect((input.element as HTMLInputElement).checked).toBe(true)
  })

  it('emits the flipped value, so the field is always explicit on the wire', async () => {
    const wrapper = mountForm(basePayload({ creator_posts_content: false }))

    const input = wrapper.find('[data-test="campaign-creator-posts-content"] input')
    await input.setValue(true)

    const emitted = wrapper.emitted('update:modelValue')
    expect(emitted).toBeTruthy()
    const last = emitted?.at(-1)?.[0] as CreateCampaignPayload
    expect(last.creator_posts_content).toBe(true)
  })
})

describe('CampaignForm — insert-link integration (AH-082)', () => {
  it('inserts a markdown link into the description via the shared InsertLinkButton', async () => {
    const wrapper = mountForm(basePayload({ description: 'shoot a video please' }))
    // Let VTextarea's post-mount auto-grow pass settle before touching
    // selection — otherwise its deferred resize effect can land after ours
    // and reset the cursor to 0, same as the composer precedent's spec.
    await flushPromises()

    const textareaEl = wrapper.find('[data-test="campaign-description"] textarea')
      .element as HTMLTextAreaElement
    textareaEl.setSelectionRange(6, 13) // "a video"

    await wrapper.find('[data-test="campaign-insert-link-button"]').trigger('click')
    await flushPromises()

    const urlInput = document.body.querySelector(
      '[data-test="campaign-insert-link-url"] input',
    ) as HTMLInputElement
    urlInput.value = 'https://example.com/brief'
    urlInput.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(
      document.body.querySelector('[data-test="campaign-insert-link-insert"]') as HTMLElement
    ).click()
    await flushPromises()

    expect(
      (wrapper.find('[data-test="campaign-description"] textarea').element as HTMLTextAreaElement)
        .value,
    ).toBe('shoot [a video](https://example.com/brief) please')

    const emitted = wrapper.emitted('update:modelValue')
    const last = emitted?.at(-1)?.[0] as CreateCampaignPayload
    expect(last.description).toBe('shoot [a video](https://example.com/brief) please')
  })
})

describe('CampaignForm — the region switch (AH-118)', () => {
  const SWITCH = '[data-test="campaign-restrict-to-regions"]'

  it('renders nothing while the feature flag is off, even for a stored true', () => {
    const wrapper = mountForm(
      basePayload({ listing_regions: ['IE'], restrict_to_listing_regions: true }),
    )
    expect(wrapper.find(SWITCH).exists()).toBe(false)
  })

  it('renders the switch with its label and the hint that invitations are not affected', () => {
    const wrapper = mountForm(basePayload({ listing_regions: ['IE'] }), {
      regionRestrictionEnabled: true,
    })
    expect(wrapper.find(SWITCH).exists()).toBe(true)
    expect(wrapper.text()).toContain('Only creators in the listed regions can apply')
    expect(wrapper.text()).toContain('Invitations are not affected.')
    expect((wrapper.find(`${SWITCH} input`).element as HTMLInputElement).checked).toBe(false)
  })

  it('is disabled while no region is chosen, enabled once one is', () => {
    const empty = mountForm(basePayload({ listing_regions: [] }), {
      regionRestrictionEnabled: true,
    })
    expect((empty.find(`${SWITCH} input`).element as HTMLInputElement).disabled).toBe(true)

    const chosen = mountForm(basePayload({ listing_regions: ['PT'] }), {
      regionRestrictionEnabled: true,
    })
    expect((chosen.find(`${SWITCH} input`).element as HTMLInputElement).disabled).toBe(false)
  })

  it('emits restrict_to_listing_regions true when turned on', async () => {
    const wrapper = mountForm(basePayload({ listing_regions: ['PT'] }), {
      regionRestrictionEnabled: true,
    })
    await wrapper.find(`${SWITCH} input`).setValue(true)

    const emitted = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as CreateCampaignPayload
    expect(emitted.restrict_to_listing_regions).toBe(true)
  })

  it('says to choose a region when the switch is ON with none (the merged-state rule, mirrored)', () => {
    const wrapper = mountForm(
      basePayload({ listing_regions: [], restrict_to_listing_regions: true }),
      { regionRestrictionEnabled: true },
    )
    expect(wrapper.text()).toContain('Choose at least one region to restrict applications.')
  })
})

describe('CampaignForm — brand exclusivity (AH-120)', () => {
  const LIST = '[data-test="campaign-exclusive-brands"]'
  const DATE = '[data-test="campaign-exclusivity-ends-on"]'
  const BRANDS = [
    { id: 'brand-ulid', name: 'Acme' },
    { id: 'zava-ulid', name: 'Zava' },
    { id: 'yahoo-ulid', name: 'Yahoo' },
  ]

  function autocompleteItems(wrapper: ReturnType<typeof mountForm>) {
    return wrapper.findComponent(vuetifyComponents.VAutocomplete).props('items') as {
      title: string
      value: string
      props?: { disabled: boolean }
    }[]
  }

  it('renders neither control while the flag is off (the DOM it always was)', () => {
    const wrapper = mountForm(basePayload(), { brands: BRANDS })

    expect(wrapper.find(LIST).exists()).toBe(false)
    expect(wrapper.find(DATE).exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Exclusivity ends on')
  })

  it('renders the multi-select and the end date while the flag is on, with the hint and the cap', () => {
    const wrapper = mountForm(basePayload(), { brands: BRANDS, brandExclusivityEnabled: true })

    expect(wrapper.find(LIST).exists()).toBe(true)
    expect(wrapper.find(DATE).exists()).toBe(true)
    expect(wrapper.text()).toContain('Block creators selected here from applying to')
    expect(wrapper.text()).toContain('Invitations are not affected.')
    expect(wrapper.text()).toContain('Up to 50 brands')
  })

  it("offers every brand except the campaign's own (create: the chosen brand)", () => {
    const wrapper = mountForm(basePayload({ brand_id: 'brand-ulid' }), {
      brands: BRANDS,
      brandExclusivityEnabled: true,
    })

    expect(autocompleteItems(wrapper).map((i) => i.value)).toEqual(['zava-ulid', 'yahoo-ulid'])
  })

  it('offers every brand except the stored one on the Settings edit (hide-brand)', () => {
    const wrapper = mountForm(basePayload({ brand_id: 'zava-ulid' }), {
      brands: BRANDS,
      hideBrand: true,
      ownBrandId: 'zava-ulid',
      brandExclusivityEnabled: true,
    })

    expect(autocompleteItems(wrapper).map((i) => i.value)).toEqual(['brand-ulid', 'yahoo-ulid'])
  })

  it('keeps an archived brand that is already listed (marked, removable), and never offers it otherwise', () => {
    const archived = { id: 'old-ulid', name: 'Old Co', is_archived: true }

    const listed = mountForm(basePayload({ exclusive_brand_ids: ['old-ulid'] }), {
      brands: BRANDS,
      brandExclusivityEnabled: true,
      listedExclusiveBrands: [archived],
    })
    const row = autocompleteItems(listed).find((i) => i.value === 'old-ulid')
    expect(row?.title).toBe('Old Co (Archived)')
    expect(row?.props?.disabled).toBe(false)

    // Removed from the selection: still shown, but can no longer be picked again.
    const removed = mountForm(basePayload({ exclusive_brand_ids: [] }), {
      brands: BRANDS,
      brandExclusivityEnabled: true,
      listedExclusiveBrands: [archived],
    })
    expect(autocompleteItems(removed).find((i) => i.value === 'old-ulid')?.props?.disabled).toBe(
      true,
    )
  })

  it('emits exclusive_brand_ids on change', async () => {
    const wrapper = mountForm(basePayload(), { brands: BRANDS, brandExclusivityEnabled: true })

    wrapper
      .findComponent(vuetifyComponents.VAutocomplete)
      .vm.$emit('update:modelValue', ['zava-ulid'])
    await flushPromises()

    const emitted = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as CreateCampaignPayload
    expect(emitted.exclusive_brand_ids).toEqual(['zava-ulid'])
  })

  it("drops the blocked brand that the campaign's own brand just became (create)", async () => {
    const wrapper = mountForm(basePayload({ exclusive_brand_ids: ['zava-ulid', 'yahoo-ulid'] }), {
      brands: BRANDS,
      brandExclusivityEnabled: true,
    })

    wrapper.findComponent(vuetifyComponents.VSelect).vm.$emit('update:modelValue', 'zava-ulid')
    await flushPromises()

    const emitted = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as CreateCampaignPayload
    expect(emitted.brand_id).toBe('zava-ulid')
    expect(emitted.exclusive_brand_ids).toEqual(['yahoo-ulid'])
  })

  it('emits null when the end date is cleared', async () => {
    const wrapper = mountForm(basePayload({ exclusivity_ends_on: '2099-01-31' }), {
      brands: BRANDS,
      brandExclusivityEnabled: true,
    })

    const date = wrapper
      .findAllComponents(vuetifyComponents.VTextField)
      .find((c) => c.attributes('data-test') === 'campaign-exclusivity-ends-on')
    date?.vm.$emit('update:modelValue', '')
    await flushPromises()

    const emitted = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as CreateCampaignPayload
    expect(emitted.exclusivity_ends_on).toBeNull()
  })

  it('says so before the round trip when a CHANGED date is in the past, but not for an unchanged one', () => {
    const changed = mountForm(basePayload({ exclusivity_ends_on: '2000-01-01' }), {
      brands: BRANDS,
      brandExclusivityEnabled: true,
    })
    expect(changed.text()).toContain('Choose today or a later date.')

    const unchanged = mountForm(basePayload({ exclusivity_ends_on: '2000-01-01' }), {
      brands: BRANDS,
      brandExclusivityEnabled: true,
      storedExclusivityEndsOn: '2000-01-01',
    })
    expect(unchanged.text()).not.toContain('Choose today or a later date.')

    const future = mountForm(basePayload({ exclusivity_ends_on: '2099-01-01' }), {
      brands: BRANDS,
      brandExclusivityEnabled: true,
    })
    expect(future.text()).not.toContain('Choose today or a later date.')
  })

  it("shows the server's per-brand refusal on the list", () => {
    const wrapper = mountForm(basePayload({ exclusive_brand_ids: ['zava-ulid'] }), {
      brands: BRANDS,
      brandExclusivityEnabled: true,
      fieldErrors: { 'exclusive_brand_ids.0': ['Choose a brand from this agency.'] },
    })

    expect(wrapper.text()).toContain('Choose a brand from this agency.')
  })
})
