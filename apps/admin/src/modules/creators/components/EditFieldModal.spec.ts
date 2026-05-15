/**
 * EditFieldModal unit tests — Sprint 3 Chunk 4 sub-step 9.
 *
 * Exercises the 7 control kinds (text / textarea / region-text /
 * select / multi-select) plus the reason-required gate (for `bio`
 * and `categories`), error-key rendering, and the save / cancel
 * emit contract.
 */

import { describe, expect, it, afterEach, beforeEach } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import enCreators from '@/core/i18n/locales/en/creators.json'

import EditFieldModal from './EditFieldModal.vue'
import { FIELD_EDIT_CONFIG } from '../config/field-edit'

function buildI18n() {
  return createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    messages: { en: { ...enApp, ...enAuth, ...enCreators } } as never,
  }) as unknown as ReturnType<typeof createI18n>
}

function buildVuetify() {
  return createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
}

function mountModal(props: {
  field: keyof typeof FIELD_EDIT_CONFIG
  currentValue: unknown
  modelValue?: boolean
  errorKey?: string | null
  isSaving?: boolean
}): VueWrapper<unknown> {
  const i18n = buildI18n()
  const vuetify = buildVuetify()
  return mount(EditFieldModal, {
    props: {
      modelValue: props.modelValue ?? true,
      config: FIELD_EDIT_CONFIG[props.field],
      currentValue: props.currentValue,
      errorKey: props.errorKey ?? null,
      isSaving: props.isSaving ?? false,
    },
    global: {
      plugins: [i18n, vuetify],
    },
    attachTo: document.createElement('div'),
  })
}

describe('EditFieldModal', () => {
  let wrapper: VueWrapper<unknown> | null = null

  beforeEach(() => {
    document.body.innerHTML = ''
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('renders the i18n-translated title for the field being edited', async () => {
    wrapper = mountModal({ field: 'display_name', currentValue: 'Jane' })
    await flushPromises()
    const title = document.body.querySelector('[data-testid="admin-creator-edit-modal-title"]')
    expect(title?.textContent?.trim()).toBe('Edit Display name')
  })

  it('hydrates the text input with the current value for display_name', async () => {
    wrapper = mountModal({ field: 'display_name', currentValue: 'Jane' })
    await flushPromises()
    const input = document.body.querySelector<HTMLInputElement>(
      '[data-testid="admin-creator-edit-modal-text"] input',
    )
    expect(input?.value).toBe('Jane')
  })

  it('disables Save when display_name is empty (non-nullable text)', async () => {
    wrapper = mountModal({ field: 'display_name', currentValue: '' })
    await flushPromises()
    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )
    expect(save?.disabled).toBe(true)
  })

  it('emits save with trimmed value when display_name is filled', async () => {
    wrapper = mountModal({ field: 'display_name', currentValue: '' })
    await flushPromises()
    const input = document.body.querySelector<HTMLInputElement>(
      '[data-testid="admin-creator-edit-modal-text"] input',
    )!
    input.value = '  New Name  '
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )!
    save.click()
    await flushPromises()
    const emits = wrapper!.emitted('save')
    expect(emits).toHaveLength(1)
    expect(emits?.[0]?.[0]).toEqual({
      field: 'display_name',
      value: 'New Name',
      reason: null,
    })
  })

  it('disables Save when reason is missing for a reason-required field (bio)', async () => {
    wrapper = mountModal({ field: 'bio', currentValue: 'old bio' })
    await flushPromises()
    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )
    expect(save?.disabled).toBe(true)
  })

  it('enables Save once a reason is provided for bio', async () => {
    wrapper = mountModal({ field: 'bio', currentValue: 'old bio' })
    await flushPromises()
    const reason = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-edit-modal-reason"] textarea',
    )!
    reason.value = 'Updating to remove outdated text'
    reason.dispatchEvent(new Event('input'))
    await flushPromises()
    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )
    expect(save?.disabled).toBe(false)
  })

  it('emits save with reason for bio', async () => {
    wrapper = mountModal({ field: 'bio', currentValue: 'old' })
    await flushPromises()
    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-edit-modal-textarea"] textarea',
    )!
    textarea.value = 'new bio text'
    textarea.dispatchEvent(new Event('input'))
    const reason = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-edit-modal-reason"] textarea',
    )!
    reason.value = 'cleanup'
    reason.dispatchEvent(new Event('input'))
    await flushPromises()
    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )!
    save.click()
    await flushPromises()
    const emits = wrapper!.emitted('save')
    expect(emits?.[0]?.[0]).toEqual({
      field: 'bio',
      value: 'new bio text',
      reason: 'cleanup',
    })
  })

  it('emits save with null when bio is cleared (nullable textarea)', async () => {
    wrapper = mountModal({ field: 'bio', currentValue: 'old' })
    await flushPromises()
    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-edit-modal-textarea"] textarea',
    )!
    textarea.value = ''
    textarea.dispatchEvent(new Event('input'))
    const reason = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-edit-modal-reason"] textarea',
    )!
    reason.value = 'remove bio'
    reason.dispatchEvent(new Event('input'))
    await flushPromises()
    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )!
    save.click()
    await flushPromises()
    expect(wrapper!.emitted('save')?.[0]?.[0]).toEqual({
      field: 'bio',
      value: null,
      reason: 'remove bio',
    })
  })

  it('disables Save for categories when fewer than minItems are selected', async () => {
    wrapper = mountModal({ field: 'categories', currentValue: [] })
    await flushPromises()
    const reason = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-edit-modal-reason"] textarea',
    )!
    reason.value = 'cleanup'
    reason.dispatchEvent(new Event('input'))
    await flushPromises()
    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )
    expect(save?.disabled).toBe(true)
  })

  it('renders the error key when one is provided', async () => {
    wrapper = mountModal({
      field: 'display_name',
      currentValue: 'Jane',
      errorKey: 'admin.creators.detail.edit.save_failed',
    })
    await flushPromises()
    const error = document.body.querySelector('[data-testid="admin-creator-edit-modal-error"]')
    expect(error?.textContent?.trim()).toBe("We couldn't save this change. Please try again.")
  })

  it('emits cancel + update:modelValue=false when Cancel is clicked', async () => {
    wrapper = mountModal({ field: 'display_name', currentValue: 'Jane' })
    await flushPromises()
    const cancel = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-cancel"]',
    )!
    cancel.click()
    await flushPromises()
    expect(wrapper!.emitted('cancel')).toHaveLength(1)
    expect(wrapper!.emitted('update:modelValue')?.[0]?.[0]).toBe(false)
  })

  it('shows the loading state on the Save button when isSaving is true', async () => {
    wrapper = mountModal({
      field: 'display_name',
      currentValue: 'Jane',
      isSaving: true,
    })
    await flushPromises()
    const save = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-edit-modal-save"]',
    )
    expect(save?.disabled).toBe(true)
  })
})
