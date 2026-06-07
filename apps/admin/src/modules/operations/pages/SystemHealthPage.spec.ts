/**
 * SystemHealthPage unit tests (Sprint 13, D-8).
 *
 * Focus: the probe rendering — the overall status chip, the per-check
 * rows, the degraded state when a dependency reports `error`, and the
 * error surface when the probe request itself fails.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/operations/api/operations.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/operations/api/operations.api')>(
    '@/modules/operations/api/operations.api',
  )
  return {
    ...actual,
    adminOperationsApi: {
      health: vi.fn(),
    },
  }
})

import { adminOperationsApi } from '@/modules/operations/api/operations.api'

import { mountOperationsPage } from '../../../../tests/unit/helpers/mountOperationsPage'
import SystemHealthPage from './SystemHealthPage.vue'

describe('SystemHealthPage (Sprint 13, D-8)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('renders the healthy status and per-check rows', async () => {
    vi.mocked(adminOperationsApi.health).mockResolvedValue({
      data: { status: 'ok', checks: { database: 'ok', cache: 'ok' } },
    })

    const h = await mountOperationsPage(SystemHealthPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-system-health-status"]').text()).toContain('Healthy')
    expect(h.wrapper.find('[data-testid="admin-system-health-check-database"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-testid="admin-system-health-check-cache"]').exists()).toBe(true)
  })

  it('renders the degraded status when a dependency reports error', async () => {
    vi.mocked(adminOperationsApi.health).mockResolvedValue({
      data: { status: 'degraded', checks: { database: 'ok', cache: 'error' } },
    })

    const h = await mountOperationsPage(SystemHealthPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-system-health-status"]').text()).toContain(
      'Degraded',
    )
  })

  it('surfaces the API error code when the probe fails', async () => {
    vi.mocked(adminOperationsApi.health).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountOperationsPage(SystemHealthPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-system-health-error"]').exists()).toBe(true)
  })
})
