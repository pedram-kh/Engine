/**
 * AH-115 L0.3 — the agency tree never carries a creator class.
 *
 * The mirror of AH-114's `creator-no-agency-theme`. The real `App.vue` is mounted on agency
 * routes under a real Vuetify and the document body is searched (teleported overlays
 * included), plus the `<html>` and `<body>` class lists, for anything the creator look puts
 * on a page: the `v-theme--creator-*` classes, the creator shell's DOM modifier classes and
 * the `catalyst-native` class (which a creator web session must never put on `<html>`).
 *
 * The cross-navigation form: a creator route first, then a client-side push to an agency
 * route. The agency render must carry no creator marker and the Vuetify theme stylesheet
 * must hold no creator theme once the creator layout has unmounted (its themes are
 * unregistered with it, as the agency's are).
 *
 * The worst case (both creator themes sitting in the Vuetify instance, as after a creator
 * visit) is added with the theme module in L0.4.
 */

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import enCreator from '@/core/i18n/locales/en/creator.json'
import enNotifications from '@/core/i18n/locales/en/notifications.json'
import { buildVuetifyOptions } from '@/plugins/vuetify'
import { useAgencyStore } from '@/core/stores/useAgencyStore'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { __resetThemePreferenceForTests } from '@/composables/useThemePreference'

vi.mock('@/core/platform/isNativePlatform', () => ({ isNativePlatform: () => false }))

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

// App.vue probes impersonation status on mount; keep it inert (no live network).
vi.mock('@/modules/impersonation/api/impersonation.api', () => ({
  impersonationApi: {
    claim: vi.fn(),
    status: vi.fn().mockResolvedValue({ data: { active: false } }),
    end: vi.fn(),
  },
  readHandoffToken: () => null,
}))

vi.mock('@/modules/onboarding/api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn().mockResolvedValue({
      data: {
        type: 'creators',
        attributes: { application_status: 'incomplete' },
        wizard: { next_step: 'profile', is_submitted: false, steps: [], flags: {} },
      },
    }),
  },
}))

import App from '@/App.vue'

const Page = { template: '<div data-test="route-page" />' }

const AGENCY_NAMES = [
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
]

const CREATOR_NAMES = [
  'creator.dashboard',
  'creator.availability',
  'creator.assignments',
  'creator.messages',
  'creator.jobs',
  'creator.profile',
  'creator.security',
  'creator.notifications',
  'creator.notifications.preferences',
]

const ROUTES: RouteRecordRaw[] = [
  ...CREATOR_NAMES.map(
    (name): RouteRecordRaw => ({
      path: `/${name.replace(/\./g, '/')}`,
      name,
      component: Page,
      meta: { layout: 'creator' },
    }),
  ),
  ...AGENCY_NAMES.map(
    (name): RouteRecordRaw => ({
      path: `/agency/${name}`,
      name,
      component: Page,
      meta: { layout: 'agency' },
    }),
  ),
]

/** What the creator look puts on a page. */
const CREATOR_MARKER =
  /v-theme--creator|creator-(dark|light)\b|creator-topbar|creator-main|creator-bottom-nav|catalyst-native/

let wrapper: VueWrapper | undefined

async function mountAt(path: string, theme: 'dark' | 'light') {
  __resetThemePreferenceForTests()
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: '01USERULIDXXXXXXXXXXXXXXXXX',
    type: 'users',
    attributes: {
      email: 'agency@example.com',
      email_verified_at: '2026-10-26T10:00:00Z',
      name: 'Test Agency',
      user_type: 'agency_user',
      preferred_language: 'en',
      preferred_currency: null,
      timezone: null,
    },
  } as never

  useAgencyStore().initFromUser([
    { agency_id: '01HWALKAGENCY000000000000', agency_name: 'Northwind', role: 'agency_admin' },
  ] as never)

  const router = createRouter({ history: createMemoryHistory(), routes: ROUTES })
  await router.push(path)
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: { ...enApp, ...enAuth, ...enCreator, ...enNotifications } },
    missingWarn: false,
    fallbackWarn: false,
  })

  const vuetify = createVuetify({
    ...buildVuetifyOptions(false),
    components: vuetifyComponents,
    directives: vuetifyDirectives,
  })
  vuetify.theme.change(theme)

  wrapper = mount(App, {
    attachTo: document.body,
    global: { plugins: [pinia, router, i18n, vuetify] },
  })
  await flushPromises()
  return { vuetify, router }
}

function expectNoCreatorMarker() {
  expect(document.body.outerHTML).not.toMatch(CREATOR_MARKER)
  expect(document.documentElement.className).not.toMatch(CREATOR_MARKER)
  expect(document.body.className).not.toMatch(CREATOR_MARKER)
  expect(document.querySelectorAll('[class*="v-theme--creator"]')).toHaveLength(0)
}

describe('the agency tree never carries a creator class', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
  })

  const CASES: ReadonlyArray<[string, 'dark' | 'light']> = AGENCY_NAMES.flatMap((name) => [
    [`/agency/${name}`, 'dark' as const],
    [`/agency/${name}`, 'light' as const],
  ])

  it.each(CASES)('%s (%s)', async (path, theme) => {
    await mountAt(path, theme)

    expect(document.querySelector('[data-test="agency-layout"]')).not.toBeNull()
    expectNoCreatorMarker()
    expect(document.getElementById('vuetify-theme-stylesheet')?.innerHTML ?? '').not.toMatch(
      /creator-(dark|light)/,
    )
  })

  describe('after a creator page has rendered in the same session', () => {
    it.each(['dark', 'light'] as const)('navigating creator -> agency (%s)', async (theme) => {
      const { router } = await mountAt('/creator/dashboard', theme)
      expect(document.querySelector('[data-test="agency-layout"]')).toBeNull()

      await router.push('/agency/campaigns.list')
      await flushPromises()

      expect(document.querySelector('[data-test="agency-layout"]')).not.toBeNull()
      expectNoCreatorMarker()
      expect(document.getElementById('vuetify-theme-stylesheet')?.innerHTML ?? '').not.toMatch(
        /creator-(dark|light)/,
      )
    })
  })
})
