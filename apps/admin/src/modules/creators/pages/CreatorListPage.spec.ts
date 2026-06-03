/**
 * CreatorListPage unit tests — Sprint 4 Chunk 3 (Cluster 3).
 *
 * Focus: the review-queue list wiring — initial load, the status
 * filter re-querying the backend with the right param, and the
 * click-through navigation to the detail drill-in. The backend owns
 * the platform_admin gate + the actual filtering; this spec asserts
 * the SPA sends the right query and renders/navigates the rows.
 */

import { ApiError } from '@catalyst/api-client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

vi.mock('@/modules/creators/api/creators.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/creators/api/creators.api')>(
    '@/modules/creators/api/creators.api',
  )
  return {
    ...actual,
    adminCreatorsApi: {
      list: vi.fn(),
    },
  }
})

import {
  adminCreatorsApi,
  type AdminCreatorListResponse,
} from '@/modules/creators/api/creators.api'

import { mountCreatorPage } from '../../../../tests/unit/helpers/mountCreatorPage'
import CreatorListPage from './CreatorListPage.vue'

function listResponse(
  overrides: Partial<AdminCreatorListResponse['data'][number]['attributes']> = {},
  total = 1,
): AdminCreatorListResponse {
  return {
    data: [
      {
        id: '01HQABCD',
        type: 'creators',
        attributes: {
          display_name: 'Jane Doe',
          email: 'jane@example.com',
          application_status: 'pending',
          kyc_status: 'verified',
          profile_completeness_score: 100,
          submitted_at: '2026-05-14T00:00:00Z',
          created_at: '2026-05-10T00:00:00Z',
          ...overrides,
        },
      },
    ],
    meta: { total, page: 1, per_page: 25, last_page: 1 },
  }
}

describe('CreatorListPage — review queue (Sprint 4 Chunk 3, Cluster 3)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('loads the pending queue on mount and renders the rows', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(CreatorListPage, {
      initialRoute: { name: 'app.creators.list' },
    })
    teardown = h.unmount
    await flushPromises()

    // Default filter is `pending` → the SPA sends status=pending.
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: 'pending',
      page: 1,
      per_page: 25,
    })
    expect(h.wrapper.find('[data-testid="admin-creator-list-name-01HQABCD"]').text()).toContain(
      'Jane Doe',
    )
  })

  it('drops the status param when the "all" filter is selected', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(CreatorListPage, {
      initialRoute: { name: 'app.creators.list' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-creator-list-filter-all"]').trigger('click')
    await flushPromises()

    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: undefined,
      page: 1,
      per_page: 25,
    })
  })

  it('re-queries with the chosen status when a filter chip is clicked', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(CreatorListPage, {
      initialRoute: { name: 'app.creators.list' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-creator-list-filter-rejected"]').trigger('click')
    await flushPromises()

    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: 'rejected',
      page: 1,
      per_page: 25,
    })
  })

  it('navigates to the detail page when a row name is clicked', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(CreatorListPage, {
      initialRoute: { name: 'app.creators.list' },
    })
    teardown = h.unmount
    await flushPromises()

    const push = vi.spyOn(h.router, 'push').mockResolvedValue(undefined)
    await h.wrapper.find('[data-testid="admin-creator-list-name-01HQABCD"]').trigger('click')
    await flushPromises()

    expect(push).toHaveBeenCalledWith({
      name: 'app.creators.detail',
      params: { ulid: '01HQABCD' },
    })
  })

  it('surfaces the API error code when the list load fails', async () => {
    vi.mocked(adminCreatorsApi.list).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountCreatorPage(CreatorListPage, {
      initialRoute: { name: 'app.creators.list' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-creator-list-error"]').exists()).toBe(true)
  })
})
