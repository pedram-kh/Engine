/**
 * AH-114 (S1.2) — DOM pins for the restyled agency shell.
 *
 * The geometry itself (drawer 280, bar 65, nav item 44) is measured in a real browser by
 * `tools/walk/measure.mjs` (G_shell); jsdom has no layout. This spec pins the markup and
 * stylesheet hooks that produce it, so a template or CSS edit that drops one fails here:
 *   - drawer width 280 and the `agency-sidebar` hook;
 *   - top bar height 65, no elevation, the `agency-topbar` hook;
 *   - content padding steps up at `md`;
 *   - the avatar initial uses the theme's on-primary colour (not hard-coded white);
 *   - nav items keep `rounded-lg` and no per-item `active-color` override;
 *   - `agency.css` carries the geometry and the light-only logo fix, all in agency scope.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enNotifications from '@/core/i18n/locales/en/notifications.json'
import { __resetThemePreferenceForTests } from '@/composables/useThemePreference'
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

let wrapper: VueWrapper | undefined

const agencyCss = readFileSync(
  resolve(__dirname, '../../../../src/modules/agency/theme/agency.css'),
  'utf8',
)

async function mountAgency(): Promise<void> {
  window.localStorage.clear()
  __resetThemePreferenceForTests()

  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
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
  } as never
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

  wrapper = mount(
    {
      components: { AgencyLayout },
      template: '<AgencyLayout><div data-test="page" /></AgencyLayout>',
    },
    { attachTo: document.body, global: { plugins: [pinia, router, i18n, vuetify] } },
  )
  await flushPromises()
}

function el(selector: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(selector)
  if (found === null) throw new Error(`not rendered: ${selector}`)
  return found
}

describe('AgencyLayout shell (S1.2)', () => {
  beforeEach(async () => {
    document.body.innerHTML = ''
    await mountAgency()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
    window.localStorage.clear()
    __resetThemePreferenceForTests()
  })

  it('the drawer is 280 wide and carries the agency-sidebar hook', () => {
    const drawer = el('[data-test="agency-sidebar"]')
    expect(drawer.classList.contains('agency-sidebar')).toBe(true)
    expect(drawer.style.width).toBe('280px')
  })

  it('the top bar is 65 high, flat, and carries the agency-topbar hook', () => {
    const bar = el('[data-test="agency-topbar"]')
    expect(bar.classList.contains('agency-topbar')).toBe(true)
    expect(bar.classList.contains('elevation-0')).toBe(true)
    expect(bar.querySelector<HTMLElement>('.v-toolbar__content')?.style.height).toBe('65px')
  })

  it('the content container pads 24 and steps up to 32 from md', () => {
    const container = el('[data-test="agency-main"] .v-container')
    expect(container.classList.contains('pa-6')).toBe(true)
    expect(container.classList.contains('pa-md-8')).toBe(true)
  })

  it('the avatar initial uses the theme on-primary colour, not hard-coded white', () => {
    const label = el('[data-test="user-menu-btn"] .v-avatar span')
    expect(label.classList.contains('text-on-primary')).toBe(true)
    expect(label.classList.contains('text-white')).toBe(false)
  })

  it('nav items are rounded-lg and keep no per-item active colour override', () => {
    const items = [...document.querySelectorAll<HTMLElement>('[data-test^="nav-"]')]
    expect(items.length).toBeGreaterThan(0)
    for (const item of items) {
      expect(item.classList.contains('rounded-lg')).toBe(true)
      expect(item.classList.contains('text-primary')).toBe(false)
    }
  })
})

describe('agency.css shell rules (S1.2)', () => {
  const rule = (needle: string): string => {
    const start = agencyCss.indexOf(needle)
    if (start === -1) throw new Error(`rule not found: ${needle}`)
    return agencyCss.slice(start, agencyCss.indexOf('}', start) + 1)
  }

  it('nav items are 44 high with an 8px-radius hook and a transparent resting border', () => {
    const r = rule('.agency-sidebar .v-list-item {')
    expect(r).toContain('min-height: 44px')
    expect(r).toContain('border: 1px solid transparent')
  })

  it('the active nav item takes the container fill, accent border and primary-text label', () => {
    const r = rule('.agency-sidebar .v-list-item--active {')
    expect(r).toContain('--v-theme-primary-container')
    expect(r).toContain('border-color: rgb(var(--v-theme-primary))')
    expect(r).toContain('--v-theme-primary-text')
  })

  it('the top bar background out-specifies the library toolbar rule', () => {
    expect(agencyCss).toContain('.agency-topbar.v-toolbar {')
    expect(rule('.agency-topbar.v-toolbar {')).toContain('--v-theme-topbar')
  })

  it('the separator lives inside the 65px bar (pseudo-element, not a border)', () => {
    const r = rule('.agency-topbar::after {')
    expect(r).toContain('height: 1px')
    expect(r).toContain('bottom: 0')
  })

  it('the wordmark inversion applies to the light theme only', () => {
    expect(agencyCss).toMatch(
      /\.v-theme--agency-light\)\.v-theme--agency-light \.agency-sidebar__logo \{\s*filter: invert\(1\) hue-rotate\(180deg\);/,
    )
    expect(agencyCss).not.toMatch(/agency-dark\)\.v-theme--agency-dark \.agency-sidebar__logo/)
  })
})
