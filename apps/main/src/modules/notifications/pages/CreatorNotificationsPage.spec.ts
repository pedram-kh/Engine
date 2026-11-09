/**
 * AH-115 L4.4 — the creator Notifications page: a page header (title, settings link) above the shared
 * feed on the web; the previous bare card, unchanged, inside the Capacitor app.
 *
 * Break-revert: drop the header → the web case fails; render the header in the app → the app case fails;
 * stop passing `hide-title` → the single-title case fails.
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enNotifications from '@/core/i18n/locales/en/notifications.json'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({ isNativePlatform: () => platform.native }))

import CreatorNotificationsPage from './CreatorNotificationsPage.vue'

function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      {
        path: '/creator/notifications/preferences',
        name: 'creator.notifications.preferences',
        component: { template: '<div />' },
      },
    ],
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enNotifications } })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(CreatorNotificationsPage, {
    global: {
      plugins: [router, i18n, vuetify],
      stubs: {
        NotificationCenter: {
          props: { variant: String, hideTitle: Boolean },
          template:
            '<div data-test="center-stub" :data-variant="variant" :data-hide-title="String(hideTitle)" />',
        },
      },
    },
  })
}

describe('CreatorNotificationsPage (AH-115 L4.4)', () => {
  afterEach(() => {
    platform.native = false
  })

  it('web: a page header with the title and the settings link above the feed', () => {
    const wrapper = mountPage()

    expect(wrapper.get('[data-test="creator-notifications-title"]').text()).toBe('Notifications')
    expect(wrapper.find('[data-test="creator-notifications-settings"]').exists()).toBe(true)
    const center = wrapper.get('[data-test="center-stub"]')
    expect(center.attributes('data-variant')).toBe('page')
    expect(center.attributes('data-hide-title')).toBe('true')
  })

  it('app: the previous bare card, no header', () => {
    platform.native = true
    const wrapper = mountPage()

    expect(wrapper.find('[data-test="creator-notifications-title"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="notifications-page"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="center-stub"]').attributes('data-hide-title')).toBe('false')
  })
})
