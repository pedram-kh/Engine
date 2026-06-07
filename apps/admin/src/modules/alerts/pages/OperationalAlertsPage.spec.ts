/**
 * OperationalAlertsPage unit tests (Sprint 13, D-12).
 *
 * Focus: the page loads the operational alerts feed, renders the empty
 * state when there are none, renders rows when present, ALWAYS shows the
 * payment-alerts coming-soon block when the backend reports it (the D-13
 * discrete swappable block), and surfaces the API error code on failure.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/alerts/api/alerts.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/alerts/api/alerts.api')>(
    '@/modules/alerts/api/alerts.api',
  )
  return {
    ...actual,
    adminAlertsApi: {
      list: vi.fn(),
    },
  }
})

import { adminAlertsApi } from '@/modules/alerts/api/alerts.api'

import { mountAlertsPage } from '../../../../tests/unit/helpers/mountAlertsPage'
import OperationalAlertsPage from './OperationalAlertsPage.vue'

const paymentAlertsMeta = {
  coming_soon: true,
  types: ['assignment.payment_funded', 'assignment.payment_released'],
}

describe('OperationalAlertsPage (Sprint 13, D-12)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('renders the empty state and the payment-alerts coming-soon block', async () => {
    vi.mocked(adminAlertsApi.list).mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1, payment_alerts: paymentAlertsMeta },
    })

    const h = await mountAlertsPage(OperationalAlertsPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-operational-alerts-empty"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-testid="admin-payment-alerts-coming-soon"]').exists()).toBe(true)
  })

  it('renders operational alerts when present', async () => {
    vi.mocked(adminAlertsApi.list).mockResolvedValue({
      data: [
        {
          id: 'alert-1',
          type: 'notifications',
          attributes: {
            notification_type: 'creator.approved',
            data: {},
            read_at: null,
            created_at: '2026-06-07T00:00:00Z',
            actor: null,
            subject: null,
          },
        },
      ],
      meta: { total: 1, page: 1, per_page: 25, last_page: 1, payment_alerts: paymentAlertsMeta },
    })

    const h = await mountAlertsPage(OperationalAlertsPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-alert-alert-1"]').exists()).toBe(true)
    expect(h.wrapper.text()).toContain('Creator approved')
    expect(h.wrapper.find('[data-testid="admin-operational-alerts-empty"]').exists()).toBe(false)
  })

  it('surfaces the API error code on failure', async () => {
    vi.mocked(adminAlertsApi.list).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountAlertsPage(OperationalAlertsPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-operational-alerts-error"]').exists()).toBe(true)
  })
})
