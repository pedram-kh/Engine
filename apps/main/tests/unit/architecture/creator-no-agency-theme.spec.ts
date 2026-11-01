/**
 * AH-114 spec 7 — the creator and onboarding trees never carry an agency theme
 * class. The real `App.vue` is mounted on creator, onboarding and auth routes under
 * a real Vuetify with BOTH agency themes registered (the worst case: the themes
 * exist in the instance, as they do in a session that has visited an agency page)
 * and the document body is searched (teleported overlays included) plus the `<html>`
 * and `<body>` class lists. The theme stylesheet in `<head>` is out of scope here:
 * registered agency rules sit in it by design; spec 1 and S1.1 pin that side.
 *
 * Complements spec 2 (`agency-theme-isolation`, the import graph): that proves the
 * creator tree cannot import agency theme code; this proves nothing it renders
 * picks the agency theme up anyway. The agency-then-creator navigation form is
 * added with the layout wiring (S1.1).
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
import { registerAgencyThemes } from '@/modules/agency/theme/agencyThemes'
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

const ROUTES: RouteRecordRaw[] = [
  {
    path: '/creator/dashboard',
    name: 'creator.dashboard',
    component: Page,
    meta: { layout: 'creator' },
  },
  {
    path: '/creator/availability',
    name: 'creator.availability',
    component: Page,
    meta: { layout: 'creator' },
  },
  {
    path: '/creator/assignments',
    name: 'creator.assignments',
    component: Page,
    meta: { layout: 'creator' },
  },
  {
    path: '/creator/messages',
    name: 'creator.messages',
    component: Page,
    meta: { layout: 'creator' },
  },
  { path: '/creator/jobs', name: 'creator.jobs', component: Page, meta: { layout: 'creator' } },
  {
    path: '/creator/profile',
    name: 'creator.profile',
    component: Page,
    meta: { layout: 'creator' },
  },
  {
    path: '/creator/security',
    name: 'creator.security',
    component: Page,
    meta: { layout: 'creator' },
  },
  {
    path: '/creator/notifications',
    name: 'creator.notifications',
    component: Page,
    meta: { layout: 'creator' },
  },
  {
    path: '/creator/notifications/preferences',
    name: 'creator.notifications.preferences',
    component: Page,
    meta: { layout: 'creator' },
  },
  {
    path: '/onboarding/profile',
    name: 'onboarding.profile',
    component: Page,
    meta: { layout: 'onboarding' },
  },
  {
    path: '/onboarding/kyc',
    name: 'onboarding.kyc',
    component: Page,
    meta: { layout: 'onboarding' },
  },
  { path: '/sign-in', name: 'auth.sign-in', component: Page, meta: { layout: 'auth' } },
  // The agency shell: the nav targets the layout links to, all on the agency layout.
  ...[
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
  ].map(
    (name): RouteRecordRaw => ({
      path: `/agency/${name}`,
      name,
      component: Page,
      meta: { layout: 'agency' },
    }),
  ),
]

let wrapper: VueWrapper | undefined

async function mountAt(path: string, theme: 'dark' | 'light') {
  __resetThemePreferenceForTests()
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: '01USERULIDXXXXXXXXXXXXXXXXX',
    type: 'users',
    attributes: {
      email: 'creator@example.com',
      email_verified_at: '2026-10-26T10:00:00Z',
      name: 'Test Creator',
      user_type: 'creator',
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
  registerAgencyThemes(vuetify)
  vuetify.theme.change(theme)

  wrapper = mount(App, {
    attachTo: document.body,
    global: { plugins: [pinia, router, i18n, vuetify] },
  })
  await flushPromises()
  return { vuetify, router }
}

describe('creator, onboarding and auth trees never carry an agency theme class', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
  })

  const CASES: ReadonlyArray<[string, string, 'dark' | 'light']> = [
    ['creator dashboard', '/creator/dashboard', 'dark'],
    ['creator dashboard', '/creator/dashboard', 'light'],
    ['onboarding', '/onboarding/profile', 'dark'],
    ['onboarding', '/onboarding/profile', 'light'],
    ['auth', '/sign-in', 'dark'],
    ['auth', '/sign-in', 'light'],
  ]

  it.each(CASES)('%s (%s, %s)', async (_label, path, theme) => {
    const { vuetify } = await mountAt(path, theme)

    // The agency themes ARE in the instance (worst case), and the creator route
    // still renders its own global theme.
    expect(vuetify.theme.themes.value['agency-dark']).toBeDefined()
    expect(document.querySelector('.v-application')).not.toBeNull()
    expect(document.querySelector(`.v-application.v-theme--${theme}`)).not.toBeNull()

    // <body> only: the theme stylesheet in <head> legitimately holds the agency rules.
    expect(document.body.outerHTML).not.toMatch(/v-theme--agency/)
    expect(document.documentElement.className).not.toMatch(/agency/)
    expect(document.body.className).not.toMatch(/agency/)
    expect(document.querySelectorAll('[class*="v-theme--agency"]')).toHaveLength(0)
  })

  describe('after an agency page has rendered in the same session', () => {
    it.each(['/creator/dashboard', '/onboarding/profile', '/sign-in'])(
      'navigating agency -> %s leaves no agency class anywhere',
      async (target) => {
        const { router } = await mountAt('/agency/app.dashboard', 'dark')

        // The agency layout IS on the agency theme (the precondition of the test).
        expect(
          document.querySelector('[data-test="agency-layout"].v-theme--agency-dark'),
        ).not.toBeNull()

        await router.push(target)
        await flushPromises()

        expect(document.querySelector('[data-test="agency-layout"]')).toBeNull()
        expect(document.querySelector('.v-application')).not.toBeNull()
        expect(document.body.outerHTML).not.toMatch(/v-theme--agency/)
        expect(document.documentElement.className).not.toMatch(/agency/)
        expect(document.body.className).not.toMatch(/agency/)
        expect(document.querySelectorAll('[class*="v-theme--agency"]')).toHaveLength(0)
        // ...and the agency layout took its theme rules out of the stylesheet with it.
        expect(document.getElementById('vuetify-theme-stylesheet')?.innerHTML ?? '').not.toContain(
          'agency',
        )
      },
    )
  })
})
