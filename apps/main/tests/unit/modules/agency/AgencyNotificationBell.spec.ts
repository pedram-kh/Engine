/**
 * AH-114 (S2.5) — `AgencyNotificationBell`, the agency fork of `NotificationBell`.
 *
 * The fork must behave as the original and differ only in look: a count becomes the badge, zero
 * shows no badge, 100 and over is capped to "99+", the accessible name keeps the real count, it
 * starts its own poll (or draws a shared one), and the dropdown closes on navigation. The look
 * (20px glyph, 16px count pill) is pinned in a real browser by `tools/walk/pins-s25.mjs`.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import { VMenu } from 'vuetify/components'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enNotifications from '@/core/i18n/locales/en/notifications.json'

vi.mock('@/modules/notifications/api/notifications.api', () => ({
  notificationsApi: {
    list: vi.fn(),
    unreadCount: vi.fn(),
    markRead: vi.fn(),
    readAll: vi.fn(),
  },
}))

import AgencyNotificationBell from '@/modules/agency/components/AgencyNotificationBell.vue'
import { notificationsApi } from '@/modules/notifications/api/notifications.api'
import { NOTIFICATION_POLL_KEY } from '@/modules/notifications/composables/notificationPollContext'
import { useNotificationPoll } from '@/modules/notifications/composables/useNotificationPoll'

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

  const wrapper = mount(AgencyNotificationBell, {
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

describe('AgencyNotificationBell', () => {
  it('keeps the data-test anchors of the shared bell', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    const wrapper = mountBell()
    await flushPromises()
    expect(wrapper.find('[data-test="notification-badge"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="notification-bell-btn"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('shows the unread count as the badge', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(4) as never)
    const wrapper = mountBell()
    await flushPromises()

    expect(wrapper.get('.v-badge__badge').text()).toBe('4')
    wrapper.unmount()
  })

  it('shows no badge at zero', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    const wrapper = mountBell()
    await flushPromises()

    const badge = wrapper.find('.v-badge__badge')
    expect(badge.exists() ? badge.text() : '').toBe('')
    wrapper.unmount()
  })

  it('draws the badge in the error colour with a class the look hangs on', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(2) as never)
    const wrapper = mountBell()
    await flushPromises()

    expect(wrapper.get('[data-test="notification-badge"]').classes()).toContain(
      'agency-bell__badge',
    )
    expect(wrapper.get('.v-badge__badge').classes().join(' ')).toContain('bg-error')
    wrapper.unmount()
  })

  it('draws the pill beside the bell (inline), not over it', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(2) as never)
    const wrapper = mountBell()
    await flushPromises()

    expect(wrapper.get('[data-test="notification-badge"]').classes()).toContain('v-badge--inline')
    wrapper.unmount()
  })

  it('draws 99 as 99 and 100 as 99+', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(99) as never)
    const at99 = mountBell()
    await flushPromises()
    expect(at99.get('.v-badge__badge').text()).toBe('99')
    at99.unmount()

    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(100) as never)
    const at100 = mountBell()
    await flushPromises()
    expect(at100.get('.v-badge__badge').text()).toBe('99+')
    at100.unmount()
  })

  it('keeps the accessible name, with the REAL count when the glyph is capped', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(140) as never)
    const wrapper = mountBell()
    await flushPromises()

    const label = wrapper.get('[data-test="notification-bell-btn"]').attributes('aria-label')
    expect(label).toContain('140')
    // The same key and wording as the shared bell, so nothing for translators changes.
    expect(label).toBe(
      enNotifications.notifications.center.unreadBadgeLabel.replace('{count}', '140'),
    )
    wrapper.unmount()
  })

  it('standalone: owns and starts its own poll, and stops it on unmount', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(2) as never)
    const wrapper = mountBell()
    await flushPromises()
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)

    const before = vi.mocked(notificationsApi.unreadCount).mock.calls.length
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(45000 * 3)
    expect(vi.mocked(notificationsApi.unreadCount).mock.calls.length).toBe(before)
  })

  it('with a shared poll provided: draws its count and starts no second poll', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    const shared = useNotificationPoll()
    shared.set(7)

    const wrapper = mountBell({ providedPoll: shared })
    await flushPromises()

    expect(wrapper.get('.v-badge__badge').text()).toBe('7')
    expect(notificationsApi.unreadCount).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})

describe('AgencyNotificationBell — dropdown', () => {
  beforeEach(() => {
    vi.useRealTimers()
  })

  it('opens on a bell tap and closes when the route changes', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(0) as never)
    vi.mocked(notificationsApi.list).mockResolvedValue({ data: [], meta: {}, links: {} } as never)
    const wrapper = mountBell()
    await wrapper.router.push('/')
    await flushPromises()

    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(false)
    await wrapper.get('[data-test="notification-bell-btn"]').trigger('click')
    await flushPromises()
    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(true)

    await wrapper.router.push('/notifications')
    await flushPromises()
    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(false)
    wrapper.unmount()
  })

  it('opens the dropdown from a tap on the count pill too', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(3) as never)
    vi.mocked(notificationsApi.list).mockResolvedValue({ data: [], meta: {}, links: {} } as never)
    const wrapper = mountBell()
    await wrapper.router.push('/')
    await flushPromises()

    await wrapper.get('.agency-bell__count').trigger('click')
    await flushPromises()
    expect(wrapper.findComponent(VMenu).props('modelValue')).toBe(true)
    wrapper.unmount()
  })
})
