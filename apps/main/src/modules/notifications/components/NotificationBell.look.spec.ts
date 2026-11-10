/**
 * AH-115 (build 4 native walk) — the bell in the creator look.
 *
 * Pins: below `md` with the creator look (the native app, or the creator web on a phone) the bell is
 * a link to the Notifications page and there is no popover; at `md` and up it is the popover, carrying
 * the creator-look classes (one scroll container: the CSS is pinned by `creator-notification-popover.spec`);
 * without the look (agency, any other mount) it is the previous popover with NO added class.
 *
 * Break-revert: drop the `goesToPage` gate → the phone case fails (the popover opens); mark the classes
 * unconditionally → the no-look case fails.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, ref } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enNotifications from '@/core/i18n/locales/en/notifications.json'
import { CREATOR_LOOK } from '@/composables/useNativeLook'

const mdAndUp = ref(false)
vi.mock('vuetify', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vuetify')>()
  return { ...actual, useDisplay: () => ({ mdAndUp: computed(() => mdAndUp.value) }) }
})

vi.mock('../api/notifications.api', () => ({
  notificationsApi: {
    list: vi.fn().mockResolvedValue({ data: [], meta: { unread_count: 0, last_page: 1 } }),
    unreadCount: vi.fn().mockResolvedValue({
      data: { type: 'notification_unread_count', attributes: { unread_count: 2 } },
    }),
    markRead: vi.fn(),
    readAll: vi.fn(),
  },
}))

import NotificationBell from './NotificationBell.vue'

async function mountBell(look: boolean) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      {
        path: '/creator/notifications',
        name: 'creator.notifications',
        component: { template: '<div />' },
      },
    ],
  })
  await router.push('/')
  await router.isReady()
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en: enNotifications } as never,
  })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(NotificationBell, {
    props: { viewAllRoute: 'creator.notifications' },
    global: {
      plugins: [i18n, vuetify, router],
      provide: look ? { [CREATOR_LOOK as symbol]: ref(true) } : {},
    },
    attachTo: document.body,
  })
  await flushPromises()
  return { wrapper, router }
}

describe('NotificationBell — creator look (AH-115)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('below md with the look: the bell navigates to the page and no popover opens', async () => {
    mdAndUp.value = false
    const { wrapper, router } = await mountBell(true)

    await wrapper.get('[data-test="notification-bell-btn"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('creator.notifications')
    expect(document.querySelector('[data-test="notification-dropdown-card"]')).toBeNull()
    expect(wrapper.find('[data-test="notification-menu-trigger-wrapper"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('at md and up with the look: the popover opens with the creator-look classes', async () => {
    mdAndUp.value = true
    const { wrapper, router } = await mountBell(true)

    await wrapper.get('[data-test="notification-bell-btn"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('home')
    const card = document.querySelector('[data-test="notification-dropdown-card"]')
    expect(card).not.toBeNull()
    expect(card!.classList.contains('notification-dropdown-card')).toBe(true)
    wrapper.unmount()
  })

  it('without the look (below md): still the popover, with no added class', async () => {
    mdAndUp.value = false
    const { wrapper, router } = await mountBell(false)

    await wrapper.get('[data-test="notification-bell-btn"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('home')
    const card = document.querySelector('[data-test="notification-dropdown-card"]')
    expect(card).not.toBeNull()
    expect(card!.classList.contains('notification-dropdown-card')).toBe(false)
    wrapper.unmount()
  })
})
