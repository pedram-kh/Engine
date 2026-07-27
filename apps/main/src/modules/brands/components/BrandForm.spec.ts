/**
 * AH-053 D6/D7 — the brand form's floor mirror and logo control.
 *
 * The API is the authority (it refuses to leave a brand incomplete); these
 * pins are about the user seeing the requirement inline instead of meeting a
 * 422. The pre-floor brand — complete-looking but logo-less — is the case that
 * matters most, because without the mirror it would be refused with no visible
 * cause.
 */

import type { CreateBrandPayload } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import BrandForm from './BrandForm.vue'

const COMPLETE: Partial<CreateBrandPayload> = {
  name: 'Acme Corp',
  slug: 'acme-corp',
  description: 'Two Reels and three Stories a month.',
  industry: 'Fashion',
  website_url: 'https://acme.example.com',
}

function mountForm(
  modelValue: Partial<CreateBrandPayload>,
  props: Record<string, unknown> = {},
): ReturnType<typeof mount> {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enApp } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  return mount(BrandForm, {
    props: {
      modelValue,
      submitting: false,
      submitLabel: 'Save brand',
      error: null,
      ...props,
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

function submitButton(wrapper: ReturnType<typeof mount>): HTMLButtonElement {
  return wrapper.find('[data-test="brand-form-submit"]').element as HTMLButtonElement
}

describe('BrandForm — the D6 floor mirror', () => {
  it('holds the submit button and names what is missing on an empty form', () => {
    const wrapper = mountForm({ name: '' })

    expect(submitButton(wrapper).disabled).toBe(true)

    const hint = wrapper.find('[data-test="brand-floor-hint"]').text()
    expect(hint).toContain('brand name')
    expect(hint).toContain('monthly deliverables')
    expect(hint).toContain('logo')
  })

  it('still holds when every text field is filled but no logo has been chosen', () => {
    const wrapper = mountForm(COMPLETE)

    expect(submitButton(wrapper).disabled).toBe(true)
    expect(wrapper.find('[data-test="brand-floor-hint"]').text()).toContain('logo')
  })

  it('releases the submit button once the text fields and a logo are all present', () => {
    const wrapper = mountForm(COMPLETE, { logoUrl: 'blob:preview' })

    expect(submitButton(wrapper).disabled).toBe(false)
    expect(wrapper.find('[data-test="brand-floor-hint"]').exists()).toBe(false)
  })

  it('does not accept whitespace as a filled field (the isFilled agreement)', () => {
    const wrapper = mountForm({ ...COMPLETE, industry: '   ' }, { logoUrl: 'blob:preview' })

    expect(submitButton(wrapper).disabled).toBe(true)
    expect(wrapper.find('[data-test="brand-floor-hint"]').text()).toContain('industry')
  })

  it('renders the server-side logo_path error against the logo control', () => {
    const wrapper = mountForm(COMPLETE, {
      fieldErrors: { logo_path: ['A brand logo is required. Upload one before saving.'] },
    })

    expect(wrapper.find('[data-test="brand-logo-error"]').text()).toContain('logo is required')
  })
})

describe('BrandForm — the logo control (D7)', () => {
  it('offers upload with no logo and replace + remove once one exists', async () => {
    const empty = mountForm(COMPLETE)
    expect(empty.find('[data-test="brand-logo-choose"]').text()).toContain('Upload logo')
    expect(empty.find('[data-test="brand-logo-remove"]').exists()).toBe(false)

    const withLogo = mountForm(COMPLETE, { logoUrl: 'https://signed.example/logo.png' })
    expect(withLogo.find('[data-test="brand-logo-choose"]').text()).toContain('Replace logo')
    expect(withLogo.find('[data-test="brand-logo-remove"]').exists()).toBe(true)
  })

  it('emits the chosen file rather than uploading itself — the parent owns the network call', async () => {
    const wrapper = mountForm(COMPLETE)
    const file = new File(['bytes'], 'logo.png', { type: 'image/png' })
    const input = wrapper.find('[data-test="brand-logo-input"]')

    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')

    expect(wrapper.emitted('logo-selected')?.[0]).toEqual([file])
  })

  it('emits removal', async () => {
    const wrapper = mountForm(COMPLETE, { logoUrl: 'https://signed.example/logo.png' })

    await wrapper.find('[data-test="brand-logo-remove"]').trigger('click')

    expect(wrapper.emitted('logo-removed')).toHaveLength(1)
  })
})

describe('BrandForm — the D8 removals and relabel', () => {
  it('no longer renders the default currency or default language selects', () => {
    const wrapper = mountForm(COMPLETE, { logoUrl: 'blob:preview' })

    expect(wrapper.find('[data-test="brand-default-currency"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="brand-default-language"]').exists()).toBe(false)
  })

  it('labels the description field "Monthly deliverables"', () => {
    const wrapper = mountForm(COMPLETE, { logoUrl: 'blob:preview' })

    expect(wrapper.find('[data-test="brand-description"]').text()).toContain('Monthly deliverables')
  })
})
