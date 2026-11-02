/**
 * Sprint 4 Chunk 1 (1b) — Vitest coverage for the agency DashboardPage,
 * mounted under the theme-aware dashboard harness (dark-default, real
 * Catalyst themes).
 *
 * Mirrors the Brands data-pattern coverage: loading skeletons, the loaded
 * happy path (real KPIs + placeholder dashes), the error alert, and the 1b
 * activity-region empty-state placeholder.
 *
 * AH-116: the Active campaigns card reads the campaigns list's `meta.status_counts`
 * (a second call, `per_page=1`), and the Payments due card is not drawn while the API
 * reports null for it.
 *
 * Defense-in-depth (§5.17): the KPI VALUES are enforced by the backend
 * summary Pest suite (the SOT); these specs assert the page wires the
 * payload into the strip + handles loading/error/empty.
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountDashboardPage } from '../../../../tests/unit/helpers/mountDashboardPage'

import DashboardPage from './DashboardPage.vue'

vi.mock('../api/dashboard.api', () => ({
  dashboardApi: {
    summary: vi.fn(),
    activity: vi.fn(),
  },
}))

import { dashboardApi } from '../api/dashboard.api'

vi.mock('@/modules/campaigns/api/campaigns.api', () => ({
  campaignsApi: {
    list: vi.fn(),
  },
}))

import { campaignsApi } from '@/modules/campaigns/api/campaigns.api'

/** A campaigns-list response whose only interesting part is the status counts. */
function campaignList(statusCounts: Record<string, number> | undefined) {
  return {
    data: [],
    meta: {
      total: 24,
      page: 1,
      per_page: 1,
      last_page: 24,
      ...(statusCounts === undefined ? {} : { status_counts: statusCounts }),
    },
  } as never
}

const COUNTS = { draft: 4, active: 11, paused: 3, completed: 5, cancelled: 1 }

// localStorage stub so useAgencyStore can persist `currentAgencyId`.
const localStorageStore: Record<string, string> = {}
Object.defineProperty(globalThis, 'localStorage', {
  value: {
    getItem: (k: string): string | null => localStorageStore[k] ?? null,
    setItem: (k: string, v: string): void => {
      localStorageStore[k] = v
    },
    removeItem: (k: string): void => {
      delete localStorageStore[k]
    },
  },
  writable: true,
})

function summaryPayload(overrides: Record<string, number | null> = {}) {
  return {
    data: {
      creators_in_roster: 12,
      pending_creator_applications: 3,
      active_campaigns: null,
      payments_due: null,
      ...overrides,
    },
  }
}

