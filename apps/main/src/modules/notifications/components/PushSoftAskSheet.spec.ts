import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enNotifications from '@/core/i18n/locales/en/notifications.json'

const state = vi.hoisted(() => ({ available: true }))
const registration = vi.hoisted(() => ({
  isSoftAskDue: vi.fn<() => Promise<boolean>>(),
  accept: vi.fn<() => Promise<string>>(),
  decline: vi.fn<() => void>(),
}))
const factory = vi.hoisted(() => vi.fn())

vi.mock('@/core/platform/push/pushChannelAvailable', () => ({
  pushChannelAvailable: () => state.available,
}))
vi.mock('@/core/platform/push/usePushRegistration', () => ({
  usePushRegistration: () => {
    factory()
    return registration
  },
}))

import PushSoftAskSheet from './PushSoftAskSheet.vue'

function mountSheet(eligible = true) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enNotifications } as never,
  }) as unknown as ReturnType<typeof createI18n>
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(PushSoftAskSheet, {
    props: { eligible },
    global: { plugins: [i18n, vuetify] },
    attachTo: document.body,
  })
}

/** The sheet teleports to the overlay container, so query the document. */
const q = (selector: string): HTMLElement | null => document.querySelector<HTMLElement>(selector)

let wrapper: ReturnType<typeof mountSheet> | null = null

beforeEach(() => {
  state.available = true
  registration.isSoftAskDue.mockReset().mockResolvedValue(true)
  registration.accept.mockReset().mockResolvedValue('granted')
  registration.decline.mockReset()
  factory.mockReset()
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})

describe('PushSoftAskSheet', () => {
  it('shows the sheet, with its copy, when the ask is due', async () => {
    wrapper = mountSheet()
    await flushPromises()

    expect(q('[data-test="push-soft-ask-card"]')?.closest('.v-overlay--active')).not.toBeNull()
    expect(q('[data-test="push-soft-ask-title"]')?.textContent).toContain('Never miss an update')
    expect(q('[data-test="push-soft-ask-body"]')?.textContent).toContain(
      "Get notified the moment you're approved, invited or messaged.",
    )
    expect(q('[data-test="push-soft-ask-note"]')?.textContent).toContain('never show message text')
    expect(registration.accept).not.toHaveBeenCalled()
  })

  it('accept → asks the registration once and closes', async () => {
    wrapper = mountSheet()
    await flushPromises()

    q('[data-test="push-soft-ask-accept"]')?.click()
    await flushPromises()

    expect(registration.accept).toHaveBeenCalledTimes(1)
    expect(registration.decline).not.toHaveBeenCalled()
    expect(q('[data-test="push-soft-ask-card"]')?.closest('.v-overlay--active')).toBeNull()
  })

  it('accept still closes when the registration rejects', async () => {
    registration.accept.mockRejectedValue(new Error('boom'))
    wrapper = mountSheet()
    await flushPromises()

    q('[data-test="push-soft-ask-accept"]')?.click()
    await flushPromises()

    expect(q('[data-test="push-soft-ask-card"]')?.closest('.v-overlay--active') ?? null).toBeNull()
  })

  it('decline → starts the cool-down and never raises the OS prompt', async () => {
    wrapper = mountSheet()
    await flushPromises()

    q('[data-test="push-soft-ask-decline"]')?.click()
    await flushPromises()

    expect(registration.decline).toHaveBeenCalledTimes(1)
    expect(registration.accept).not.toHaveBeenCalled()
    expect(q('[data-test="push-soft-ask-card"]')?.closest('.v-overlay--active') ?? null).toBeNull()
  })

  it('stays closed when the ask is not due (cool-down, already granted, OS-denied)', async () => {
    registration.isSoftAskDue.mockResolvedValue(false)
    wrapper = mountSheet()
    await flushPromises()

    expect(q('[data-test="push-soft-ask-card"]')).toBeNull()
  })

  it('stays closed when the due-check itself fails', async () => {
    registration.isSoftAskDue.mockRejectedValue(new Error('bridge down'))
    wrapper = mountSheet()
    await flushPromises()

    expect(q('[data-test="push-soft-ask-card"]')).toBeNull()
  })

  it('renders nothing and consults nothing where push is unavailable (web; iOS before AH-102-ios)', async () => {
    state.available = false
    wrapper = mountSheet()
    await flushPromises()

    expect(q('[data-test="push-soft-ask-card"]')).toBeNull()
    expect(factory).not.toHaveBeenCalled()
    expect(registration.isSoftAskDue).not.toHaveBeenCalled()
  })

  it('does not ask a creator who is not eligible (rejected, mid-wizard) — and asks once they are', async () => {
    wrapper = mountSheet(false)
    await flushPromises()
    expect(registration.isSoftAskDue).not.toHaveBeenCalled()
    expect(q('[data-test="push-soft-ask-card"]')).toBeNull()

    await wrapper.setProps({ eligible: true })
    await flushPromises()

    expect(registration.isSoftAskDue).toHaveBeenCalledTimes(1)
    expect(q('[data-test="push-soft-ask-card"]')).not.toBeNull()
  })

  it('evaluates once per mount (a status flip after the answer does not re-open it)', async () => {
    wrapper = mountSheet()
    await flushPromises()
    q('[data-test="push-soft-ask-decline"]')?.click()
    await flushPromises()

    await wrapper.setProps({ eligible: false })
    await wrapper.setProps({ eligible: true })
    await flushPromises()

    expect(registration.isSoftAskDue).toHaveBeenCalledTimes(1)
  })
})
