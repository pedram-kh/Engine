/**
 * AuditLogPage unit tests (Sprint 13, D-5).
 *
 * Focus: the viewer wiring — initial load, filters re-querying the backend
 * with the right (indexed) params, cursor pagination walking forward and
 * back via the opaque tokens, and the error surface. The backend owns the
 * platform_admin gate + the cross-agency read; this spec asserts the SPA
 * sends the right query and renders the rows / cursor state.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/audit/api/audit.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/audit/api/audit.api')>(
    '@/modules/audit/api/audit.api',
  )
  return {
    ...actual,
    adminAuditApi: {
      list: vi.fn(),
    },
  }
})

import { adminAuditApi, type AdminAuditLogResponse } from '@/modules/audit/api/audit.api'

import { mountAuditPage } from '../../../../tests/unit/helpers/mountAuditPage'
import AuditLogPage from './AuditLogPage.vue'

function page(
  overrides: {
    action?: string
    nextCursor?: string | null
    prevCursor?: string | null
    id?: string
  } = {},
): AdminAuditLogResponse {
  return {
    data: [
      {
        id: overrides.id ?? '01HQAUDIT',
        type: 'audit_logs',
        attributes: {
          action: overrides.action ?? 'agency.suspended',
          actor_id: 7,
          actor_name: 'Ada Admin',
          actor_email: 'ada@catalyst.test',
          actor_role: 'super_admin',
          agency_id: 3,
          subject_type: 'agency',
          subject_ulid: '01HQAGENCY',
          reason: 'Suspended for cause.',
          ip: '127.0.0.1',
          created_at: '2026-05-10T00:00:00Z',
        },
      },
    ],
    meta: {
      per_page: 50,
      next_cursor: overrides.nextCursor ?? null,
      prev_cursor: overrides.prevCursor ?? null,
      has_more: (overrides.nextCursor ?? null) !== null,
    },
  }
}

describe('AuditLogPage (Sprint 13, D-5)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('loads the first page on mount with no cursor and renders rows', async () => {
    vi.mocked(adminAuditApi.list).mockResolvedValue(page())

    const h = await mountAuditPage(AuditLogPage)
    teardown = h.unmount
    await flushPromises()

    expect(adminAuditApi.list).toHaveBeenCalledWith({
      action: undefined,
      subject_ulid: undefined,
      date_from: undefined,
      date_to: undefined,
      per_page: 50,
      cursor: undefined,
    })
    expect(h.wrapper.find('[data-testid="admin-audit-row-01HQAUDIT"]').text()).toContain(
      'agency.suspended',
    )
  })

  it('re-queries with the typed filters (indexed columns) on Apply', async () => {
    vi.mocked(adminAuditApi.list).mockResolvedValue(page())

    const h = await mountAuditPage(AuditLogPage)
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminAuditApi.list).mockClear()
    await h.wrapper
      .find('[data-testid="admin-audit-filter-action"] input')
      .setValue('feature_flag.toggled')
    await h.wrapper.find('[data-testid="admin-audit-filter-subject"] input').setValue('01HQXYZ')
    await h.wrapper.find('[data-testid="admin-audit-apply"]').trigger('click')
    await flushPromises()

    expect(adminAuditApi.list).toHaveBeenCalledWith({
      action: 'feature_flag.toggled',
      subject_ulid: '01HQXYZ',
      date_from: undefined,
      date_to: undefined,
      per_page: 50,
      cursor: undefined,
    })
  })

  it('walks forward with next_cursor, then back to the prior cursor', async () => {
    vi.mocked(adminAuditApi.list).mockResolvedValue(page({ nextCursor: 'CURSOR_2' }))

    const h = await mountAuditPage(AuditLogPage)
    teardown = h.unmount
    await flushPromises()

    // Forward — sends the next_cursor token from the first page.
    vi.mocked(adminAuditApi.list).mockResolvedValue(
      page({ id: '01HQAUDIT2', prevCursor: 'CURSOR_1' }),
    )
    await h.wrapper.find('[data-testid="admin-audit-next"]').trigger('click')
    await flushPromises()

    expect(adminAuditApi.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: 'CURSOR_2' }),
    )

    // Back — the cursor stack pops to the first (cursorless) page.
    vi.mocked(adminAuditApi.list).mockResolvedValue(page({ nextCursor: 'CURSOR_2' }))
    await h.wrapper.find('[data-testid="admin-audit-prev"]').trigger('click')
    await flushPromises()

    expect(adminAuditApi.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: undefined }),
    )
  })

  it('surfaces the API error code when the load fails', async () => {
    vi.mocked(adminAuditApi.list).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountAuditPage(AuditLogPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-audit-error"]').exists()).toBe(true)
  })
})