describe('DashboardPage (Sprint 4 Chunk 1, 1b)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    // The embedded <ActivityFeed> self-fetches; default it to an empty feed
    // so these summary-focused tests don't trip its error path.
    vi.mocked(dashboardApi.activity).mockResolvedValue({ data: [] })
    vi.mocked(campaignsApi.list).mockResolvedValue(campaignList(COUNTS))
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
    Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
  })

  it('shows KPI skeletons while the summary request is in flight', async () => {
    // Never-resolving promise → loading stays true.
    vi.mocked(dashboardApi.summary).mockReturnValue(new Promise(() => {}))

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount

    expect(h.wrapper.find('[data-test="kpi-card-skeleton"]').exists()).toBe(true)
  })

  it('renders the real KPI values once loaded; Active campaigns comes from the list counts', async () => {
    vi.mocked(dashboardApi.summary).mockResolvedValue(summaryPayload())

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount
    await flushPromises()

    expect(dashboardApi.summary).toHaveBeenCalledWith('agency-ulid')
    // One row asked for: only the list's meta is read.
    expect(campaignsApi.list).toHaveBeenCalledTimes(1)
    expect(campaignsApi.list).toHaveBeenCalledWith('agency-ulid', { per_page: 1 })

    // Real cards bind to the payload.
    expect(h.wrapper.find('[data-test="kpi-creatorsInRoster"]').text()).toContain('12')
    expect(h.wrapper.find('[data-test="kpi-pendingApplications"]').text()).toContain('3')
    // The summary's own `active_campaigns` (null) is not what the card shows.
    const campaigns = h.wrapper.find('[data-test="kpi-activeCampaigns"]')
    expect(campaigns.find('[data-test="kpi-card-value"]').text()).toBe('11')
  })

  it('draws the muted dash on Active campaigns when the API sends no counts', async () => {
    vi.mocked(dashboardApi.summary).mockResolvedValue(summaryPayload())
    vi.mocked(campaignsApi.list).mockResolvedValue(campaignList(undefined))

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount
    await flushPromises()

    const campaigns = h.wrapper.find('[data-test="kpi-activeCampaigns"]')
    expect(campaigns.find('[data-test="kpi-card-value"]').text()).toBe('—')
    expect(h.wrapper.find('[data-test="kpi-creatorsInRoster"]').text()).toContain('12')
  })

  it('a failed campaigns call leaves the other cards intact and no error alert', async () => {
    vi.mocked(dashboardApi.summary).mockResolvedValue(summaryPayload())
    vi.mocked(campaignsApi.list).mockRejectedValue(new Error('500'))

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount
    await flushPromises()

    const campaigns = h.wrapper.find('[data-test="kpi-activeCampaigns"]')
    expect(campaigns.find('[data-test="kpi-card-value"]').text()).toBe('—')
    expect(h.wrapper.find('[data-test="kpi-creatorsInRoster"]').text()).toContain('12')
    expect(h.wrapper.find('[data-test="kpi-pendingApplications"]').text()).toContain('3')
    expect(h.wrapper.find('[data-test="dashboard-error"]').exists()).toBe(false)
  })

  it('a failed summary still shows the error alert, and the counts do not rescue it', async () => {
    vi.mocked(dashboardApi.summary).mockRejectedValue(new Error('500'))

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-test="dashboard-error"]').exists()).toBe(true)
  })

  async function cardOrder(payments: number | null): Promise<string[]> {
    vi.mocked(dashboardApi.summary).mockResolvedValue(summaryPayload({ payments_due: payments }))

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount
    await flushPromises()

    const nonCardAnchors = new Set(['kpi-strip', 'kpi-card-value', 'kpi-card-skeleton'])
    return (
      h.wrapper
        .findAll('[data-test^="kpi-"]')
        .map((el) => el.attributes('data-test'))
        // Keep the card roots; drop the strip container + inner anchors.
        .filter((dt): dt is string => dt !== undefined && !nonCardAnchors.has(dt))
    )
  }

  it('renders three KPI cards, in the locked order, while Payments due is null (D-c1-4, AH-116)', async () => {
    expect(await cardOrder(null)).toEqual([
      'kpi-activeCampaigns',
      'kpi-creatorsInRoster',
      'kpi-pendingApplications',
    ])
  })

  it('renders all four KPI cards in the locked order once Payments due is a number', async () => {
    expect(await cardOrder(1250)).toEqual([
      'kpi-activeCampaigns',
      'kpi-creatorsInRoster',
      'kpi-pendingApplications',
      'kpi-paymentsDue',
    ])
  })

  it('surfaces a localized error alert when the summary request fails', async () => {
    vi.mocked(dashboardApi.summary).mockRejectedValue(new Error('500'))

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount
    await flushPromises()

    const alert = h.wrapper.find('[data-test="dashboard-error"]')
    expect(alert.exists()).toBe(true)
    expect(alert.text()).toContain("We couldn't load your dashboard")
  })

  it('mounts the activity feed in the activity region (1c)', async () => {
    vi.mocked(dashboardApi.summary).mockResolvedValue(summaryPayload())

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-test="dashboard-activity"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="activity-feed"]').exists()).toBe(true)
    // With the default empty-feed mock, the feed shows its empty state.
    expect(h.wrapper.find('[data-test="dashboard-activity-empty"]').exists()).toBe(true)
  })
})
