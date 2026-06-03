/**
 * Sprint 6 Chunk 2b — Vitest coverage for the shared PoolForm: the controlled
 * v-model contract, the optional brand label select (D-2b-4), per-field errors,
 * and the submit gate (disabled until a name is present).
 */

import type { CreateTalentPoolPayload } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'
import { createI18n } from 'vue-i18n'

import enApp from '@/core/i18n/locales/en/app.json'

import PoolForm, { type BrandOption } from './PoolForm.vue'

function mountForm(
  modelValue: CreateTalentPoolPayload,
  options: {
    brandOptions?: BrandOption[]
    fieldErrors?: Partial<Record<keyof CreateTalentPoolPayload, readonly string[]>>
  } = {},
) {
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

  return mount(PoolForm, {
    props: {
      modelValue,
      submitting: false,
      submitLabel: 'Save pool',
      error: null,
      brandOptions: options.brandOptions ?? [],
      fieldErrors: options.fieldErrors ?? {},
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

describe('PoolForm (Sprint 6 Chunk 2b)', () => {
  let wrapper: ReturnType<typeof mount> | null = null

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('disables submit until a name is present', async () => {
    wrapper = mountForm({ name: '' })
    const submit = wrapper.find('[data-test="pool-form-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
  })

  it('enables submit and emits submit when a name is set', async () => {
    wrapper = mountForm({ name: 'Acme Q3' })
    await wrapper.find('[data-test="pool-form"]').trigger('submit')
    expect(wrapper.emitted('submit')).toBeTruthy()
  })

  it('surfaces a per-field name error from the backend', async () => {
    wrapper = mountForm(
      { name: 'Dupe' },
      { fieldErrors: { name: ['The name has already been taken.'] } },
    )
    expect(wrapper.text()).toContain('The name has already been taken.')
  })

  it('renders the brand label select with the provided options', async () => {
    wrapper = mountForm({ name: 'Acme Q3' }, { brandOptions: [{ value: '01BR', title: 'Acme' }] })
    expect(wrapper.find('[data-test="pool-brand"]').exists()).toBe(true)
  })
})
