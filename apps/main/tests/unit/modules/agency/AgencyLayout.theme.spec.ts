/**
 * AH-114 (S1.1) — the agency layout renders under the agency Vuetify themes.
 *
 * Pins, on the REAL `AgencyLayout` under a real Vuetify:
 *   - the themes are registered when the layout sets up, not before;
 *   - `<v-app>` carries `v-theme--agency-dark` with no stored preference and with a
 *     stored `dark`, and `v-theme--agency-light` with a stored `light` (the layout
 *     applies the stored preference itself: the global theme alone would stay dark);
 *   - the generated stylesheet carries both agency theme classes;
 *   - a teleported overlay (the user menu) carries the agency theme class;
 *   - toggling the preference at runtime flips the class.
 */

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enNotifications from '@/core/i18n/locales/en/notifications.json'
import { __resetThemePreferenceForTests, STORAGE_KEY } from '@/composables/useThemePreference'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { buildVuetifyOptions } from '@/plugins/vuetify'

vi.mock('@/modules/notifications/api/notifications.api', () => ({
  notificationsApi: {
    list: vi.fn().mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 8, last_page: 1, unread_count: 0 },
    }),
    unreadCount: vi.fn().mockResolvedValue({
      data: { type: 'notification_unread_count', attributes: { unread_count: 0 } },
    }),
    markRead: vi.fn().mockResolvedValue({}),
    readAll: vi.fn().mockResolvedValue({}),
  },
}))

import AgencyLayout from '@/modules/agency/layouts/AgencyLayout.vue'

const STYLE_ID = 'vuetify-theme-stylesheet'

let wrapper: VueWrapper | undefined

async function mountAgency(stored: 'dark' | 'light' | null) {
  window.localStorage.clear()
  if (stored !== null) window.localStorage.setItem(STORAGE_KEY, stored)
  __resetThemePreferenceForTests()

  const pinia = createPinia()
  setActivePinia(pinia)
  const user = {
    id: '01USERULIDXXXXXXXXXXXXXXXXX',
    type: 'users',
    attributes: {
      email: 'agent@example.com',
      email_verified_at: '2026-10-26T10:00:00Z',
      name: 'Ada Agent',
      user_type: 'agency_user',
      preferred_language: 'en',
      preferred_currency: null,
      timezone: null,
    },
  }
  useAuthStore().user = user as never
  useAgencyStore().initFromUser([
    { agency_id: '01HWALKAGENCY000000000000', agency_name: 'Northwind', role: 'agency_admin' },
  ] as never)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      'app.dashboard',
      'roster.list',
      'discover.list',
      'pools.list',
      'brands.list',
      'campaigns.list',
      'files.list',
      'messages.inbox',
      'agency-users.list',
      'settings',
      'notifications',
      'notifications.preferences',
      'auth.sign-in',
    ].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  })
  await router.push('/app.dashboard')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: { ...enApp, ...enNotifications } },
    missingWarn: false,
    fallbackWarn: false,
  })

  const vuetify = createVuetify({
    ...buildVuetifyOptions(false),
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })
  expect(vuetify.theme.themes.value['agency-dark']).toBeUndefined()

  wrapper = mount(
    {
      components: { AgencyLayout },
      props: { show: { type: Boolean, default: true } },
      template: '<AgencyLayout v-if="show"><div data-test="page" /></AgencyLayout>',
    },
    { attachTo: document.body, global: { plugins: [pinia, router, i18n, vuetify] } },
  )
  await flushPromises()
  return vuetify
}

function appRoot(): Element {
  const root = document.querySelector('[data-test="agency-layout"]')
  if (root === null) throw new Error('agency layout did not render')
  return root
}

describe('AgencyLayout theme', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
    window.localStorage.clear()
    __resetThemePreferenceForTests()
  })

  it('registers both agency themes when it sets up', async () => {
    const vuetify = await mountAgency(null)
    expect(vuetify.theme.themes.value['agency-dark']).toBeDefined()
    expect(vuetify.theme.themes.value['agency-light']).toBeDefined()
  })

  it.each([
    ['no stored preference', null, 'v-theme--agency-dark'],
    ['a stored dark preference', 'dark', 'v-theme--agency-dark'],
    ['a stored light preference', 'light', 'v-theme--agency-light'],
  ] as const)('renders %s under %s', async (_label, stored, expectedClass) => {
    await mountAgency(stored)
    const root = appRoot()
    expect(root.classList.contains('v-application')).toBe(true)
    expect(root.classList.contains(expectedClass)).toBe(true)
    // Never both, and never the global theme class on the agency root.
    const agencyClasses = [...root.classList].filter((c) => c.startsWith('v-theme--'))
    expect(agencyClasses).toEqual([expectedClass])
  })

  it('removes the themes and their stylesheet rules again when the layout unmounts', async () => {
    // Unmount the LAYOUT (as App.vue's layout switch does), not the app: the app's own
    // unmount would stop Vuetify's stylesheet watcher before the layout's hook ran.
    const vuetify = await mountAgency(null)
    expect(document.getElementById(STYLE_ID)?.innerHTML).toContain('.v-theme--agency-dark')

    await wrapper?.setProps({ show: false })
    await flushPromises()
    await nextTick()

    expect(document.querySelector('[data-test="agency-layout"]')).toBeNull()
    expect(vuetify.theme.themes.value['agency-dark']).toBeUndefined()
    expect(vuetify.theme.themes.value['agency-light']).toBeUndefined()
    expect(document.getElementById(STYLE_ID)?.innerHTML ?? '').not.toContain('agency')
  })

  it('the generated stylesheet carries both agency theme classes', async () => {
    await mountAgency(null)
    const sheet = document.getElementById(STYLE_ID)?.innerHTML ?? ''
    expect(sheet).toContain('.v-theme--agency-dark')
    expect(sheet).toContain('.v-theme--agency-light')
  })

  it('chrome inside the layout inherits the agency theme (app bar, drawer)', async () => {
    await mountAgency('light')
    expect(document.querySelector('[data-test="agency-topbar"]')?.className).toContain(
      'v-theme--agency-light',
    )
    expect(document.querySelector('[data-test="agency-sidebar"]')?.className).toContain(
      'v-theme--agency-light',
    )
  })

  it('a teleported overlay (the user menu) carries the agency theme class', async () => {
    await mountAgency('dark')
    const trigger = document.querySelector<HTMLElement>('[data-test="user-menu-btn"]')
    trigger?.click()
    await flushPromises()
    await nextTick()

    const overlay = document.querySelector('.v-overlay-container .v-overlay')
    expect(overlay).not.toBeNull()
    expect(overlay?.classList.contains('v-theme--agency-dark')).toBe(true)
  })

  it('switching the preference at runtime flips the class', async () => {
    await mountAgency('dark')
    expect(appRoot().classList.contains('v-theme--agency-dark')).toBe(true)

    const { useThemePreference } = await import('@/composables/useThemePreference')
    useThemePreference().setPreference('light')
    await flushPromises()
    expect(appRoot().classList.contains('v-theme--agency-light')).toBe(true)
    expect(appRoot().classList.contains('v-theme--agency-dark')).toBe(false)
  })
})
