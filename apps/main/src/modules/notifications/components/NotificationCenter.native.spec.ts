/**
 * AH-100d — the notification feed as the creator app draws it (Figma frame 09):
 * a type-icon disc per row, a compact age with the unread dot beneath it, and
 * "You're all caught up" at the end of the feed. Web polarity beside it: the
 * web row is untouched.
 *
 * Deferred and NOT asserted (needs API shape): the title / subtitle split and
 * the All / Unread control.
 */

import type { NotificationResource, NotificationType } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enNotifications from '@/core/i18n/locales/en/notifications.json'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../api/notifications.api', () => ({
  notificationsApi: { list: vi.fn(), unreadCount: vi.fn(), markRead: vi.fn(), readAll: vi.fn() },
}))

import { notificationsApi } from '../api/notifications.api'

import NotificationCenter from './NotificationCenter.vue'

const NOW = Date.parse('2026-10-09T12:00:00Z')

function row(
  id: string,
  type: NotificationType | string,
  ageMs: number,
  readAt: string | null = null,
): NotificationResource {
  return {
    id,
    type: 'notifications',
    attributes: {
      notification_type: type as NotificationType,
      data: {},
      read_at: readAt,
      created_at: new Date(NOW - ageMs).toISOString(),
      actor: null,
      subject: null,
    },
  }
}

function feed(rows: NotificationResource[], meta: { page?: number; last_page?: number } = {}) {
  return {
    data: rows,
    meta: {
      total: rows.length,
      page: meta.page ?? 1,
      per_page: 25,
      last_page: meta.last_page ?? 1,
      unread_count: rows.filter((r) => r.attributes.read_at === null).length,
    },
  } as never
}

async function mountPage(native: boolean, rows: NotificationResource[], last = 1) {
  platform.native = native
  vi.mocked(notificationsApi.list).mockResolvedValue(feed(rows, { last_page: last }))
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enNotifications } as never,
  })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(NotificationCenter, {
    props: { variant: 'page' },
    // AH-103: a row can navigate now, so the component injects the router.
    global: {
      plugins: [
        i18n,
        vuetify,
        createRouter({
          history: createMemoryHistory(),
          routes: [{ path: '/', component: { template: '<div />' } }],
        }),
      ],
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

const H = 3_600_000
const D = 24 * H

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(NOW)
})

afterEach(() => {
  vi.useRealTimers()
  platform.native = false
})

describe('notifications — native rows (frame 09)', () => {
  it('draws a type-icon disc per row, toned by type', async () => {
    const wrapper = await mountPage(true, [
      row('A', 'assignment.invited', 2 * H),
      row('B', 'agency_creator_relation.admin_connected', 1 * D),
      row('C', 'creator.approved', 2 * D),
    ])

    const discs = wrapper.findAll('[data-test="notification-type-icon"]')
    expect(
      discs.map((d) => d.classes().find((c) => c.startsWith('notification-row__icon--'))),
    ).toEqual([
      'notification-row__icon--info',
      'notification-row__icon--accent',
      'notification-row__icon--success',
    ])
    expect(discs[0]?.find('.mdi-gift-outline').exists()).toBe(true)
    expect(discs[1]?.find('.mdi-link-variant').exists()).toBe(true)
    expect(discs[2]?.find('.mdi-check-circle-outline').exists()).toBe(true)
    wrapper.unmount()
  })

  it('shows the compact age, and the unread dot only on unread rows', async () => {
    const wrapper = await mountPage(true, [
      row('A', 'assignment.invited', 2 * H),
      row('B', 'creator.approved', 2 * D, '2026-10-08T00:00:00Z'),
    ])

    const rows = wrapper.findAll('.notification-row')
    expect(rows[0]?.find('[data-test="notification-age"]').text()).toBe('2h')
    expect(rows[0]?.find('.notification-row__dot').exists()).toBe(true)
    expect(rows[1]?.find('[data-test="notification-age"]').text()).toBe('2d')
    expect(rows[1]?.find('.notification-row__dot').exists()).toBe(false)
    wrapper.unmount()
  })

  it('gives a type the app does not know a neutral bell, not a hole', async () => {
    const wrapper = await mountPage(true, [row('A', 'something.brand.new', H)])

    const disc = wrapper.find('[data-test="notification-type-icon"]')
    expect(disc.classes()).toContain('notification-row__icon--neutral')
    expect(disc.find('.mdi-bell-outline').exists()).toBe(true)
    wrapper.unmount()
  })

  it('ends the feed with "You\'re all caught up" on the last page only', async () => {
    const last = await mountPage(true, [row('A', 'creator.approved', H)], 1)
    expect(last.find('[data-test="notification-caught-up"]').text()).toContain('all caught up')
    last.unmount()

    const more = await mountPage(true, [row('A', 'creator.approved', H)], 3)
    expect(more.find('[data-test="notification-caught-up"]').exists()).toBe(false)
    more.unmount()
  })
})

describe('notifications — web is unchanged', () => {
  it('keeps the plain row: no disc, no age, no footer; the dot is the Vuetify icon', async () => {
    const wrapper = await mountPage(false, [row('A', 'assignment.invited', 2 * H)])

    expect(wrapper.find('[data-test="notification-type-icon"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="notification-age"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="notification-caught-up"]').exists()).toBe(false)
    expect(wrapper.find('.notification-row').exists()).toBe(false)
    expect(wrapper.find('[data-test="notification-unread-dot"]').classes()).toContain('v-icon')
    wrapper.unmount()
  })
})
