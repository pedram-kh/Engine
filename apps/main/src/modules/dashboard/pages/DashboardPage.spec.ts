/**
 * Sprint 4 Chunk 1 (1b) — Vitest coverage for the agency DashboardPage,
 * mounted under the theme-aware dashboard harness (dark-default, real
 * Catalyst themes).
 *
 * Mirrors the Brands data-pattern coverage: loading skeletons, the loaded
 * happy path (real KPIs + placeholder dashes), the error alert, and the 1b
 * activity-region empty-state placeholder.
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

  it('renders the real KPI values and muted placeholders once loaded', async () => {
    vi.mocked(dashboardApi.summary).mockResolvedValue(summaryPayload())

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount
    await flushPromises()

    expect(dashboardApi.summary).toHaveBeenCalledWith('agency-ulid')

    // Real cards bind to the payload.
    expect(h.wrapper.find('[data-test="kpi-creatorsInRoster"]').text()).toContain('12')
    expect(h.wrapper.find('[data-test="kpi-pendingApplications"]').text()).toContain('3')

    // Placeholder cards (null) render the muted dash.
    const campaigns = h.wrapper.find('[data-test="kpi-activeCampaigns"]')
    const payments = h.wrapper.find('[data-test="kpi-paymentsDue"]')
    expect(campaigns.find('[data-test="kpi-card-value"]').text()).toBe('—')
    expect(payments.find('[data-test="kpi-card-value"]').text()).toBe('—')
  })

  it('renders the KPI cards in the locked order (D-c1-4)', async () => {
    vi.mocked(dashboardApi.summary).mockResolvedValue(summaryPayload())

    const h = await mountDashboardPage(DashboardPage)
    cleanup = h.unmount
    await flushPromises()

    const nonCardAnchors = new Set(['kpi-strip', 'kpi-card-value', 'kpi-card-skeleton'])
    const order = h.wrapper
      .findAll('[data-test^="kpi-"]')
      .map((el) => el.attributes('data-test'))
      // Keep the four card roots; drop the strip container + inner anchors.
      .filter((dt): dt is string => dt !== undefined && !nonCardAnchors.has(dt))

    expect(order).toEqual([
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
