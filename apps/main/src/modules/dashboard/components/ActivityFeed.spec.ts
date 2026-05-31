/**
 * Sprint 4 Chunk 1 (1c) — Vitest coverage for the ActivityFeed widget,
 * under the theme-aware dashboard harness.
 *
 * Pins: empty / loading / error states; the per-action localized templates
 * (incl. actor interpolation, the system fallback for actor-less rows, and
 * whitelisted bulk-invite metadata counts); and that a raw action string is
 * never rendered.
 *
 * Defense-in-depth (§5.17): the SOT for WHICH rows + which metadata keys
 * reach the client is the backend (`DashboardActivity*Test`); this spec
 * asserts the SPA renders the curated rows as localized human-readable copy.
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountDashboardPage } from '../../../../tests/unit/helpers/mountDashboardPage'

import ActivityFeed from './ActivityFeed.vue'

vi.mock('../api/dashboard.api', () => ({
  dashboardApi: {
    summary: vi.fn(),
    activity: vi.fn(),
  },
}))

import { dashboardApi, type DashboardActivityItem } from '../api/dashboard.api'

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

function makeItem(overrides: Partial<DashboardActivityItem> = {}): DashboardActivityItem {
  return {
    id: '01HZACTIVITY0000000000001',
    action: 'creator.invited',
    actor_label: 'Grace Hopper',
    created_at: '2026-05-20T10:00:00.000Z',
    metadata: {},
    ...overrides,
  }
}

describe('ActivityFeed (Sprint 4 Chunk 1, 1c)', () => {
  let cleanup: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    cleanup?.()
    cleanup = null
    Object.keys(localStorageStore).forEach((k) => delete localStorageStore[k])
  })

  it('shows the empty state when the feed has no rows', async () => {
    vi.mocked(dashboardApi.activity).mockResolvedValue({ data: [] })

    const h = await mountDashboardPage(ActivityFeed)
    cleanup = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-test="dashboard-activity-empty"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="activity-feed-list"]').exists()).toBe(false)
  })

  it('surfaces a localized error when the activity request fails', async () => {
    vi.mocked(dashboardApi.activity).mockRejectedValue(new Error('500'))

    const h = await mountDashboardPage(ActivityFeed)
    cleanup = h.unmount
    await flushPromises()

    const alert = h.wrapper.find('[data-test="activity-feed-error"]')
    expect(alert.exists()).toBe(true)
    expect(alert.text()).toContain("We couldn't load recent activity")
  })

  it('renders one localized row per item with the actor interpolated', async () => {
    vi.mocked(dashboardApi.activity).mockResolvedValue({
      data: [
        makeItem({ id: 'a', action: 'creator.invited', actor_label: 'Grace Hopper' }),
        makeItem({ id: 'b', action: 'brand.created', actor_label: 'Ada Lovelace' }),
      ],
    })

    const h = await mountDashboardPage(ActivityFeed)
    cleanup = h.unmount
    await flushPromises()

    const rows = h.wrapper.findAll('[data-test="activity-feed-item"]')
    expect(rows).toHaveLength(2)
    expect(h.wrapper.text()).toContain('Grace Hopper invited a creator')
    expect(h.wrapper.text()).toContain('Ada Lovelace created a brand')
  })

  it('interpolates whitelisted bulk-invite metadata counts into the template', async () => {
    vi.mocked(dashboardApi.activity).mockResolvedValue({
      data: [
        makeItem({
          action: 'bulk_invite.completed',
          actor_label: 'Grace Hopper',
          metadata: { invited: 7, already_invited: 1, failed: 2 },
        }),
      ],
    })

    const h = await mountDashboardPage(ActivityFeed)
    cleanup = h.unmount
    await flushPromises()

    expect(h.wrapper.text()).toContain('7 invited')
    expect(h.wrapper.text()).toContain('2 failed')
  })

  it('falls back to the system label when a row has no actor', async () => {
    vi.mocked(dashboardApi.activity).mockResolvedValue({
      data: [makeItem({ action: 'brand.archived', actor_label: null })],
    })

    const h = await mountDashboardPage(ActivityFeed)
    cleanup = h.unmount
    await flushPromises()

    expect(h.wrapper.text()).toContain('The system archived a brand')
  })

  it('never renders a raw action string', async () => {
    vi.mocked(dashboardApi.activity).mockResolvedValue({
      data: [
        makeItem({ id: 'a', action: 'creator.invited' }),
        makeItem({ id: 'b', action: 'agency_creator_relation.created' }),
        makeItem({ id: 'c', action: 'agency_settings.updated' }),
      ],
    })

    const h = await mountDashboardPage(ActivityFeed)
    cleanup = h.unmount
    await flushPromises()

    const text = h.wrapper.text()
    expect(text).not.toContain('creator.invited')
    expect(text).not.toContain('agency_creator_relation.created')
    expect(text).not.toContain('agency_settings.updated')
  })
})
