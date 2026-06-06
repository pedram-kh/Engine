import type { NotificationPreferencesEnvelope, UserType } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enNotifications from '@/core/i18n/locales/en/notifications.json'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

vi.mock('../api/notifications.api', () => ({
  notificationsApi: {
    getPreferences: vi.fn(),
    updatePreferences: vi.fn(),
  },
}))

import { notificationsApi } from '../api/notifications.api'

import NotificationPreferencesPage from './NotificationPreferencesPage.vue'

const DEFAULTS = { in_app: true, email: true, digest: false }

function envelope(
  preferences: NotificationPreferencesEnvelope['data']['attributes']['preferences'] = [],
): NotificationPreferencesEnvelope {
  return {
    data: {
      type: 'notification_preferences',
      attributes: { preferences, defaults: DEFAULTS },
    },
  }
}

function mountPage(userType: UserType = 'creator') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.user = {
    id: '01USERULIDXXXXXXXXXXXXXXXXX',
    type: 'users',
    attributes: { name: 'Test', email: 't@example.com', user_type: userType },
  } as never

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    availableLocales: ['en'],
    messages: { en: enNotifications } as never,
  }) as unknown as ReturnType<typeof createI18n>

  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })

  return mount(NotificationPreferencesPage, {
    global: { plugins: [pinia, i18n, vuetify] },
    attachTo: document.createElement('div'),
  })
}

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('NotificationPreferencesPage', () => {
  it('composes display state from sparse rows + the defaults block (row ?? default)', async () => {
    // One divergent row (draft_approved OFF); everything else falls to default ON.
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(
      envelope([
        { notification_type: 'assignment.draft_approved', channel: 'in_app', is_enabled: false },
      ]),
    )
    const wrapper = mountPage()
    await flushPromises()

    const divergent = wrapper.find('[data-test="prefs-toggle-assignment.draft_approved"] input')
      .element as HTMLInputElement
    const defaulted = wrapper.find('[data-test="prefs-toggle-creator.approved"] input')
      .element as HTMLInputElement

    expect(divergent.checked).toBe(false)
    expect(defaulted.checked).toBe(true)
    wrapper.unmount()
  })

  it('role filter — a CREATOR sees only the 6 creator-facing types (2 groups)', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('creator')
    await flushPromises()

    const toggles = wrapper.findAll('[data-test^="prefs-toggle-"]')
    expect(toggles).toHaveLength(6)
    // Both groups present (4 assignment + 2 creator).
    expect(wrapper.find('[data-test="prefs-group-assignment"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="prefs-group-creator"]').exists()).toBe(true)
    // The agency-only types are NOT offered to a creator (no dead control).
    expect(wrapper.find('[data-test="prefs-toggle-assignment.draft_submitted"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-test="prefs-toggle-assignment.contracted"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="prefs-toggle-creator.approved"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('role filter — an AGENCY user sees only the 2 agency-facing types (assignment group)', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('agency_user')
    await flushPromises()

    const toggles = wrapper.findAll('[data-test^="prefs-toggle-"]')
    expect(toggles).toHaveLength(2)
    expect(wrapper.find('[data-test="prefs-toggle-assignment.draft_submitted"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-test="prefs-toggle-assignment.contracted"]').exists()).toBe(true)
    // No creator-facing toggles, and the creator group header is absent.
    expect(wrapper.find('[data-test="prefs-toggle-creator.approved"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="prefs-group-creator"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('toggles a switch and submits the full visible set with the flipped value', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    vi.mocked(notificationsApi.updatePreferences).mockResolvedValue(
      envelope([
        { notification_type: 'assignment.draft_approved', channel: 'in_app', is_enabled: false },
      ]),
    )
    const wrapper = mountPage()
    await flushPromises()

    // Flip draft_approved OFF (it starts ON via the default).
    await wrapper.find('[data-test="prefs-toggle-assignment.draft_approved"] input').setValue(false)

    await wrapper.find('[data-test="prefs-form"]').trigger('submit')
    await flushPromises()

    expect(notificationsApi.updatePreferences).toHaveBeenCalledTimes(1)
    const payload = vi.mocked(notificationsApi.updatePreferences).mock.calls[0]?.[0]
    // A creator submits exactly their 6 role-filtered toggles (not the full 8).
    expect(payload?.preferences).toHaveLength(6)
    // Every row is in_app (the only exposed channel).
    expect(payload?.preferences.every((p) => p.channel === 'in_app')).toBe(true)
    // The flipped toggle rides as false; an untouched one stays true.
    expect(
      payload?.preferences.find((p) => p.notification_type === 'assignment.draft_approved')
        ?.is_enabled,
    ).toBe(false)
    expect(
      payload?.preferences.find((p) => p.notification_type === 'creator.approved')?.is_enabled,
    ).toBe(true)
    wrapper.unmount()
  })

  it('shows the success alert after a successful save', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    vi.mocked(notificationsApi.updatePreferences).mockResolvedValue(envelope())
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="prefs-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="prefs-success"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('renders the load-error state when the read rejects', async () => {
    vi.mocked(notificationsApi.getPreferences).mockRejectedValue(new Error('boom'))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="prefs-load-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="prefs-form"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('renders the save-error state when the write rejects', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    vi.mocked(notificationsApi.updatePreferences).mockRejectedValue(new Error('boom'))
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="prefs-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="prefs-save-error"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
