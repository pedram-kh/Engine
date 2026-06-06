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

    const divergent = wrapper.find(
      '[data-test="prefs-toggle-assignment.draft_approved-in_app"] input',
    ).element as HTMLInputElement
    const defaulted = wrapper.find('[data-test="prefs-toggle-creator.approved-in_app"] input')
      .element as HTMLInputElement

    expect(divergent.checked).toBe(false)
    expect(defaulted.checked).toBe(true)
    wrapper.unmount()
  })

  it('role filter — a CREATOR sees 7 types / 8 toggles across 3 groups (messaging has the digest)', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('creator')
    await flushPromises()

    // 7 types, 8 toggles: 6 in_app-only types + the messaging type's
    // in_app + digest pair (D-10).
    expect(wrapper.findAll('[data-test^="prefs-type-"]')).toHaveLength(7)
    expect(wrapper.findAll('[data-test^="prefs-toggle-"]')).toHaveLength(8)
    // All three groups present (assignment + creator + messaging).
    expect(wrapper.find('[data-test="prefs-group-assignment"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="prefs-group-creator"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="prefs-group-messaging"]').exists()).toBe(true)
    // The messaging type exposes BOTH channels (digest co-delivered, D-10).
    expect(
      wrapper.find('[data-test="prefs-toggle-message.received_by_creator-in_app"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-test="prefs-toggle-message.received_by_creator-digest"]').exists(),
    ).toBe(true)
    // The agency-only types are NOT offered to a creator (no dead control).
    expect(
      wrapper.find('[data-test="prefs-toggle-assignment.draft_submitted-in_app"]').exists(),
    ).toBe(false)
    expect(
      wrapper.find('[data-test="prefs-toggle-message.received_by_agency-in_app"]').exists(),
    ).toBe(false)
    wrapper.unmount()
  })

  it('role filter — an AGENCY user sees 3 types / 4 toggles (assignment + messaging w/ digest)', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('agency_user')
    await flushPromises()

    expect(wrapper.findAll('[data-test^="prefs-type-"]')).toHaveLength(3)
    expect(wrapper.findAll('[data-test^="prefs-toggle-"]')).toHaveLength(4)
    expect(
      wrapper.find('[data-test="prefs-toggle-assignment.draft_submitted-in_app"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-test="prefs-toggle-message.received_by_agency-digest"]').exists(),
    ).toBe(true)
    // No creator-facing types, and the creator group header is absent.
    expect(wrapper.find('[data-test="prefs-group-creator"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-test="prefs-toggle-message.received_by_creator-in_app"]').exists(),
    ).toBe(false)
    wrapper.unmount()
  })

  it('the digest channel is opt-in (default OFF) while in_app defaults ON (D-10)', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('creator')
    await flushPromises()

    const inApp = wrapper.find(
      '[data-test="prefs-toggle-message.received_by_creator-in_app"] input',
    ).element as HTMLInputElement
    const digest = wrapper.find(
      '[data-test="prefs-toggle-message.received_by_creator-digest"] input',
    ).element as HTMLInputElement

    expect(inApp.checked).toBe(true)
    expect(digest.checked).toBe(false)
    // No non-messaging type exposes a digest toggle (honest channel lift).
    expect(
      wrapper.find('[data-test="prefs-toggle-assignment.draft_approved-digest"]').exists(),
    ).toBe(false)
    wrapper.unmount()
  })

  it('toggles a switch and submits the full visible (type, channel) set with the flipped value', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    vi.mocked(notificationsApi.updatePreferences).mockResolvedValue(
      envelope([
        { notification_type: 'assignment.draft_approved', channel: 'in_app', is_enabled: false },
      ]),
    )
    const wrapper = mountPage()
    await flushPromises()

    // Flip draft_approved's in_app OFF (it starts ON via the default).
    await wrapper
      .find('[data-test="prefs-toggle-assignment.draft_approved-in_app"] input')
      .setValue(false)
    // Opt INTO the messaging digest (it starts OFF).
    await wrapper
      .find('[data-test="prefs-toggle-message.received_by_creator-digest"] input')
      .setValue(true)

    await wrapper.find('[data-test="prefs-form"]').trigger('submit')
    await flushPromises()

    expect(notificationsApi.updatePreferences).toHaveBeenCalledTimes(1)
    const payload = vi.mocked(notificationsApi.updatePreferences).mock.calls[0]?.[0]
    // A creator submits all 8 (type, channel) toggles — 7 in_app + 1 digest.
    expect(payload?.preferences).toHaveLength(8)
    expect(payload?.preferences.filter((p) => p.channel === 'digest')).toHaveLength(1)
    // The flipped in_app rides false; the opted-in digest rides true.
    expect(
      payload?.preferences.find(
        (p) => p.notification_type === 'assignment.draft_approved' && p.channel === 'in_app',
      )?.is_enabled,
    ).toBe(false)
    expect(
      payload?.preferences.find(
        (p) => p.notification_type === 'message.received_by_creator' && p.channel === 'digest',
      )?.is_enabled,
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
