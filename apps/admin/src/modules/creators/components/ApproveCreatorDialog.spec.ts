/**
 * ApproveCreatorDialog unit tests — Sprint 3 Chunk 4 sub-step 10.
 *
 * Confirms the confirm-with-optional-welcome-message contract +
 * the error-state rendering for `creator.already_approved` returned
 * by the backend's idempotency rule #6 path.
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import enCreators from '@/core/i18n/locales/en/creators.json'

import ApproveCreatorDialog from './ApproveCreatorDialog.vue'

function buildI18n() {
  return createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    messages: { en: { ...enApp, ...enAuth, ...enCreators } } as never,
  }) as unknown as ReturnType<typeof createI18n>
}

function mountDialog(
  props: Partial<{
    modelValue: boolean
    isSaving: boolean
    errorKey: string | null
    creatorDisplayName: string
  }> = {},
): VueWrapper<unknown> {
  const i18n = buildI18n()
  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })
  return mount(ApproveCreatorDialog, {
    props: {
      modelValue: props.modelValue ?? true,
      isSaving: props.isSaving ?? false,
      errorKey: props.errorKey ?? null,
      creatorDisplayName: props.creatorDisplayName ?? 'Jane Doe',
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

describe('ApproveCreatorDialog', () => {
  let wrapper: VueWrapper<unknown> | null = null

  beforeEach(() => {
    document.body.innerHTML = ''
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('renders the i18n title with the creator display name', async () => {
    wrapper = mountDialog({ creatorDisplayName: 'Alice' })
    await flushPromises()
    const title = document.body.querySelector('[data-testid="admin-creator-approve-dialog-title"]')
    expect(title?.textContent?.trim()).toBe("Approve Alice's application")
  })

  it('emits confirm with null welcome message when textarea is empty', async () => {
    wrapper = mountDialog()
    await flushPromises()
    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-approve-dialog-confirm"]',
    )!
    confirm.click()
    await flushPromises()
    expect(wrapper!.emitted('confirm')?.[0]?.[0]).toEqual({ welcomeMessage: null })
  })

  it('emits confirm with trimmed welcome message when provided', async () => {
    wrapper = mountDialog()
    await flushPromises()
    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-approve-dialog-welcome"] textarea',
    )!
    textarea.value = '  Welcome aboard!  '
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    document
      .querySelector<HTMLButtonElement>('[data-testid="admin-creator-approve-dialog-confirm"]')!
      .click()
    await flushPromises()
    expect(wrapper!.emitted('confirm')?.[0]?.[0]).toEqual({
      welcomeMessage: 'Welcome aboard!',
    })
  })

  it('emits cancel + closes when the cancel button is clicked', async () => {
    wrapper = mountDialog()
    await flushPromises()
    document
      .querySelector<HTMLButtonElement>('[data-testid="admin-creator-approve-dialog-cancel"]')!
      .click()
    await flushPromises()
    expect(wrapper!.emitted('cancel')).toHaveLength(1)
    expect(wrapper!.emitted('update:modelValue')?.[0]?.[0]).toBe(false)
  })

  it('renders the resolved errorKey when one is provided', async () => {
    wrapper = mountDialog({ errorKey: 'creator.already_approved' })
    await flushPromises()
    const error = document.body.querySelector('[data-testid="admin-creator-approve-dialog-error"]')
    expect(error?.textContent?.trim()).toBe('This creator has already been approved.')
  })

  it('disables confirm + cancel while isSaving', async () => {
    wrapper = mountDialog({ isSaving: true })
    await flushPromises()
    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-approve-dialog-confirm"]',
    )!
    const cancel = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-approve-dialog-cancel"]',
    )!
    expect(confirm.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
  })
})
