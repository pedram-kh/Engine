/**
 * PL-1.2 W1 + W2 — the campaign type on the shared form.
 *
 *  W1  The type choice is offered only when the page says `waves_enabled` is on; "For a wave" hides the posting
 *      switch and puts `creator_posts_content: false` into the payload; the Settings edit never offers the type.
 *  W2  With the flag off the form's DOM is what it was before PL-1.2: no type control, and no extra comment node
 *      (a `v-if` that is false leaves `<!--v-if-->`, which would make the flag-off campaign walk differ).
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

function payload(over: Partial<CreateCampaignPayload> = {}): CreateCampaignPayload {
  return {
    brand_id: 'brand-ulid',
    name: 'Autumn',
    budget_minor_units: 100_000,
    budget_currency: 'EUR',
    creator_posts_content: false,
    ...over,
  }
}

function mountForm(model: CreateCampaignPayload, extra: Record<string, unknown> = {}) {
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
      modelValue: model,
      brands: [{ id: 'brand-ulid', name: 'Acme' }],
      submitting: false,
      submitLabel: 'Save',
      error: null,
      ...extra,
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

function commentCount(root: Element): number {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_COMMENT)
  let n = 0
  while (walker.nextNode()) n += 1
  return n
}

describe('CampaignForm — campaign type (PL-1.2 W1)', () => {
  it('offers no type choice unless the page turns it on', () => {
    expect(mountForm(payload()).find('[data-test="campaign-type"]').exists()).toBe(false)
    expect(
      mountForm(payload(), { showTypeChoice: true }).find('[data-test="campaign-type"]').exists(),
    ).toBe(true)
  })

  it('"For a wave" hides the posting switch and sends creator_posts_content false and the type', async () => {
    const w = mountForm(payload({ creator_posts_content: true }), { showTypeChoice: true })
    expect(w.find('[data-test="campaign-creator-posts-content"]').exists()).toBe(true)

    await w.find('[data-test="campaign-type-for-wave"] input').setValue(true)

    const emitted = w.emitted('update:modelValue') ?? []
    const last = emitted[emitted.length - 1]?.[0] as CreateCampaignPayload
    expect(last.campaign_type).toBe('for_wave')
    expect(last.creator_posts_content).toBe(false)
    await w.setProps({ modelValue: last })
    expect(w.find('[data-test="campaign-creator-posts-content"]').exists()).toBe(false)
  })

  it('a saved for-a-wave campaign (Settings edit) shows no posting switch and no type choice', () => {
    const w = mountForm(payload(), { hideBrand: true, forWave: true })
    expect(w.find('[data-test="campaign-type"]').exists()).toBe(false)
    expect(w.find('[data-test="campaign-creator-posts-content"]').exists()).toBe(false)
  })
})

describe('CampaignForm — flag-off DOM (PL-1.2 W2)', () => {
  // Measured on the pre-PL-1.2 form (git HEAD a173a9b2) with these exact props: 219 (almost all are Vuetify's own
  // anchors). The new controls must not add one.
  const BASELINE_COMMENTS = 219

  it('adds no comment node and no type control with waves off', () => {
    const w = mountForm(payload())
    expect(w.find('[data-test="campaign-type"]').exists()).toBe(false)
    expect(commentCount(w.element)).toBe(BASELINE_COMMENTS)
  })

  it('a normal campaign keeps the posting switch exactly where it was', () => {
    const w = mountForm(payload({ creator_posts_content: true }))
    expect(w.find('[data-test="campaign-creator-posts-content"]').exists()).toBe(true)
    expect(commentCount(w.element)).toBe(BASELINE_COMMENTS)
  })
})
