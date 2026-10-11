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

/**
 * AH-102 (C3.1) — the push column on the preferences page. A separate spec file
 * so `NotificationPreferencesPage.spec.ts` stays byte-identical: that file is the
 * proof that the WEB page did not change, and it passes unedited.
 */
const platform = vi.hoisted(() => ({ native: false, pushAvailable: false }))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('@/core/platform/push/pushChannelAvailable', () => ({
  pushChannelAvailable: () => platform.pushAvailable,
}))

vi.mock('../api/notifications.api', () => ({
  notificationsApi: {
    getPreferences: vi.fn(),
    updatePreferences: vi.fn(),
  },
}))

import { notificationsApi } from '../api/notifications.api'

import NotificationPreferencesPage from './NotificationPreferencesPage.vue'

const DEFAULTS = { in_app: true, email: true, digest: false, push: true }
/** What the API ships today: only the exceptions to the channel default. */
const TYPE_DEFAULTS = { 'campaign.job_posted': { push: false } }

/** The ten push switches the creator matrix carries (PushEligibility::TOGGLEABLE). */
const PUSH_SWITCH_TYPES = [
  'assignment.invited',
  'assignment.revision_requested',
  'assignment.draft_approved',
  'assignment.draft_rejected',
  'assignment.completed_on_approval',
  'assignment.manually_verified',
  'message.received_by_creator',
  'message.relationship_received_by_creator',
  'campaign_application.accepted',
  'campaign.job_posted',
]

