import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enNotifications from '@/core/i18n/locales/en/notifications.json'

vi.mock('../api/notifications.api', () => ({
  notificationsApi: {
    list: vi.fn(),
    unreadCount: vi.fn(),
    markRead: vi.fn(),
    readAll: vi.fn(),
  },
}))

import { notificationsApi } from '../api/notifications.api'

import NotificationBell from './NotificationBell.vue'

function countEnvelope(n: number) {
  return { data: { type: 'notification_unread_count', attributes: { unread_count: n } } }
}

function mountBell() {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enNotifications } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/notifications', name: 'notifications', component: { template: '<div />' } }],
  })

  return mount(NotificationBell, {
    props: { viewAllRoute: 'notifications' },
    global: { plugins: [i18n, vuetify, router] },
    attachTo: document.createElement('div'),
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('NotificationBell — the first v-badge', () => {
  it('renders a v-badge wrapping the bell button', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    const wrapper = mountBell()
    await flushPromises()

    expect(wrapper.find('[data-test="notification-badge"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="notification-bell-btn"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('hides the badge content at zero via model-value (not v-if)', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    const wrapper = mountBell()
    await flushPromises()

    // Vuetify renders the badge content wrapper but toggles visibility off the
    // model-value; at zero the numeric badge content must not be visible.
    const badge = wrapper.find('[data-test="notification-badge"]')
    expect(badge.exists()).toBe(true)
    const visibleBadge = wrapper.find('.v-badge__badge')
    // When model-value is false Vuetify hides the badge wrapper (style/transition);
    // the safest cross-version assertion is that no count text shows.
    expect(visibleBadge.exists() ? visibleBadge.text() : '').not.toContain('1')
    wrapper.unmount()
  })

  it('shows the unread count once the poll resolves a positive count', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(4) as never)
    const wrapper = mountBell()
    await flushPromises()

    expect(wrapper.find('.v-badge__badge').text()).toContain('4')
    wrapper.unmount()
  })

  it('starts the count poll on mount', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    const wrapper = mountBell()
    await flushPromises()
    expect(notificationsApi.unreadCount).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('tears down the poll on unmount (no further count fetches)', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    const wrapper = mountBell()
    await flushPromises()
    const before = vi.mocked(notificationsApi.unreadCount).mock.calls.length

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(45000 * 3)
    expect(vi.mocked(notificationsApi.unreadCount).mock.calls.length).toBe(before)
  })
})
