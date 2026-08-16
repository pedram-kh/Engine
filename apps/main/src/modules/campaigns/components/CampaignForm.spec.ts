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
import { mount } from '@vue/test-utils'
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

function mountForm(modelValue: CreateCampaignPayload) {
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
