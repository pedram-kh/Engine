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

// AH-101 — the matrix is a grid on the web and stacked cards in the native app.
// `vi.hoisted` so the mock factory (hoisted above imports) can read the switch.
const platform = vi.hoisted(() => ({ native: false }))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
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

function envelope(
  preferences: NotificationPreferencesEnvelope['data']['attributes']['preferences'] = [],
): NotificationPreferencesEnvelope {
  return {
    data: {
      type: 'notification_preferences',
      attributes: { preferences, defaults: DEFAULTS, type_defaults: {} },
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
  platform.native = false
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

  it('role filter — a CREATOR sees 14 types / 27 toggles across 4 groups (matrix: in-app + email + digest)', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('creator')
    await flushPromises()

    // 14 types, 27 toggles (AH-101): 14 in_app (one per type) + 12 email + 1 digest.
    // The 12 email cells are exactly the creator types whose send site now reads
    // the `email` preference — invited, the three draft outcomes, completion,
    // manual + automatic verification, both message kinds, job posted, and the
    // two application answers. `creator.approved` / `creator.rejected` have NO
    // email switch (always-on caption instead), and the campaign message type
    // keeps its opt-in digest.
    expect(wrapper.findAll('[data-test^="prefs-type-"]')).toHaveLength(14)
    expect(wrapper.findAll('[data-test^="prefs-toggle-"]')).toHaveLength(27)
    expect(wrapper.findAll('[data-test^="prefs-toggle-"][data-test$="-email"]')).toHaveLength(12)
    expect(wrapper.find('[data-test="prefs-toggle-assignment.invited-in_app"]').exists()).toBe(true)
    // All four groups present — AH-058 (Q5) split `jobs_board` out of
    // `assignment`, honouring the trigger AH-056 recorded in the enum.
    expect(wrapper.find('[data-test="prefs-group-assignment"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="prefs-group-jobs_board"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="prefs-group-creator"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="prefs-group-messaging"]').exists()).toBe(true)
    // The two AH-058 creator answers are offered here, under jobs_board.
    expect(
      wrapper.find('[data-test="prefs-toggle-campaign_application.accepted-in_app"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-test="prefs-toggle-campaign_application.rejected-in_app"]').exists(),
    ).toBe(true)
    // …and the agency-facing application verb is not (no dead control).
    expect(
      wrapper.find('[data-test="prefs-toggle-campaign_application.submitted-in_app"]').exists(),
    ).toBe(false)
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

  it('role filter — an AGENCY user sees 5 types / 7 toggles (assignment + jobs board + messaging w/ digest)', async () => {
    vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
    const wrapper = mountPage('agency_user')
    await flushPromises()

    expect(wrapper.findAll('[data-test^="prefs-type-"]')).toHaveLength(5)
    // 5 in_app + 1 digest + 1 email (campaign_application.submitted — the only
    // agency type whose recipients are also mailed through the guarded site).
    expect(wrapper.findAll('[data-test^="prefs-toggle-"]')).toHaveLength(7)
    expect(
      wrapper.find('[data-test="prefs-toggle-assignment.draft_submitted-in_app"]').exists(),
    ).toBe(true)
    // AH-058 — the agency's own jobs-board toggle: a creator applied. This is
    // the first agency-facing type in the jobs_board group, which is why the
    // group heading could not stay "Assignments".
    expect(wrapper.find('[data-test="prefs-group-jobs_board"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-test="prefs-toggle-campaign_application.submitted-in_app"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-test="prefs-toggle-campaign_application.accepted-in_app"]').exists(),
    ).toBe(false)
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
    // A creator submits all 27 (type, channel) toggles — 14 in_app + 12 email + 1 digest.
    expect(payload?.preferences).toHaveLength(27)
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
  describe('AH-101 matrix', () => {
    it("derives each group's columns from its own types — digest belongs to Messages alone", async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      const columnsOf = (group: string): string[] =>
        wrapper
          .findAll(`[data-test="prefs-grid-${group}"] [data-test^="prefs-column-"]`)
          .map((c) => c.attributes('data-test') as string)

      expect(columnsOf('assignment')).toEqual(['prefs-column-in_app', 'prefs-column-email'])
      expect(columnsOf('jobs_board')).toEqual(['prefs-column-in_app', 'prefs-column-email'])
      expect(columnsOf('creator')).toEqual(['prefs-column-in_app', 'prefs-column-email'])
      expect(columnsOf('messaging')).toEqual([
        'prefs-column-in_app',
        'prefs-column-email',
        'prefs-column-digest',
      ])
      wrapper.unmount()
    })

    it('leaves a cell empty where a type does not deliver that channel (no dead control)', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      // The relationship DM has no digest, and the messaging group has a digest
      // column — so its digest cell exists but is empty.
      const row = wrapper.find('[data-test="prefs-type-message.relationship_received_by_creator"]')
      expect(row.exists()).toBe(true)
      expect(
        wrapper
          .find('[data-test="prefs-toggle-message.relationship_received_by_creator-digest"]')
          .exists(),
      ).toBe(false)
      expect(row.findAll('[role="cell"]')).toHaveLength(3)
      expect(row.findAll('[role="cell"]')[2]?.text()).toBe('')
      wrapper.unmount()
    })

    it('shows creator.approved / creator.rejected email as an "Always on" caption, not a switch (Q2)', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      for (const type of ['creator.approved', 'creator.rejected']) {
        expect(wrapper.find(`[data-test="prefs-toggle-${type}-email"]`).exists()).toBe(false)
        expect(wrapper.find(`[data-test="prefs-always-on-${type}-email"]`).text()).toBe('Always on')
        // …while the in-app toggle those users already hold rows for is kept.
        expect(wrapper.find(`[data-test="prefs-toggle-${type}-in_app"]`).exists()).toBe(true)
      }
      wrapper.unmount()
    })

    it('offers no email cell for the agency-inviter mails (Q3 — a dead control on that page)', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      const agency = mountPage('agency_user')
      await flushPromises()

      for (const type of ['assignment.draft_submitted', 'assignment.contracted']) {
        expect(agency.find(`[data-test="prefs-toggle-${type}-in_app"]`).exists()).toBe(true)
        expect(agency.find(`[data-test="prefs-toggle-${type}-email"]`).exists()).toBe(false)
      }
      agency.unmount()
    })

    it('email defaults ON from the defaults block, and a stored email=false row shows OFF', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(
        envelope([
          { notification_type: 'assignment.live_verified', channel: 'email', is_enabled: false },
        ]),
      )
      const wrapper = mountPage('creator')
      await flushPromises()

      const checked = (type: string): boolean =>
        (wrapper.find(`[data-test="prefs-toggle-${type}-email"] input`).element as HTMLInputElement)
          .checked

      expect(checked('assignment.live_verified')).toBe(false)
      expect(checked('assignment.manually_verified')).toBe(true)
      wrapper.unmount()
    })

    it('saves an email opt-out through the same single payload (no new save model)', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      vi.mocked(notificationsApi.updatePreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      await wrapper
        .find('[data-test="prefs-toggle-campaign.job_posted-email"] input')
        .setValue(false)
      await wrapper.find('[data-test="prefs-form"]').trigger('submit')
      await flushPromises()

      expect(notificationsApi.updatePreferences).toHaveBeenCalledTimes(1)
      const sent = vi.mocked(notificationsApi.updatePreferences).mock.calls[0]?.[0].preferences
      expect(
        sent?.find((p) => p.notification_type === 'campaign.job_posted' && p.channel === 'email')
          ?.is_enabled,
      ).toBe(false)
      expect(
        sent?.find((p) => p.notification_type === 'campaign.job_posted' && p.channel === 'in_app')
          ?.is_enabled,
      ).toBe(true)
      wrapper.unmount()
    })

    it('renders the grid on the web and no stacked cards', async () => {
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      expect(wrapper.find('[data-test="prefs-grid-assignment"]').exists()).toBe(true)
      expect(wrapper.find('.prefs-card').exists()).toBe(false)
      wrapper.unmount()
    })

    it('renders stacked cards per type in the native app — same toggles, same ids, no grid (Q6)', async () => {
      platform.native = true
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      expect(wrapper.find('[data-test^="prefs-grid-"]').exists()).toBe(false)
      expect(wrapper.findAll('.prefs-card')).toHaveLength(14)
      // The same data-test ids and toggle counts as the grid — only the layout moved.
      expect(wrapper.findAll('[data-test^="prefs-toggle-"]')).toHaveLength(27)
      // A card carries only its own channels: the always-on caption sits in the card.
      const card = wrapper.find('[data-test="prefs-type-creator.approved"]')
      expect(card.find('[data-test="prefs-toggle-creator.approved-in_app"]').exists()).toBe(true)
      expect(card.find('[data-test="prefs-always-on-creator.approved-email"]').text()).toContain(
        'Always on',
      )
      expect(card.find('[data-test="prefs-toggle-creator.approved-email"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('saves the same payload from the native cards as from the grid', async () => {
      platform.native = true
      vi.mocked(notificationsApi.getPreferences).mockResolvedValue(envelope())
      vi.mocked(notificationsApi.updatePreferences).mockResolvedValue(envelope())
      const wrapper = mountPage('creator')
      await flushPromises()

      await wrapper.find('[data-test="prefs-form"]').trigger('submit')
      await flushPromises()

      expect(
        vi.mocked(notificationsApi.updatePreferences).mock.calls[0]?.[0].preferences,
      ).toHaveLength(27)
      wrapper.unmount()
    })
  })
})
