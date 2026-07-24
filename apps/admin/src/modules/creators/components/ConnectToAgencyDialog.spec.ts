/**
 * ConnectToAgencyDialog unit tests — AH-051 (D-4/D-5/D-9).
 *
 * Pins the two-door contract:
 *   - Door 1 (request): agency required, NO reason → confirm emits mode=request,
 *     reason=null.
 *   - Door 2 (direct): reason becomes MANDATORY (min 10); confirm stays disabled
 *     until both an agency AND a >=10-char reason are present, then emits
 *     mode=direct with the trimmed reason.
 * Also pins the debounced `search` emit and the cancel/close contract.
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

import ConnectToAgencyDialog, { type AgencyOption } from './ConnectToAgencyDialog.vue'

function buildI18n() {
  return createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    messages: { en: { ...enApp, ...enAuth, ...enCreators } } as never,
  }) as unknown as ReturnType<typeof createI18n>
}

const AGENCIES: AgencyOption[] = [
  { ulid: '01AGENCYONEXXXXXXXXXXXXXXXX', name: 'Nova Talent' },
  { ulid: '01AGENCYTWOXXXXXXXXXXXXXXXX', name: 'Acme Creators' },
]

function mountDialog(
  props: Partial<{
    modelValue: boolean
    isSaving: boolean
    errorKey: string | null
    creatorDisplayName: string
    agencies: ReadonlyArray<AgencyOption>
    isSearching: boolean
  }> = {},
): VueWrapper<InstanceType<typeof ConnectToAgencyDialog>> {
  const i18n = buildI18n()
  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })
  return mount(ConnectToAgencyDialog, {
    props: {
      modelValue: props.modelValue ?? true,
      isSaving: props.isSaving ?? false,
      errorKey: props.errorKey ?? null,
      creatorDisplayName: props.creatorDisplayName ?? 'Jane Doe',
      agencies: props.agencies ?? AGENCIES,
      isSearching: props.isSearching ?? false,
    },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  }) as VueWrapper<InstanceType<typeof ConnectToAgencyDialog>>
}

function confirmBtn(): HTMLButtonElement {
  return document.body.querySelector<HTMLButtonElement>(
    '[data-testid="admin-creator-connect-dialog-confirm"]',
  )!
}

describe('ConnectToAgencyDialog', () => {
  let wrapper: VueWrapper<InstanceType<typeof ConnectToAgencyDialog>> | null = null

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
    const title = document.body.querySelector('[data-testid="admin-creator-connect-dialog-title"]')
    expect(title?.textContent?.trim()).toBe('Connect Bob to an agency')
  })

  it('disables confirm until an agency is selected (Door 1, request)', async () => {
    wrapper = mountDialog()
    await flushPromises()
    expect(confirmBtn().disabled).toBe(true)

    // Select an agency via the component's v-model (autocomplete internals are
    // heavy to drive through the DOM; the gate logic is the unit under test).
    wrapper.vm.selectedAgencyId = AGENCIES[0]!.ulid
    await flushPromises()
    expect(confirmBtn().disabled).toBe(false)
  })

  it('request door emits mode=request with reason=null (no reason field shown)', async () => {
    wrapper = mountDialog()
    await flushPromises()
    wrapper.vm.selectedAgencyId = AGENCIES[0]!.ulid
    await flushPromises()

    // The reason textarea is hidden in request mode.
    expect(
      document.body.querySelector('[data-testid="admin-creator-connect-dialog-reason"]'),
    ).toBeNull()

    confirmBtn().click()
    await flushPromises()
    expect(wrapper.emitted('confirm')?.[0]?.[0]).toEqual({
      agencyId: AGENCIES[0]!.ulid,
      mode: 'request',
      reason: null,
    })
  })

  it('direct door reveals a reason field and requires >=10 chars before confirming', async () => {
    wrapper = mountDialog()
    await flushPromises()
    wrapper.vm.selectedAgencyId = AGENCIES[1]!.ulid
    wrapper.vm.mode = 'direct'
    await flushPromises()

    // Reason now shown; confirm blocked until the 10-char minimum is met.
    const textarea = document.body.querySelector<HTMLTextAreaElement>(
      '[data-testid="admin-creator-connect-dialog-reason"] textarea',
    )
    expect(textarea).not.toBeNull()
    expect(confirmBtn().disabled).toBe(true)

    textarea!.value = '  Signed offline agreement on file.  '
    textarea!.dispatchEvent(new Event('input'))
    await flushPromises()

    expect(confirmBtn().disabled).toBe(false)
    confirmBtn().click()
    await flushPromises()
    expect(wrapper.emitted('confirm')?.[0]?.[0]).toEqual({
      agencyId: AGENCIES[1]!.ulid,
      mode: 'direct',
      reason: 'Signed offline agreement on file.',
    })
  })

  it('emits a debounced search event when the query changes', async () => {
    wrapper = mountDialog()
    await flushPromises()
    wrapper.vm.searchQuery = 'nova'
    await flushPromises()
    const searchEvents = wrapper.emitted('search') as string[][] | undefined
    expect(searchEvents?.some((call) => call[0] === 'nova')).toBe(true)
  })

  it('emits cancel + closes when the cancel button is clicked', async () => {
    wrapper = mountDialog()
    await flushPromises()
    document
      .querySelector<HTMLButtonElement>('[data-testid="admin-creator-connect-dialog-cancel"]')!
      .click()
    await flushPromises()
    expect(wrapper.emitted('cancel')).toHaveLength(1)
    expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toBe(false)
  })
})
