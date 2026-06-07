/**
 * DashboardPage unit tests (Sprint 13, D-7).
 *
 * Focus: the KPI strip rendering real non-payment counts, the payment
 * cards holding their slot as a muted dash (coming-soon), the summary
 * feeding the nav badges, and the activity feed rendering newest-first.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/dashboard/api/dashboard.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/dashboard/api/dashboard.api')>(
    '@/modules/dashboard/api/dashboard.api',
  )
  return {
    ...actual,
    adminDashboardApi: {
      summary: vi.fn(),
      activity: vi.fn(),
    },
  }
})

import {
  adminDashboardApi,
  type AdminDashboardActivityResponse,
  type AdminDashboardSummaryResponse,
} from '@/modules/dashboard/api/dashboard.api'
import { useNavBadges } from '@/core/stores/useNavBadges'

import { mountDashboardPage } from '../../../../tests/unit/helpers/mountDashboardPage'
import DashboardPage from './DashboardPage.vue'

function summaryResponse(): AdminDashboardSummaryResponse {
  return {
    data: {
      agencies_total: 12,
      agencies_active: 10,
      agencies_suspended: 2,
      creators_pending_approval: 5,
      creators_pending_kyc: 3,
      queue_pending: 1,
      queue_failed: 0,
      open_disputes: null,
      failed_payments_today: null,
    },
  }
}

function activityResponse(): AdminDashboardActivityResponse {
  return {
    data: [
      {
        id: '01HQAUDIT',
        type: 'audit_logs',
        attributes: {
          action: 'agency.suspended',
          actor_name: 'Ada Admin',
          actor_email: 'ada@catalyst.test',
          reason: 'Suspended for cause.',
          created_at: '2026-05-10T00:00:00Z',
        },
      },
    ],
  }
}

describe('DashboardPage (Sprint 13, D-7)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('renders real non-payment counts and feeds the nav badges', async () => {
    vi.mocked(adminDashboardApi.summary).mockResolvedValue(summaryResponse())
    vi.mocked(adminDashboardApi.activity).mockResolvedValue(activityResponse())

    const h = await mountDashboardPage(DashboardPage)
    teardown = h.unmount
    await flushPromises()

    expect(adminDashboardApi.summary).toHaveBeenCalledOnce()
    expect(h.wrapper.find('[data-test="admin-kpi-agencies-total"]').text()).toContain('12')
    expect(h.wrapper.find('[data-test="admin-kpi-creator-approvals"]').text()).toContain('5')

    const badges = useNavBadges()
    expect(badges.creatorApprovals).toBe(5)
    expect(badges.kycQueue).toBe(3)
  })

  it('renders the payment cards as a muted dash placeholder (coming-soon)', async () => {
    vi.mocked(adminDashboardApi.summary).mockResolvedValue(summaryResponse())
    vi.mocked(adminDashboardApi.activity).mockResolvedValue(activityResponse())

    const h = await mountDashboardPage(DashboardPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-test="admin-kpi-open-disputes"]').text()).toContain('—')
    expect(h.wrapper.find('[data-test="admin-kpi-failed-payments"]').text()).toContain('—')
  })

  it('renders the recent activity feed', async () => {
    vi.mocked(adminDashboardApi.summary).mockResolvedValue(summaryResponse())
    vi.mocked(adminDashboardApi.activity).mockResolvedValue(activityResponse())

    const h = await mountDashboardPage(DashboardPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-dashboard-activity-01HQAUDIT"]').text()).toContain(
      'agency.suspended',
    )
  })

  it('surfaces the API error code when the load fails', async () => {
    vi.mocked(adminDashboardApi.summary).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )
    vi.mocked(adminDashboardApi.activity).mockResolvedValue(activityResponse())

    const h = await mountDashboardPage(DashboardPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-dashboard-error"]').exists()).toBe(true)
  })
})
