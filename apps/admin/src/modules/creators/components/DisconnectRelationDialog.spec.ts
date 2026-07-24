/**
 * DisconnectRelationDialog unit tests — AH-051 (D-6/D-9).
 *
 * Confirms the 10-char minimum (mirroring AdminDisconnectRequest), the
 * required-reason gate, the {name}/{agency} title interpolation, error
 * rendering, and the confirm / cancel emit contract.
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

import DisconnectRelationDialog from './DisconnectRelationDialog.vue'

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
    agencyName: string
  }> = {},
): VueWrapper<unknown> {
  const i18n = buildI18n()
  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })
  return mount(DisconnectRelationDialog, {
    props: {
      modelValue: props.modelValue ?? true,
      isSaving: props.isSaving ?? false,
      errorKey: props.errorKey ?? null,
      creatorDisplayName: props.creatorDisplayName ?? 'Jane Doe',
      agencyName: props.agencyName ?? 'Acme Talent',
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

describe('DisconnectRelationDialog', () => {
  let wrapper: VueWrapper<unknown> | null = null

  beforeEach(() => {
    document.body.innerHTML = ''
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('renders the i18n title with the creator + agency names', async () => {
    wrapper = mountDialog({ creatorDisplayName: 'Bob', agencyName: 'Nova' })
    await flushPromises()
    const title = document.body.querySelector(
      '[data-testid="admin-creator-disconnect-dialog-title"]',
    )
    expect(title?.textContent?.trim()).toBe('Disconnect Bob from Nova?')
  })

  it('disables confirm when the reason is empty', async () => {
    wrapper = mountDialog()
    await flushPromises()
    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-disconnect-dialog-confirm"]',
    )
    expect(confirm?.disabled).toBe(true)
  })

  it('disables confirm below the 10-char minimum', async () => {
    wrapper = mountDialog()
    await flushPromises()
    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-disconnect-dialog-reason"] textarea',
    )!
    textarea.value = 'too short'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-disconnect-dialog-confirm"]',
    )
    expect(confirm?.disabled).toBe(true)
  })

  it('enables confirm + emits the trimmed reason once the threshold is met', async () => {
    wrapper = mountDialog()
    await flushPromises()
    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-disconnect-dialog-reason"] textarea',
    )!
    textarea.value = '  Agency requested offboarding.  '
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-testid="admin-creator-disconnect-dialog-confirm"]',
    )!
    expect(confirm.disabled).toBe(false)
    confirm.click()
    await flushPromises()
    expect(wrapper!.emitted('confirm')?.[0]?.[0]).toEqual({
      reason: 'Agency requested offboarding.',
    })
  })

  it('renders the resolved errorKey when one is provided', async () => {
    wrapper = mountDialog({ errorKey: 'admin.creators.detail.connections.disconnect.failed' })
    await flushPromises()
    const error = document.body.querySelector(
      '[data-testid="admin-creator-disconnect-dialog-error"]',
    )
    expect(error?.textContent?.trim()).toBe(
      "We couldn't disconnect this relationship. Please try again.",
    )
  })

  it('emits cancel + closes when the cancel button is clicked', async () => {
    wrapper = mountDialog()
    await flushPromises()
    document
      .querySelector<HTMLButtonElement>('[data-testid="admin-creator-disconnect-dialog-cancel"]')!
      .click()
    await flushPromises()
    expect(wrapper!.emitted('cancel')).toHaveLength(1)
    expect(wrapper!.emitted('update:modelValue')?.[0]?.[0]).toBe(false)
  })
})
