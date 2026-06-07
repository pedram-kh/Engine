/**
 * ExportRequestsPage unit tests (Sprint 13, D-11) — SHELL.
 *
 * Focus: the page loads the export queue, renders the shell-state notice
 * when the backend reports `meta.shell: true`, renders rows when data is
 * present (the S14-forward path), and surfaces the API error code on
 * failure.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/compliance/api/compliance.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/compliance/api/compliance.api')>(
    '@/modules/compliance/api/compliance.api',
  )
  return {
    ...actual,
    adminComplianceApi: {
      listExports: vi.fn(),
      listErasures: vi.fn(),
    },
  }
})

import { adminComplianceApi } from '@/modules/compliance/api/compliance.api'

import { mountCompliancePage } from '../../../../tests/unit/helpers/mountCompliancePage'
import ExportRequestsPage from './ExportRequestsPage.vue'

describe('ExportRequestsPage (Sprint 13, D-11)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('renders the shell notice for an empty shell response', async () => {
    vi.mocked(adminComplianceApi.listExports).mockResolvedValue({
      data: [],
      meta: { total: 0, shell: true },
    })

    const h = await mountCompliancePage(ExportRequestsPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-export-requests-shell"]').exists()).toBe(true)
    expect(adminComplianceApi.listExports).toHaveBeenCalledOnce()
  })

  it('renders rows when the queue has data (the S14-forward path)', async () => {
    vi.mocked(adminComplianceApi.listExports).mockResolvedValue({
      data: [
        {
          id: '1',
          type: 'data_export_requests',
          attributes: {
            subject_email: 'subject@example.com',
            status: 'pending',
            requested_at: '2026-06-07T00:00:00Z',
          },
        },
      ],
      meta: { total: 1, shell: false },
    })

    const h = await mountCompliancePage(ExportRequestsPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-export-requests-shell"]').exists()).toBe(false)
    expect(h.wrapper.text()).toContain('subject@example.com')
  })

  it('surfaces the API error code on failure', async () => {
    vi.mocked(adminComplianceApi.listExports).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountCompliancePage(ExportRequestsPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-export-requests-error"]').exists()).toBe(true)
  })
})
