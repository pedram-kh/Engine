/**
 * Component tests for the creator topbar nav (Sprint 5 Chunk B, D-b13).
 *
 * Pins: the topbar carries Dashboard + Availability router links; the link
 * matching the current route gets the active class; the labels localize
 * across en/pt/it; and the bar renders under the dark theme.
 *
 * Heavy bits irrelevant to the nav (the user-menu VMenu/VSelect, ThemeToggle)
 * are stubbed to keep the mount lean under jsdom.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'
import enApp from '@/core/i18n/locales/en/app.json'
import enAvailability from '@/core/i18n/locales/en/availability.json'
import itAvailability from '@/core/i18n/locales/it/availability.json'
import ptAvailability from '@/core/i18n/locales/pt/availability.json'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

import CreatorDashboardLayout from './CreatorDashboardLayout.vue'

async function mountLayout(
  options: { locale?: 'en' | 'pt' | 'it'; route?: string; dark?: boolean } = {},
) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const auth = useAuthStore()
  auth.user = {
    id: '01USERULIDXXXXXXXXXXXXXXXXX',
    type: 'users',
    attributes: {
      email: 'creator@example.com',
      email_verified_at: null,
      name: 'Test Creator',
      user_type: 'creator',
      preferred_language: 'en',
      preferred_currency: null,
      timezone: null,
    },
  } as never

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/creator/dashboard', name: 'creator.dashboard', component: { template: '<div />' } },
      {
        path: '/creator/availability',
        name: 'creator.availability',
        component: { template: '<div />' },
      },
      {
        path: '/creator/assignments',
        name: 'creator.assignments',
        component: { template: '<div />' },
      },
      { path: '/sign-in', name: 'auth.sign-in', component: { template: '<div />' } },
    ],
  })
  await router.push(options.route ?? '/creator/dashboard')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: options.locale ?? 'en',
    fallbackLocale: 'en',
    availableLocales: ['en', 'pt', 'it'],
    messages: {
      en: { ...enApp, ...enAvailability },
      pt: { ...enApp, ...ptAvailability },
      it: { ...enApp, ...itAvailability },
    } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({
    components: vuetifyComponents,
    directives: vuetifyDirectives,
    theme: {
      defaultTheme: options.dark === true ? 'dark' : 'light',
      themes: { light: lightTheme, dark: darkTheme },
    },
  })

  const wrapper = mount(CreatorDashboardLayout, {
    global: {
      plugins: [pinia, router, i18n, vuetify],
      stubs: { VMenu: true, VSelect: true, ThemeToggle: true },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return { wrapper, cleanup: () => wrapper.unmount() }
}

describe('CreatorDashboardLayout — topbar nav (D-b13)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    if (cleanup !== null) {
      cleanup()
      cleanup = null
    }
  })

  it('renders the Dashboard + Availability nav items', async () => {
    const mounted = await mountLayout()
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-nav-dashboard"]').exists()).toBe(true)
    expect(mounted.wrapper.find('[data-test="creator-nav-availability"]').text()).toBe(
      'Availability',
    )
  })

  // Sprint 8 Chunk 2 (D-10): the campaign-invitation surface gets its own
  // topbar entry between Dashboard and Availability.
  it('renders the Invitations nav item (and localizes it)', async () => {
    const en = await mountLayout()
    expect(en.wrapper.find('[data-test="creator-nav-assignments"]').text()).toBe('Invitations')
    en.cleanup()

    const pt = await mountLayout({ locale: 'pt' })
    expect(pt.wrapper.find('[data-test="creator-nav-assignments"]').text()).toBe('Convites')
    pt.cleanup()

    const it = await mountLayout({ locale: 'it' })
    cleanup = it.cleanup
    expect(it.wrapper.find('[data-test="creator-nav-assignments"]').text()).toBe('Inviti')
  })

  it('marks the Availability item active on its route (and Dashboard inactive)', async () => {
    const mounted = await mountLayout({ route: '/creator/availability' })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-nav-availability"]').classes()).toContain(
      'v-btn--active',
    )
    expect(mounted.wrapper.find('[data-test="creator-nav-dashboard"]').classes()).not.toContain(
      'v-btn--active',
    )
  })

  it('marks the Dashboard item active on its route', async () => {
    const mounted = await mountLayout({ route: '/creator/dashboard' })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-nav-dashboard"]').classes()).toContain(
      'v-btn--active',
    )
  })

  it('localizes the Availability label in pt and it', async () => {
    const pt = await mountLayout({ locale: 'pt' })
    expect(pt.wrapper.find('[data-test="creator-nav-availability"]').text()).toBe('Disponibilidade')
    pt.cleanup()

    const it = await mountLayout({ locale: 'it' })
    cleanup = it.cleanup
    expect(it.wrapper.find('[data-test="creator-nav-availability"]').text()).toBe('Disponibilità')
  })

  it('renders the nav under the dark theme', async () => {
    const mounted = await mountLayout({ dark: true })
    cleanup = mounted.cleanup
    expect(mounted.wrapper.find('[data-test="creator-nav"]').exists()).toBe(true)
    expect(mounted.wrapper.find('[data-test="creator-nav-availability"]').exists()).toBe(true)
  })
})
