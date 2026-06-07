/**
 * ImpersonationLogPage unit tests (Sprint 13, D-9).
 *
 * Focus: the viewer wiring — initial load with no cursor, filters
 * (status / search / date range) re-querying the backend, cursor pagination
 * walking forward and back via the opaque tokens, and the error surface.
 * The backend owns the platform_admin gate + the cross-agency read; this
 * spec asserts the SPA sends the right query and renders the rows.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/support/api/impersonation.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/support/api/impersonation.api')>(
    '@/modules/support/api/impersonation.api',
  )
  return {
    ...actual,
    impersonationApi: {
      searchUsers: vi.fn(),
      start: vi.fn(),
      end: vi.fn(),
      sessions: vi.fn(),
    },
  }
})

import {
  impersonationApi,
  type ImpersonationLogResponse,
} from '@/modules/support/api/impersonation.api'

import { mountSupportPage } from '../../../../tests/unit/helpers/mountSupportPage'
import ImpersonationLogPage from './ImpersonationLogPage.vue'

function page(
  overrides: { id?: string; nextCursor?: string | null; prevCursor?: string | null } = {},
): ImpersonationLogResponse {
  return {
    data: [
      {
        id: overrides.id ?? '01HQIMP',
        type: 'impersonation_sessions',
        attributes: {
          admin_name: 'Ada Admin',
          admin_email: 'ada@catalyst.test',
          impersonated_user_name: 'Bob User',
          impersonated_user_email: 'bob@agency.test',
          impersonated_user_ulid: '01HQUSER',
          reason: 'Investigating a reported checkout bug.',
          status: 'active',
          started_at: '2026-05-10T00:00:00Z',
          claimed_at: null,
          ended_at: null,
          expires_at: '2026-05-10T00:30:00Z',
          ip: '127.0.0.1',
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

describe('ImpersonationLogPage (Sprint 13, D-9)', () => {
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
    vi.mocked(impersonationApi.sessions).mockResolvedValue(page())

    const h = await mountSupportPage(ImpersonationLogPage, {
      initialRoute: { name: 'app.support.impersonation-log' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(impersonationApi.sessions).toHaveBeenCalledWith({
      status: 'all',
      q: undefined,
      date_from: undefined,
      date_to: undefined,
      per_page: 50,
      cursor: undefined,
    })
    expect(h.wrapper.find('[data-testid="admin-impersonation-row-01HQIMP"]').text()).toContain(
      'Bob User',
    )
  })

  it('re-queries with the typed filters on Apply', async () => {
    vi.mocked(impersonationApi.sessions).mockResolvedValue(page())

    const h = await mountSupportPage(ImpersonationLogPage, {
      initialRoute: { name: 'app.support.impersonation-log' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(impersonationApi.sessions).mockClear()
    await h.wrapper
      .find('[data-testid="admin-impersonation-filter-search"] input')
      .setValue('bob@agency.test')
    await h.wrapper.find('[data-testid="admin-impersonation-apply"]').trigger('click')
    await flushPromises()

    expect(impersonationApi.sessions).toHaveBeenCalledWith(
      expect.objectContaining({ q: 'bob@agency.test', status: 'all', cursor: undefined }),
    )
  })

  it('walks forward with next_cursor, then back to the prior cursor', async () => {
    vi.mocked(impersonationApi.sessions).mockResolvedValue(page({ nextCursor: 'CURSOR_2' }))

    const h = await mountSupportPage(ImpersonationLogPage, {
      initialRoute: { name: 'app.support.impersonation-log' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(impersonationApi.sessions).mockResolvedValue(
      page({ id: '01HQIMP2', prevCursor: 'CURSOR_1' }),
    )
    await h.wrapper.find('[data-testid="admin-impersonation-next"]').trigger('click')
    await flushPromises()

    expect(impersonationApi.sessions).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: 'CURSOR_2' }),
    )

    vi.mocked(impersonationApi.sessions).mockResolvedValue(page({ nextCursor: 'CURSOR_2' }))
    await h.wrapper.find('[data-testid="admin-impersonation-prev"]').trigger('click')
    await flushPromises()

    expect(impersonationApi.sessions).toHaveBeenLastCalledWith(
      expect.objectContaining({ cursor: undefined }),
    )
  })

  it('surfaces the API error code when the load fails', async () => {
    vi.mocked(impersonationApi.sessions).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountSupportPage(ImpersonationLogPage, {
      initialRoute: { name: 'app.support.impersonation-log' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-impersonation-error"]').exists()).toBe(true)
  })
})
