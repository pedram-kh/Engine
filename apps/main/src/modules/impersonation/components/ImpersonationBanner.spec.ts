/**
 * ImpersonationBanner unit tests (Sprint 13, D-10).
 *
 * Focus: the banner is gated on store.active, renders the advisory
 * countdown, and the End button calls store.end() then bounces to a
 * clean anonymous session.
 */

import { flushPromises } from '@vue/test-utils'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enImpersonation from '@/core/i18n/locales/en/impersonation.json'

import { useImpersonationStore } from '../stores/useImpersonationStore'
import ImpersonationBanner from './ImpersonationBanner.vue'

function buildHarness() {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: { ...enImpersonation } },
  }) as unknown as ReturnType<typeof createI18n>
  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })

  // The banner is now a <v-system-bar>, which requires a Vuetify layout
  // (<v-app>) ancestor for its layout-item injection — mirror the real
  // mount site by wrapping it in a bare <v-app>.
  const Harness = defineComponent({
    name: 'ImpersonationBannerHarness',
    components: { VApp: vuetifyComponents.VApp, ImpersonationBanner },
    setup() {
      return () => h(vuetifyComponents.VApp, () => h(ImpersonationBanner))
    },
  })

  return mount(Harness, {
    global: { plugins: [i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

describe('ImpersonationBanner (Sprint 13, D-10)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
    document.body.innerHTML = ''
  })

  it('is hidden when not impersonating', () => {
    const wrapper = buildHarness()
    expect(wrapper.find('[data-testid="impersonation-banner"]').exists()).toBe(false)
  })

  it('renders the banner + advisory countdown when active', async () => {
    const store = useImpersonationStore()
    store.setActive(new Date(Date.now() + 90_000).toISOString())

    const wrapper = buildHarness()
    await flushPromises()

    expect(wrapper.find('[data-testid="impersonation-banner"]').exists()).toBe(true)
    // ~90s remaining → "1:30" (allow the 1s tick jitter on the seconds).
    expect(wrapper.find('[data-testid="impersonation-banner-countdown"]').text()).toMatch(
      /1:[23]\d/,
    )
  })

  it('ends the session and bounces to sign-in on End', async () => {
    const store = useImpersonationStore()
    store.setActive(new Date(Date.now() + 90_000).toISOString())
    const endSpy = vi.spyOn(store, 'end').mockResolvedValue(undefined)

    // jsdom's window.location.assign is non-configurable and cannot be
    // spied directly; swap the whole location object for the assertion.
    const originalLocation = window.location
    const assignSpy = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...originalLocation, assign: assignSpy },
    })

    const wrapper = buildHarness()
    await flushPromises()

    await wrapper.find('[data-testid="impersonation-banner-end"]').trigger('click')
    await flushPromises()

    expect(endSpy).toHaveBeenCalledOnce()
    expect(assignSpy).toHaveBeenCalledWith('/sign-in')

    Object.defineProperty(window, 'location', {
      configurable: true,
      value: originalLocation,
    })
  })
})
