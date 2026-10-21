import type { NotificationResource, NotificationType } from '@catalyst/api-client'
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
import type { NotificationPollHandle } from '../composables/useNotificationPoll'

import NotificationCenter from './NotificationCenter.vue'

let rowSeq = 0

function makeRow(
  type: NotificationType | string,
  data: Record<string, unknown> = {},
  readAt: string | null = null,
): NotificationResource {
  rowSeq += 1
  return {
    id: `01ROW${rowSeq.toString().padStart(20, '0')}`,
    type: 'notifications',
    attributes: {
      notification_type: type as NotificationType,
      data,
      read_at: readAt,
      created_at: '2026-06-01T10:00:00.000000Z',
      actor: null,
      subject: null,
    },
  }
}

function feed(
  rows: NotificationResource[],
  meta: Partial<{ page: number; last_page: number; unread_count: number }> = {},
) {
  return {
    data: rows,
    meta: {
      total: rows.length,
      page: meta.page ?? 1,
      per_page: 25,
      last_page: meta.last_page ?? 1,
      unread_count: meta.unread_count ?? rows.filter((r) => r.attributes.read_at === null).length,
    },
  }
}

function makePoll(): NotificationPollHandle {
  return {
    unreadCount: { value: 0 } as never,
    messageUnreadCount: { value: 0 } as never,
    hasLoaded: { value: true } as never,
    isPolling: { value: false } as never,
    start: vi.fn(),
    cancel: vi.fn(),
    refresh: vi.fn(),
    set: vi.fn(),
    applyMarkRead: vi.fn(),
    applyReadAll: vi.fn(),
  }
}

interface CenterProps {
  variant: 'dropdown' | 'page'
  poll?: NotificationPollHandle | null
  viewAllRoute?: string | null
}