function envelope(
  preferences: NotificationPreferencesEnvelope['data']['attributes']['preferences'] = [],
  typeDefaults: NotificationPreferencesEnvelope['data']['attributes']['type_defaults'] = TYPE_DEFAULTS,
): NotificationPreferencesEnvelope {
  return {
    data: {
      type: 'notification_preferences',
      attributes: { preferences, defaults: DEFAULTS, type_defaults: typeDefaults },
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

function checked(wrapper: ReturnType<typeof mountPage>, type: string, channel: string): boolean {
  return (
    wrapper.find(`[data-test="prefs-toggle-${type}-${channel}"] input`).element as HTMLInputElement
  ).checked
}

beforeEach(() => {
  vi.clearAllMocks()
  platform.native = false
  platform.pushAvailable = false
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('push column — web (push not available)', () => {
  it('renders no push column, switch or caption, and sends no push row in the PATCH body', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    vi.mocked(notificationsApi.updatePreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('creator')
    await flushPromises()

    expect(wrapper.find('[data-test="prefs-column-push"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test$="-push"]')).toHaveLength(0)

    await wrapper.find('[data-test="prefs-form"]').trigger('submit')
    await flushPromises()

    const sent = vi.mocked(notificationsApi.updatePreferences).mock.calls[0]?.[0].preferences
    expect(sent).toHaveLength(27)
    expect(sent?.filter((p) => p.channel === 'push')).toHaveLength(0)
    wrapper.unmount()
  })

  it('ignores the type_defaults block entirely when there is no push cell to compose', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('creator')
    await flushPromises()

    // The same in-app / email state a pre-AH-102 page would show.
    expect(checked(wrapper, 'campaign.job_posted', 'in_app')).toBe(true)
    expect(checked(wrapper, 'campaign.job_posted', 'email')).toBe(true)
    wrapper.unmount()
  })
})

describe('push column — native (push available)', () => {
  beforeEach(() => {
    platform.native = true
    platform.pushAvailable = true
  })

  it('shows exactly the ten push switches and the two always-on captions, as stacked cards', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('creator')
    await flushPromises()

    const switches = wrapper
      .findAll('[data-test^="prefs-toggle-"][data-test$="-push"]')
      .map((el) => (el.attributes('data-test') as string).replace(/^prefs-toggle-|-push$/g, ''))
    expect([...switches].sort()).toEqual([...PUSH_SWITCH_TYPES].sort())

    for (const type of ['creator.approved', 'creator.rejected']) {
      expect(wrapper.find(`[data-test="prefs-toggle-${type}-push"]`).exists()).toBe(false)
      expect(wrapper.find(`[data-test="prefs-always-on-${type}-push"]`).text()).toContain(
        'Always on',
      )
    }
    // 27 web toggles + 10 push.
    expect(wrapper.findAll('[data-test^="prefs-toggle-"]')).toHaveLength(37)
    expect(wrapper.find('.prefs-card').exists()).toBe(true)
    wrapper.unmount()
  })

  it('never shows a push cell to an agency user', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('agency_user')
    await flushPromises()

    expect(wrapper.findAll('[data-test$="-push"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-test^="prefs-toggle-"]')).toHaveLength(7)
    wrapper.unmount()
  })

  it('renders a push column in the grid where a row fills it (grid layout, push available)', async () => {
    platform.native = false
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('creator')
    await flushPromises()

    const columnsOf = (group: string): string[] =>
      wrapper
        .findAll(`[data-test="prefs-grid-${group}"] [data-test^="prefs-column-"]`)
        .map((c) => c.attributes('data-test') as string)

    expect(columnsOf('assignment')).toEqual([
      'prefs-column-in_app',
      'prefs-column-email',
      'prefs-column-push',
    ])
    expect(columnsOf('messaging')).toEqual([
      'prefs-column-in_app',
      'prefs-column-email',
      'prefs-column-digest',
      'prefs-column-push',
    ])
    wrapper.unmount()
  })

  describe('per-type defaults — both polarities', () => {
    it('job_posted push, no row → OFF (opt-in)', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      expect(checked(wrapper, 'campaign.job_posted', 'push')).toBe(false)
      // …while the SAME type's other channels keep the channel defaults.
      expect(checked(wrapper, 'campaign.job_posted', 'in_app')).toBe(true)
      expect(checked(wrapper, 'campaign.job_posted', 'email')).toBe(true)
      wrapper.unmount()
    })

    it('job_posted push, stored opt-in row (true) → ON', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(
        envelope([{ notification_type: 'campaign.job_posted', channel: 'push', is_enabled: true }]),
      )
      const wrapper = mountPage('creator')
      await flushPromises()

      expect(checked(wrapper, 'campaign.job_posted', 'push')).toBe(true)
      wrapper.unmount()
    })

    it('control type push, no row → ON (the channel default)', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      for (const type of PUSH_SWITCH_TYPES.filter((t) => t !== 'campaign.job_posted')) {
        expect(checked(wrapper, type, 'push')).toBe(true)
      }
      wrapper.unmount()
    })

    it('control type push, stored opt-out row (false) → OFF', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(
        envelope([{ notification_type: 'assignment.invited', channel: 'push', is_enabled: false }]),
      )
      const wrapper = mountPage('creator')
      await flushPromises()

      expect(checked(wrapper, 'assignment.invited', 'push')).toBe(false)
      expect(checked(wrapper, 'assignment.draft_approved', 'push')).toBe(true)
      wrapper.unmount()
    })

    it('reads the exception from the server block, not from a hardcoded type name', async () => {
      // A different exception on the wire must drive the display: the page holds
      // no copy of the rule.
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(
        envelope([], { 'assignment.invited': { push: false } }),
      )
      const wrapper = mountPage('creator')
      await flushPromises()

      expect(checked(wrapper, 'assignment.invited', 'push')).toBe(false)
      expect(checked(wrapper, 'campaign.job_posted', 'push')).toBe(true)
      wrapper.unmount()
    })
  })

  describe('save', () => {
    it('sends all ten push rows in the single payload, carrying the composed value', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      vi.mocked(notificationsApi.updatePreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      await wrapper.find('[data-test="prefs-form"]').trigger('submit')
      await flushPromises()

      const sent = vi.mocked(notificationsApi.updatePreferences).mock.calls[0]?.[0].preferences
      const pushRows = sent?.filter((p) => p.channel === 'push') ?? []
      expect(sent).toHaveLength(37)
      expect(pushRows.map((p) => p.notification_type).sort()).toEqual([...PUSH_SWITCH_TYPES].sort())
      // Untouched: job_posted rides its OFF default, everything else its ON one.
      expect(pushRows.find((p) => p.notification_type === 'campaign.job_posted')?.is_enabled).toBe(
        false,
      )
      expect(pushRows.find((p) => p.notification_type === 'assignment.invited')?.is_enabled).toBe(
        true,
      )
      wrapper.unmount()
    })

    it('an opt-in to job_posted push rides true, and the server echo keeps it ON (it sticks)', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      vi.mocked(notificationsApi.updatePreferences).mockResolvedValue(
        envelope([{ notification_type: 'campaign.job_posted', channel: 'push', is_enabled: true }]),
      )
      const wrapper = mountPage('creator')
      await flushPromises()

      await wrapper.find('[data-test="prefs-toggle-campaign.job_posted-push"] input').setValue(true)
      await wrapper.find('[data-test="prefs-form"]').trigger('submit')
      await flushPromises()

      const sent = vi.mocked(notificationsApi.updatePreferences).mock.calls[0]?.[0].preferences
      expect(
        sent?.find((p) => p.notification_type === 'campaign.job_posted' && p.channel === 'push')
          ?.is_enabled,
      ).toBe(true)
      expect(checked(wrapper, 'campaign.job_posted', 'push')).toBe(true)
      wrapper.unmount()
    })

    it('an opt-out of a control type rides false', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      vi.mocked(notificationsApi.updatePreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      await wrapper
        .find('[data-test="prefs-toggle-assignment.draft_approved-push"] input')
        .setValue(false)
      await wrapper.find('[data-test="prefs-form"]').trigger('submit')
      await flushPromises()

      const sent = vi.mocked(notificationsApi.updatePreferences).mock.calls[0]?.[0].preferences
      expect(
        sent?.find(
          (p) => p.notification_type === 'assignment.draft_approved' && p.channel === 'push',
        )?.is_enabled,
      ).toBe(false)
      wrapper.unmount()
    })
  })
})
