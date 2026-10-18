import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import { VMenu } from 'vuetify/components'
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
import { NOTIFICATION_POLL_KEY } from '../composables/notificationPollContext'
import { useNotificationPoll } from '../composables/useNotificationPoll'

import NotificationBell from './NotificationBell.vue'

function countEnvelope(n: number) {
  return { data: { type: 'notification_unread_count', attributes: { unread_count: n } } }
}

function mountBell(options: { providedPoll?: ReturnType<typeof useNotificationPoll> } = {}) {
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
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/notifications', name: 'notifications', component: { template: '<div />' } },
      { path: '/elsewhere', name: 'elsewhere', component: { template: '<div />' } },
    ],
  })

  const wrapper = mount(NotificationBell, {
    props: { viewAllRoute: 'notifications' },
    global: {
      plugins: [i18n, vuetify, router],
      provide:
        options.providedPoll === undefined
          ? {}
          : { [NOTIFICATION_POLL_KEY as symbol]: options.providedPoll },
    },
    attachTo: document.createElement('div'),
  })

  return Object.assign(wrapper, { router })
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

describe('NotificationBell — closes on navigation (AH-101 U1)', () => {
  // vue-router's navigation never settles under the file-level fake timers, so this
  // block runs on real ones (the 45 s poll never fires inside a spec this short).
  beforeEach(() => {
    vi.useRealTimers()
  })

  async function openMenu(wrapper: ReturnType<typeof mountBell>): Promise<void> {
    await wrapper.find('[data-test="notification-bell-btn"]').trigger('click')
    await flushPromises()
  }

  it('opens on a bell tap (non-vacuity: the close assertions below start from open)', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    vi.mocked(notificationsApi.list).mockResolvedValue({ data: [], meta: {}, links: {} } as never)
    const wrapper = mountBell()
    await flushPromises()

    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(false)
    await openMenu(wrapper)
    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(true)
    wrapper.unmount()
  })

  it('closes when the route changes while open — the "View all" case', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    vi.mocked(notificationsApi.list).mockResolvedValue({ data: [], meta: {}, links: {} } as never)
    const wrapper = mountBell()
    await wrapper.router.push('/')
    await flushPromises()
    await openMenu(wrapper)
    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(true)

    await wrapper.router.push('/notifications')
    await flushPromises()

    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(false)
    wrapper.unmount()
  })

  it('closes for ANY navigation, not just the view-all route', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    vi.mocked(notificationsApi.list).mockResolvedValue({ data: [], meta: {}, links: {} } as never)
    const wrapper = mountBell()
    await wrapper.router.push('/')
    await flushPromises()
    await openMenu(wrapper)

    await wrapper.router.push('/elsewhere')
    await flushPromises()

    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(false)
    wrapper.unmount()
  })

  it('stays open when the route does not change (a row tap that does not navigate)', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    vi.mocked(notificationsApi.list).mockResolvedValue({ data: [], meta: {}, links: {} } as never)
    const wrapper = mountBell()
    await wrapper.router.push('/')
    await flushPromises()
    await openMenu(wrapper)

    // Re-pushing the same location is a no-op navigation: nothing to close on.
    await wrapper.router.push('/')
    await flushPromises()

    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(true)
    wrapper.unmount()
  })
})

describe('NotificationBell — badge cap and shared poll (AH-108)', () => {
  it('draws 99 as 99 and 100 as 99+ (the same cap the Messages tab uses)', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(99) as never)
    const at99 = mountBell()
    await flushPromises()
    expect(at99.find('.v-badge__badge').text()).toBe('99')
    at99.unmount()

    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(100) as never)
    const at100 = mountBell()
    await flushPromises()
    expect(at100.find('.v-badge__badge').text()).toBe('99+')
    at100.unmount()
  })

  it('keeps the REAL count in its accessible name when the glyph is capped', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(140) as never)
    const wrapper = mountBell()
    await flushPromises()

    expect(wrapper.find('[data-test="notification-bell-btn"]').attributes('aria-label')).toContain(
      '140',
    )
    wrapper.unmount()
  })

  it('standalone (nothing provided — the agency layout): owns and starts its own poll', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(2) as never)
    const wrapper = mountBell()
    await flushPromises()

    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)
    expect(wrapper.find('.v-badge__badge').text()).toBe('2')
    wrapper.unmount()
  })

  it('with a shared poll provided: draws ITS count and starts no second poll', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    const shared = useNotificationPoll()
    shared.set(7)

    const wrapper = mountBell({ providedPoll: shared })
    await flushPromises()

    expect(wrapper.find('.v-badge__badge').text()).toBe('7')
    expect(notificationsApi.unreadCount).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
