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

/** Search-style response for a list of `total` rows over `lastPage` pages. */
function pagedResponse(
  name: string,
  total: number,
  lastPage: number,
  page = 1,
): AdminCreatorListResponse {
  const base = listResponse({ display_name: name })
  return { ...base, meta: { total, page, per_page: 25, last_page: lastPage } }
}

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
          is_erased: false,
          deletion_pending: false,
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
    // The echo guard: the table's mount echo is not a second request.
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
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

    // `all` is not the default here, so it is the one value the URL carries.
    await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ status: 'all' }))
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
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

    await vi.waitFor(() =>
      expect(h.router.currentRoute.value.query).toEqual({ status: 'rejected' }),
    )
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
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

  it('reads an erased creator and a leaving creator through the reader (AH-104c C2-4)', async () => {
    const base = listResponse().data[0]!
    vi.mocked(adminCreatorsApi.list).mockResolvedValue({
      data: [
        {
          ...base,
          id: '01HQERASED',
          attributes: {
            ...base.attributes,
            display_name: null,
            email: 'erased-01hqerased@erased.invalid',
            is_erased: true,
          },
        },
        { ...base, id: '01HQLEAVING', attributes: { ...base.attributes, deletion_pending: true } },
      ],
      meta: { total: 2, page: 1, per_page: 25, last_page: 1 },
    })

    const h = await mountCreatorPage(CreatorListPage, {
      initialRoute: { name: 'app.creators.list' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-creator-list-name-01HQERASED"]').text()).toBe(
      'Deleted creator',
    )
    expect(h.wrapper.find('[data-testid="admin-creator-list-name-01HQLEAVING"]').text()).toContain(
      'Jane Doe',
    )
    expect(h.wrapper.text()).not.toContain('erased.invalid')
    // One chip: on the leaving creator, none on the erased one or the ordinary ones.
    const chips = h.wrapper.findAll('[data-testid="admin-deletion-requested-chip"]')
    expect(chips).toHaveLength(1)
    expect(chips[0]!.text()).toBe('Deletion requested')
  })

  describe('URL-backed browse context (AH-117)', () => {
    function lastCall(): unknown {
      const calls = vi.mocked(adminCreatorsApi.list).mock.calls
      return calls[calls.length - 1]![0]
    }

    it('seeds the first request from the URL, with one request', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Seeded', 120, 12, 3))

      const h = await mountCreatorPage(CreatorListPage, {
        initialRoute: {
          name: 'app.creators.list',
          query: { status: 'approved', page: '3', per_page: '10' },
        },
      })
      teardown = h.unmount
      await flushPromises()

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        status: 'approved',
        page: 3,
        per_page: 10,
      })
      // Nothing was rewritten: the URL the operator arrived on is the URL they keep.
      await new Promise((resolve) => setTimeout(resolve, 50))
      expect(h.router.currentRoute.value.query).toEqual({
        status: 'approved',
        page: '3',
        per_page: '10',
      })
      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    })

    it('reads a junk URL as the defaults and never sends it to the API', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

      const h = await mountCreatorPage(CreatorListPage, {
        initialRoute: {
          name: 'app.creators.list',
          query: { status: 'bogus', page: '-2', per_page: '100000' },
        },
      })
      teardown = h.unmount
      await flushPromises()

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        status: 'pending',
        page: 1,
        per_page: 25,
      })
    })

    it('goes back to page 1 when a chip is clicked from page 3, with one request', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 5, 3))

      const h = await mountCreatorPage(CreatorListPage, {
        initialRoute: { name: 'app.creators.list', query: { page: '3' } },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminCreatorsApi.list).mockClear()

      await h.wrapper.find('[data-testid="admin-creator-list-filter-approved"]').trigger('click')
      await flushPromises()

      await vi.waitFor(() =>
        expect(h.router.currentRoute.value.query).toEqual({ status: 'approved' }),
      )
      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        status: 'approved',
        page: 1,
        per_page: 25,
      })
    })

    it('pages through the table by REPLACING the URL (back is not a page-by-page walk)', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 5))

      const h = await mountCreatorPage(CreatorListPage, {
        initialRoute: { name: 'app.creators.list' },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminCreatorsApi.list).mockClear()
      const push = vi.spyOn(h.router, 'push')
      const replace = vi.spyOn(h.router, 'replace')

      await h.wrapper.find('button[aria-label="Next page"]').trigger('click')
      await flushPromises()

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ page: '2' }))

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(lastCall()).toEqual({ status: 'pending', page: 2, per_page: 25 })
      expect(replace).toHaveBeenCalled()
      expect(push).not.toHaveBeenCalled()
    })

    it('re-seeds when the route changes underneath it: a bare sidebar URL resets the list', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 5, 3))

      const h = await mountCreatorPage(CreatorListPage, {
        initialRoute: { name: 'app.creators.list', query: { status: 'approved', page: '3' } },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminCreatorsApi.list).mockClear()

      await h.router.replace({ name: 'app.creators.list' })
      await flushPromises()

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({}))

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        status: 'pending',
        page: 1,
        per_page: 25,
      })
    })

    it('clamps a page past the last one to the last page: ONE refetch, the empty page never shown', async () => {
      let resolveLast!: (value: AdminCreatorListResponse) => void
      vi.mocked(adminCreatorsApi.list).mockImplementation((params) =>
        params?.page === 2
          ? new Promise<AdminCreatorListResponse>((resolve) => {
              resolveLast = resolve
            })
          : Promise.resolve({
              ...pagedResponse('Out of range row', 30, 2, 9),
              meta: { total: 30, page: 9, per_page: 25, last_page: 2 },
            }),
      )

      const h = await mountCreatorPage(CreatorListPage, {
        initialRoute: { name: 'app.creators.list', query: { page: '9' } },
      })
      teardown = h.unmount
      await flushPromises()

      // The out-of-range response is on screen at no point while the refetch is out.
      expect(h.wrapper.text()).not.toContain('Out of range row')

      resolveLast(pagedResponse('Last page row', 30, 2, 2))
      await flushPromises()
      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ page: '2' }))

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(2)
      expect(vi.mocked(adminCreatorsApi.list).mock.calls[1]![0]).toEqual({
        status: 'pending',
        page: 2,
        per_page: 25,
      })
      expect(h.wrapper.text()).toContain('Last page row')
      expect(h.wrapper.text()).not.toContain('Out of range row')
    })

    it('offers 10, 25, 50 and 100 rows per page and no "All"', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

      const h = await mountCreatorPage(CreatorListPage, {
        initialRoute: { name: 'app.creators.list' },
      })
      teardown = h.unmount
      await flushPromises()

      const table = h.wrapper.findComponent({ name: 'VDataTableServer' })
      expect(table.props('itemsPerPageOptions')).toEqual([10, 25, 50, 100])
    })

    it('a page-size change from the table is one request and lands in the URL', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 3))

      const h = await mountCreatorPage(CreatorListPage, {
        initialRoute: { name: 'app.creators.list' },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminCreatorsApi.list).mockClear()

      h.wrapper.findComponent({ name: 'VDataTableServer' }).vm.$emit('update:options', {
        page: 1,
        itemsPerPage: 50,
        sortBy: [],
        groupBy: [],
        search: undefined,
      })
      await flushPromises()

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ per_page: '50' }))
      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        status: 'pending',
        page: 1,
        per_page: 50,
      })
    })

    it('drops a stale response: the slow first request cannot overwrite the newer one', async () => {
      let resolveFirst!: (value: AdminCreatorListResponse) => void
      vi.mocked(adminCreatorsApi.list)
        .mockImplementationOnce(
          () =>
            new Promise<AdminCreatorListResponse>((resolve) => {
              resolveFirst = resolve
            }),
        )
        .mockResolvedValue(pagedResponse('Newer row', 1, 1))

      const h = await mountCreatorPage(CreatorListPage, {
        initialRoute: { name: 'app.creators.list' },
      })
      teardown = h.unmount
      await flushPromises()

      await h.wrapper.find('[data-testid="admin-creator-list-filter-approved"]').trigger('click')
      await flushPromises()
      expect(h.wrapper.text()).toContain('Newer row')

      resolveFirst(pagedResponse('Stale row', 1, 1))
      await flushPromises()

      expect(h.wrapper.text()).toContain('Newer row')
      expect(h.wrapper.text()).not.toContain('Stale row')
    })
  })
})