function mountCenter(props: CenterProps) {
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
      { path: '/notifications', name: 'notifications', component: { template: '<div />' } },
      {
        path: '/creator/notifications',
        name: 'creator.notifications',
        component: { template: '<div />' },
      },
      // AH-103 — the real destination routes (same names and paths as the app's table).
      {
        path: '/creator/assignments/:ulid',
        name: 'creator.assignment.detail',
        component: { template: '<div />' },
      },
      {
        path: '/creator/jobs/:ulid',
        name: 'creator.job.detail',
        component: { template: '<div />' },
      },
      { path: '/campaigns/:ulid', name: 'campaigns.detail', component: { template: '<div />' } },
    ],
  })

  return mount(NotificationCenter, {
    props,
    global: { plugins: [i18n, vuetify, router] },
    attachTo: document.createElement('div'),
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  rowSeq = 0
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('NotificationCenter — feed render + interactions', () => {
  it('renders the feed rows returned by list()', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([
        makeRow('assignment.manually_verified', { campaign_name: 'Spring Launch' }),
        makeRow('creator.approved', {}, '2026-06-01T11:00:00Z'),
      ]) as never,
    )
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()

    expect(wrapper.findAll('[data-test~="notification-row"]')).toHaveLength(2)
    wrapper.unmount()
  })

  it('marks unread rows visually distinct (unread class + dot)', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([
        makeRow('assignment.manually_verified', { campaign_name: 'A' }),
        makeRow('assignment.manually_verified', { campaign_name: 'B' }, '2026-06-01T11:00:00Z'),
      ]) as never,
    )
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()

    expect(wrapper.findAll('[data-test~="notification-row--unread"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test="notification-unread-dot"]')).toHaveLength(1)
    wrapper.unmount()
  })

  it('row click on an unread row marks it read + reconciles the badge', async () => {
    const poll = makePoll()
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([makeRow('assignment.manually_verified', { campaign_name: 'A' })]) as never,
    )
    vi.mocked(notificationsApi.markRead).mockResolvedValue({} as never)
    const wrapper = mountCenter({ variant: 'dropdown', poll })
    await flushPromises()

    await wrapper.find('[data-test~="notification-row"]').trigger('click')
    await flushPromises()

    expect(notificationsApi.markRead).toHaveBeenCalledTimes(1)
    expect(poll.applyMarkRead).toHaveBeenCalledTimes(1)
    // Row is now read → no unread row remains.
    expect(wrapper.findAll('[data-test~="notification-row--unread"]')).toHaveLength(0)
    wrapper.unmount()
  })

  it('AH-103: clicking an already-read row sends no PATCH and does not touch the badge', async () => {
    const poll = makePoll()
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([
        makeRow('assignment.manually_verified', { campaign_name: 'A' }, '2026-06-01T11:00:00Z'),
      ]) as never,
    )
    const wrapper = mountCenter({ variant: 'dropdown', poll })
    await flushPromises()

    await wrapper.find('[data-test~="notification-row"]').trigger('click')
    await flushPromises()

    expect(notificationsApi.markRead).not.toHaveBeenCalled()
    expect(poll.applyMarkRead).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  describe('AH-103 — a row click opens the screen the notification is about', () => {
    const ASSIGNMENT = '01J9ZK3Q8M5T7WXYABCDEFGHJK'
    const CAMPAIGN = '01J9ZK3Q8M5T7WXYCAMPAGNAAA'

    async function clickFirstRow(rows: NotificationResource[], props: Partial<CenterProps> = {}) {
      vi.mocked(notificationsApi.list).mockResolvedValue(feed(rows) as never)
      vi.mocked(notificationsApi.markRead).mockResolvedValue({} as never)
      const wrapper = mountCenter({ variant: 'dropdown', poll: makePoll(), ...props })
      await flushPromises()
      const router = wrapper.vm.$router

      await wrapper.find('[data-test~="notification-row"]').trigger('click')
      await flushPromises()

      return { wrapper, router }
    }

    it('an UNREAD creator row navigates to its assignment AND is marked read', async () => {
      const { wrapper, router } = await clickFirstRow([
        makeRow('assignment.draft_approved', { campaign_name: 'A', assignment_ulid: ASSIGNMENT }),
      ])

      expect(router.currentRoute.value.fullPath).toBe(`/creator/assignments/${ASSIGNMENT}`)
      expect(notificationsApi.markRead).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })

    it('a READ row navigates too, with NO PATCH', async () => {
      const { wrapper, router } = await clickFirstRow([
        makeRow(
          'assignment.draft_approved',
          { assignment_ulid: ASSIGNMENT },
          '2026-06-01T11:00:00Z',
        ),
      ])

      expect(router.currentRoute.value.fullPath).toBe(`/creator/assignments/${ASSIGNMENT}`)
      expect(notificationsApi.markRead).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('an AGENCY row (the agency bell) opens the campaign on the right tab', async () => {
      const { wrapper, router } = await clickFirstRow([
        makeRow('campaign_application.submitted', { campaign_ulid: CAMPAIGN }),
      ])

      expect(router.currentRoute.value.fullPath).toBe(`/campaigns/${CAMPAIGN}?tab=applications`)
      wrapper.unmount()
    })

    it('navigation does not wait on the PATCH, and a failing PATCH neither blocks it nor strands the row read', async () => {
      vi.mocked(notificationsApi.list).mockResolvedValue(
        feed([makeRow('assignment.draft_approved', { assignment_ulid: ASSIGNMENT })]) as never,
      )
      // A PATCH that never settles.
      vi.mocked(notificationsApi.markRead).mockReturnValue(new Promise(() => undefined) as never)
      const wrapper = mountCenter({ variant: 'dropdown', poll: makePoll() })
      await flushPromises()

      void wrapper.find('[data-test~="notification-row"]').trigger('click')
      await flushPromises()

      expect(wrapper.vm.$router.currentRoute.value.fullPath).toBe(
        `/creator/assignments/${ASSIGNMENT}`,
      )
      wrapper.unmount()
    })

    it('a failing PATCH rolls the row back to unread but the navigation still happened', async () => {
      vi.mocked(notificationsApi.list).mockResolvedValue(
        feed([makeRow('assignment.draft_approved', { assignment_ulid: ASSIGNMENT })]) as never,
      )
      vi.mocked(notificationsApi.markRead).mockRejectedValue(new Error('offline'))
      const wrapper = mountCenter({ variant: 'dropdown', poll: makePoll() })
      await flushPromises()

      await wrapper.find('[data-test~="notification-row"]').trigger('click')
      await flushPromises()

      expect(wrapper.vm.$router.currentRoute.value.fullPath).toBe(
        `/creator/assignments/${ASSIGNMENT}`,
      )
      expect(wrapper.findAll('[data-test~="notification-row--unread"]')).toHaveLength(1)
      wrapper.unmount()
    })

    it('an OLD job_posted row (no campaign_ulid in its bag) resolves through its subject', async () => {
      const row = makeRow('campaign.job_posted', { campaign_name: 'A', agency_name: 'B' })
      row.attributes.subject = { type: 'Campaign', ulid: CAMPAIGN }

      const { wrapper, router } = await clickFirstRow([row])

      expect(router.currentRoute.value.fullPath).toBe(`/creator/jobs/${CAMPAIGN}`)
      wrapper.unmount()
    })

    it('a row with no resolvable destination marks read and STAYS (never a dead click)', async () => {
      const { wrapper, router } = await clickFirstRow([
        // An old agency row: no campaign_ulid, no usable subject.
        makeRow('campaign_application.submitted', { creator_name: 'N', campaign_name: 'C' }),
      ])

      expect(router.currentRoute.value.fullPath).toBe('/')
      expect(notificationsApi.markRead).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })

    it('a free-text value under a key that looks like a route key is not navigated to', async () => {
      const { wrapper, router } = await clickFirstRow([
        makeRow('assignment.draft_approved', { assignment_ulid: 'SENTINEL-FEEDBACK-xyz' }),
      ])

      expect(router.currentRoute.value.fullPath).toBe('/')
      wrapper.unmount()
    })

    it('an unknown (deferred / future) type marks read and stays', async () => {
      const { wrapper, router } = await clickFirstRow([
        makeRow('assignment.declined', { assignment_ulid: ASSIGNMENT }),
      ])

      expect(router.currentRoute.value.fullPath).toBe('/')
      expect(notificationsApi.markRead).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })
  })

  it('mark-all-read calls readAll + zeroes the badge + flips every row', async () => {
    const poll = makePoll()
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([
        makeRow('assignment.manually_verified', { campaign_name: 'A' }),
        makeRow('assignment.manually_verified', { campaign_name: 'B' }),
      ]) as never,
    )
    vi.mocked(notificationsApi.readAll).mockResolvedValue({} as never)
    const wrapper = mountCenter({ variant: 'dropdown', poll })
    await flushPromises()

    await wrapper.find('[data-test="notification-mark-all"]').trigger('click')
    await flushPromises()

    expect(notificationsApi.readAll).toHaveBeenCalledTimes(1)
    expect(poll.applyReadAll).toHaveBeenCalledTimes(1)
    expect(wrapper.findAll('[data-test~="notification-row--unread"]')).toHaveLength(0)
    wrapper.unmount()
  })

  it('every feed fetch reconciles the badge via poll.set(meta.unread_count)', async () => {
    const poll = makePoll()
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([makeRow('assignment.manually_verified', { campaign_name: 'A' })], {
        unread_count: 9,
      }) as never,
    )
    const wrapper = mountCenter({ variant: 'dropdown', poll })
    await flushPromises()

    expect(poll.set).toHaveBeenCalledWith(9)
    wrapper.unmount()
  })

  it('page variant renders pagination when last_page > 1 and loads the chosen page', async () => {
    vi.mocked(notificationsApi.list)
      .mockResolvedValueOnce(
        feed([makeRow('assignment.manually_verified', { campaign_name: 'A' })], {
          page: 1,
          last_page: 3,
        }) as never,
      )
      .mockResolvedValueOnce(
        feed([makeRow('assignment.manually_verified', { campaign_name: 'B' })], {
          page: 2,
          last_page: 3,
        }) as never,
      )
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()

    expect(wrapper.find('[data-test="notification-pagination"]').exists()).toBe(true)
    expect(notificationsApi.list).toHaveBeenLastCalledWith({ page: 1, perPage: 25 })

    // Drive the page change through the exposed loader (v-pagination's internal
    // button wiring is hostile to JSDOM clicks).
    await (wrapper.vm as unknown as { load: (n: number) => Promise<void> }).load(2)
    await flushPromises()
    expect(notificationsApi.list).toHaveBeenLastCalledWith({ page: 2, perPage: 25 })
    wrapper.unmount()
  })

  it('dropdown variant fetches the small recent slice (per_page=8)', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValue(feed([]) as never)
    const wrapper = mountCenter({ variant: 'dropdown', poll: makePoll() })
    await flushPromises()
    expect(notificationsApi.list).toHaveBeenCalledWith({ page: 1, perPage: 8 })
    wrapper.unmount()
  })

  it('renders the empty state when the feed is empty', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValue(feed([]) as never)
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()
    expect(wrapper.find('[data-test="notification-empty"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('renders the error state when the feed fetch rejects', async () => {
    vi.mocked(notificationsApi.list).mockRejectedValue(new Error('boom'))
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()
    expect(wrapper.find('[data-test="notification-error"]').exists()).toBe(true)
    wrapper.unmount()
  })
})

