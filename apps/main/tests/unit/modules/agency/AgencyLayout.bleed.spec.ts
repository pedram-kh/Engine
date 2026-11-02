/**
 * AH-114 (S3.4) — the agency content container is padded on every page except Messages.
 *
 * Messages is a full-bleed two-pane surface (the list pane meets the sidebar, the thread meets
 * the right edge), so `AgencyLayout` drops the container padding when the matched route chain
 * contains `messages.inbox`: on the inbox itself AND on the `messages.thread` child. Every other
 * route keeps `pa-6 pa-md-8` (pinned in `AgencyLayout.shell.spec.ts`).
 *
 * Break-revert: key `fullBleed` on a different route name → the messages cases fail.
 */

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'
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

async function containerClassesAt(path: string): Promise<string[]> {
  document.body.innerHTML = ''
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

  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      ...[
        'app.dashboard',
        'roster.list',
        'discover.list',
        'pools.list',
        'brands.list',
        'campaigns.list',
        'files.list',
        'agency-users.list',
        'settings',
        'notifications',
        'notifications.preferences',
        'auth.sign-in',
      ].map((name) => ({ path: `/${name}`, name, component: stub })),
      {
        path: '/messages',
        name: 'messages.inbox',
        component: stub,
        children: [{ path: ':creatorUlid', name: 'messages.thread', component: stub }],
      },
    ],
  })
  await router.push(path)
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
  const container = document.querySelector('[data-test="agency-main"] .v-container')
  if (container === null) throw new Error('container not rendered')
  return [...container.classList]
}

describe('AgencyLayout full-bleed container (S3.4)', () => {
  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
    window.localStorage.clear()
    __resetThemePreferenceForTests()
  })

  it('drops the padding on the messages inbox', async () => {
    const classes = await containerClassesAt('/messages')
    expect(classes).toContain('pa-0')
    expect(classes).not.toContain('pa-6')
    expect(classes).not.toContain('pa-md-8')
  })

  it('keeps it dropped for a conversation open in the right pane', async () => {
    const classes = await containerClassesAt('/messages/01HCREATORMAYA00000000001')
    expect(classes).toContain('pa-0')
    expect(classes).not.toContain('pa-6')
  })

  it('leaves every other page padded', async () => {
    for (const path of ['/app.dashboard', '/campaigns.list', '/settings']) {
      const classes = await containerClassesAt(path)
      expect(classes, path).toContain('pa-6')
      expect(classes, path).toContain('pa-md-8')
      expect(classes, path).not.toContain('pa-0')
      wrapper?.unmount()
      wrapper = undefined
    }
  })
})
