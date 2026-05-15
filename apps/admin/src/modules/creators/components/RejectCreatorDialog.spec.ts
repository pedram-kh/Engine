/**
 * RejectCreatorDialog unit tests — Sprint 3 Chunk 4 sub-step 10.
 *
 * Confirms the 10-char minimum, the required-reason gate, the
 * already-rejected error rendering, and the save / cancel emit
 * contract.
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

import RejectCreatorDialog from './RejectCreatorDialog.vue'

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
  return mount(RejectCreatorDialog, {
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

describe('RejectCreatorDialog', () => {
  let wrapper: VueWrapper<unknown> | null = null

  beforeEach(() => {
    document.body.innerHTML = ''
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('renders the i18n title with the creator display name', async () => {
    wrapper = mountDialog({ creatorDisplayName: 'Bob' })
    await flushPromises()
    const title = document.body.querySelector('[data-testid="admin-creator-reject-dialog-title"]')
    expect(title?.textContent?.trim()).toBe("Reject Bob's application")
  })

  it('disables confirm when rejection_reason is empty', async () => {
    wrapper = mountDialog()
    await flushPromises()
    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-reject-dialog-confirm"]',
    )
    expect(confirm?.disabled).toBe(true)
  })

  it('disables confirm when rejection_reason is below the 10-char minimum', async () => {
    wrapper = mountDialog()
    await flushPromises()
    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-reject-dialog-reason"] textarea',
    )!
    textarea.value = 'too short'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-reject-dialog-confirm"]',
    )
    expect(confirm?.disabled).toBe(true)
  })

  it('enables confirm + emits confirm with trimmed reason once threshold is met', async () => {
    wrapper = mountDialog()
    await flushPromises()
    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-reject-dialog-reason"] textarea',
    )!
    textarea.value = '  Insufficient evidence of identity.  '
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-reject-dialog-confirm"]',
    )!
    expect(confirm.disabled).toBe(false)
    confirm.click()
    await flushPromises()
    expect(wrapper!.emitted('confirm')?.[0]?.[0]).toEqual({
      rejectionReason: 'Insufficient evidence of identity.',
    })
  })

  it('renders the resolved errorKey when one is provided', async () => {
    wrapper = mountDialog({ errorKey: 'creator.already_rejected' })
    await flushPromises()
    const error = document.body.querySelector('[data-testid="admin-creator-reject-dialog-error"]')
    expect(error?.textContent?.trim()).toBe('This creator has already been rejected.')
  })

  it('emits cancel + closes when the cancel button is clicked', async () => {
    wrapper = mountDialog()
    await flushPromises()
    document
      .querySelector<HTMLButtonElement>('[data-testid="admin-creator-reject-dialog-cancel"]')!
      .click()
    await flushPromises()
    expect(wrapper!.emitted('cancel')).toHaveLength(1)
    expect(wrapper!.emitted('update:modelValue')?.[0]?.[0]).toBe(false)
  })
})