describe('NotificationCenter — localized render per live type', () => {
  async function renderBodyFor(
    type: NotificationType,
    data: Record<string, unknown>,
  ): Promise<string> {
    vi.mocked(notificationsApi.list).mockResolvedValue(feed([makeRow(type, data)]) as never)
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()
    const body = wrapper.find('[data-test="notification-body"]').text()
    wrapper.unmount()
    return body
  }

  it('assignment.draft_approved — creator review row', async () => {
    const body = await renderBodyFor('assignment.draft_approved', {
      campaign_name: 'Spring Launch',
      creator_name: 'Alex',
      outcome: 'approved',
      feedback: null,
      assignment_ulid: '01ASSIGN',
    })
    expect(body).toBe('Your draft for Spring Launch was approved.')
  })

  it('assignment.revision_requested — renders body + the feedback detail line', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([
        makeRow('assignment.revision_requested', {
          campaign_name: 'Spring Launch',
          creator_name: 'Alex',
          outcome: 'revision_requested',
          feedback: 'Brighten the lighting.',
          assignment_ulid: '01ASSIGN',
        }),
      ]) as never,
    )
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()
    expect(wrapper.find('[data-test="notification-body"]').text()).toBe(
      'Revisions were requested on your draft for Spring Launch.',
    )
    expect(wrapper.find('[data-test="notification-detail"]').text()).toContain(
      'Brighten the lighting.',
    )
    wrapper.unmount()
  })

  it('assignment.draft_rejected — creator review row', async () => {
    const body = await renderBodyFor('assignment.draft_rejected', {
      campaign_name: 'Spring Launch',
      creator_name: 'Alex',
      outcome: 'rejected',
      feedback: 'Off-brief.',
      assignment_ulid: '01ASSIGN',
    })
    expect(body).toBe('Your draft for Spring Launch was rejected.')
  })

  it('assignment.manually_verified — creator row', async () => {
    const body = await renderBodyFor('assignment.manually_verified', {
      campaign_name: 'Spring Launch',
      creator_name: 'Alex',
      assignment_ulid: '01ASSIGN',
    })
    expect(body).toBe('Your post for Spring Launch was verified.')
  })

  it('assignment.live_verified — creator row (AH-101: the automatic-verification notice)', async () => {
    const body = await renderBodyFor('assignment.live_verified', {
      campaign_name: 'Spring Launch',
      creator_name: 'Alex',
      assignment_ulid: '01ASSIGN',
    })
    expect(body).toBe('Your post for Spring Launch was verified.')
  })

  it('assignment.draft_submitted — AGENCY fan-out row binds creator_name + campaign_name (NOT assignment_ulid)', async () => {
    const body = await renderBodyFor('assignment.draft_submitted', {
      creator_name: 'Alex',
      campaign_name: 'Spring Launch',
      campaign_ulid: '01CAMPAIGN',
      // deliberately NO assignment_ulid — agency rows carry campaign_ulid.
    })
    expect(body).toBe('Alex submitted a draft for Spring Launch.')
    // Proof the template binds only its emit-site keys: no leak of the ulid,
    // and the message reads cleanly with no dangling/empty interpolation.
    expect(body).not.toContain('01CAMPAIGN')
    expect(body).not.toContain('{')
  })

  it('assignment.contracted — AGENCY fan-out row', async () => {
    const body = await renderBodyFor('assignment.contracted', {
      creator_name: 'Alex',
      campaign_name: 'Spring Launch',
      campaign_ulid: '01CAMPAIGN',
    })
    expect(body).toBe('Alex accepted the contract for Spring Launch.')
  })

  it('creator.approved — tolerates {} data (no required param, no throw)', async () => {
    const body = await renderBodyFor('creator.approved', {})
    expect(body).toBe('Your creator account has been approved.')
    expect(body).not.toContain('{')
  })

  it('creator.rejected — renders body + the rejection reason detail line', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([
        makeRow('creator.rejected', { rejection_reason: 'Insufficient portfolio depth.' }),
      ]) as never,
    )
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()
    expect(wrapper.find('[data-test="notification-body"]').text()).toBe(
      'Your creator application was not approved.',
    )
    expect(wrapper.find('[data-test="notification-detail"]').text()).toContain(
      'Insufficient portfolio depth.',
    )
    wrapper.unmount()
  })

  // AH-104b-del (B6) — the agency's "creator account erased" cancellation notice.
  describe('assignment.cancelled (the erased-creator notice)', () => {
    const CAMPAIGN_ULID = '01J9ZK3Q8M5T7WXYCAMPAGNAAA'
    const erasedBag = {
      campaign_name: 'Spring Launch',
      campaign_ulid: CAMPAIGN_ULID,
      assignment_ulid: '01J9ZK3Q8M5T7WXYABCDEFGHJK',
      reason: 'creator_account_erased',
      is_erased: true,
    }

    async function mountCancelled(data: Record<string, unknown>) {
      vi.mocked(notificationsApi.list).mockResolvedValue(
        feed([makeRow('assignment.cancelled', data)]) as never,
      )
      const wrapper = mountCenter({ variant: 'page' })
      await flushPromises()
      return wrapper
    }

    it('renders its own body (not the generic fallback) with the campaign name', async () => {
      const wrapper = await mountCancelled(erasedBag)

      const body = wrapper.find('[data-test="notification-body"]').text()
      expect(body).toBe('An assignment on Spring Launch was cancelled.')
      expect(body).not.toBe(enNotifications.notifications.types.fallback)
      wrapper.unmount()
    })

    it('shows the reason CODE through i18n, never the raw code', async () => {
      const wrapper = await mountCancelled(erasedBag)

      const detail = wrapper.find('[data-test="notification-detail"]').text()
      expect(detail).toContain(enNotifications.notifications.center.reasons.creator_account_erased)
      expect(detail).not.toContain('creator_account_erased')
      wrapper.unmount()
    })

    it('an unknown reason is shown as the free text it is', async () => {
      const wrapper = await mountCancelled({ ...erasedBag, reason: 'Budget was pulled.' })

      expect(wrapper.find('[data-test="notification-detail"]').text()).toBe(
        'Reason: Budget was pulled.',
      )
      wrapper.unmount()
    })

    it('names no creator anywhere on the row', async () => {
      const wrapper = await mountCancelled(erasedBag)

      expect(wrapper.text()).not.toContain('{')
      expect(wrapper.text()).not.toMatch(/undefined|null/u)
      wrapper.unmount()
    })

    it('a tap opens the campaign on its creators tab', async () => {
      vi.mocked(notificationsApi.list).mockResolvedValue(
        feed([makeRow('assignment.cancelled', erasedBag)]) as never,
      )
      vi.mocked(notificationsApi.markRead).mockResolvedValue({} as never)
      const wrapper = mountCenter({ variant: 'dropdown', poll: makePoll() })
      await flushPromises()

      await wrapper.find('[data-test~="notification-row"]').trigger('click')
      await flushPromises()

      expect(wrapper.vm.$router.currentRoute.value.fullPath).toBe(
        `/campaigns/${CAMPAIGN_ULID}?tab=creators`,
      )
      wrapper.unmount()
    })
  })
})

