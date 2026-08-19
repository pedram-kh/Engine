/**
 * AH-082 — a thin integration case proving the shared InsertLinkButton is
 * actually wired into the offer description field, not just unit-tested in
 * isolation: opening the dialog on this real textarea, selecting real text,
 * and inserting a link lands in `buildOffer()`'s payload.
 *
 * The full insert-link logic (selection handling, cursor restore, the
 * http/https check, the maxlength guard) is covered once, at the source, in
 * InsertLinkButton.spec.ts — this file only proves the seam.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/campaigns.api', () => ({
  campaignsApi: {
    offerAttachmentInit: vi.fn(),
    offerAttachmentComplete: vi.fn(),
  },
}))

import OfferFieldsForm from './OfferFieldsForm.vue'

function mountForm() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(OfferFieldsForm, {
    props: { agencyId: 'agency-1', campaignId: 'campaign-1', currency: 'USD' },
    global: { plugins: [vuetify, i18n] },
    attachTo: document.createElement('div'),
  })
}

describe('OfferFieldsForm — insert-link integration (AH-082)', () => {
  it('inserts a markdown link into the description via the shared InsertLinkButton', async () => {
    const wrapper = mountForm()

    const textarea = wrapper.find('[data-test="offer-fields-description"] textarea')
      .element as HTMLTextAreaElement
    textarea.value = 'shoot a video please'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    textarea.setSelectionRange(6, 13) // "a video"
    textarea.dispatchEvent(new Event('select'))

    await wrapper.find('[data-test="offer-fields-insert-link-button"]').trigger('click')
    await flushPromises()

    const urlInput = document.body.querySelector(
      '[data-test="offer-fields-insert-link-url"] input',
    ) as HTMLInputElement
    urlInput.value = 'https://example.com/brief'
    urlInput.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(
      document.body.querySelector('[data-test="offer-fields-insert-link-insert"]') as HTMLElement
    ).click()
    await flushPromises()

    expect(
      (
        wrapper.find('[data-test="offer-fields-description"] textarea')
          .element as HTMLTextAreaElement
      ).value,
    ).toBe('shoot [a video](https://example.com/brief) please')

    await wrapper.find('[data-test="offer-fields-fee"] input').setValue('50')
    const offer = await wrapper.vm.buildOffer()
    expect(offer?.offer_description).toBe('shoot [a video](https://example.com/brief) please')

    wrapper.unmount()
  })
})