// ── The draft round on review-cycle rows (AH-068, D4 / Q1a) ──────────────────
describe('NotificationCenter — draft round', () => {
  /** The review-row data bag as it was stored BEFORE AH-068: no `version`. */
  const HISTORICAL_ROW = {
    campaign_name: 'Spring Launch',
    creator_name: 'Alex',
    outcome: 'revision_requested',
    feedback: 'Brighten the lighting.',
    assignment_ulid: '01ASSIGN',
  }

  async function mountRow(data: Record<string, unknown>) {
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([makeRow('assignment.revision_requested', data)]) as never,
    )
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()
    return wrapper
  }

  it('renders the round on a row that carries a version', async () => {
    const wrapper = await mountRow({ ...HISTORICAL_ROW, version: 2 })

    expect(wrapper.find('[data-test="notification-round"]').text()).toBe('Draft 2')
    wrapper.unmount()
  })

  /**
   * The proof that Q1(a) was the right mechanism. Every notification row already
   * in production was written without a `version`; a `{version}` placeholder in
   * the body template would have left a hole in all of them. Here the historical
   * row renders its body BYTE-IDENTICALLY to the round-carrying row — the round
   * is additive, never a hole.
   */
  it('a pre-AH-068 row renders byte-identically, with no round element at all', async () => {
    const historical = await mountRow(HISTORICAL_ROW)
    const historicalBody = historical.find('[data-test="notification-body"]').text()

    expect(historical.find('[data-test="notification-round"]').exists()).toBe(false)
    // No hole, no literal placeholder, no stray punctuation.
    expect(historicalBody).toBe('Revisions were requested on your draft for Spring Launch.')
    expect(historicalBody).not.toContain('{')
    // The feedback detail line is untouched too.
    expect(historical.find('[data-test="notification-detail"]').text()).toContain(
      'Brighten the lighting.',
    )
    historical.unmount()

    const withRound = await mountRow({ ...HISTORICAL_ROW, version: 2 })
    expect(withRound.find('[data-test="notification-body"]').text()).toBe(historicalBody)
    withRound.unmount()
  })

  it('ignores a version that is not a number rather than rendering a broken label', async () => {
    const wrapper = await mountRow({ ...HISTORICAL_ROW, version: 'two' })

    expect(wrapper.find('[data-test="notification-round"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('renders the round on the agency fan-out row too — the leg that shares one verb', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([
        makeRow('assignment.draft_submitted', {
          creator_name: 'Alex',
          campaign_name: 'Spring Launch',
          campaign_ulid: '01CAMPAIGN',
          version: 3,
        }),
      ]) as never,
    )
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()

    expect(wrapper.find('[data-test="notification-round"]').text()).toBe('Draft 3')
    expect(wrapper.find('[data-test="notification-body"]').text()).toBe(
      'Alex submitted a draft for Spring Launch.',
    )
    wrapper.unmount()
  })
})

describe('NotificationCenter — generic fallback (forward-compat)', () => {
  it('an emit-less but KNOWN type (assignment.payment_funded) renders the safe fallback', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([makeRow('assignment.payment_funded', { amount_minor_units: 50000 })]) as never,
    )
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()
    expect(wrapper.find('[data-test="notification-body"]').text()).toBe(
      'You have a new notification.',
    )
    wrapper.unmount()
  })

  it('a genuinely UNKNOWN string (not in the union) renders the safe fallback — no missing-key throw', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValue(
      feed([makeRow('totally.unrecognised.future.verb', { whatever: true })]) as never,
    )
    const wrapper = mountCenter({ variant: 'page' })
    await flushPromises()
    expect(wrapper.find('[data-test="notification-body"]').text()).toBe(
      'You have a new notification.',
    )
    wrapper.unmount()
  })
})
