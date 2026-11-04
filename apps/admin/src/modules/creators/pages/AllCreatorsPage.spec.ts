/**
 * AllCreatorsPage unit tests — AH-079.
 *
 * Focus: the three chip filters (status / kyc / connected) each send the
 * right query param, drop it on "all", AND-compose when several are set
 * together, and reset paging to 1 on any filter change. The backend owns
 * the actual filtering (see AdminCreatorIndexTest.php's §5.34 disjoint
 * set) — this spec only asserts the SPA sends the right query and renders
 * rows, mirroring CreatorListPage.spec.ts's shape.
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
import AllCreatorsPage from './AllCreatorsPage.vue'

function pagedResponse(
  name: string,
  total: number,
  lastPage: number,
  page = 1,
): AdminCreatorListResponse {
  const base = listResponse({ display_name: name }, total)
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

describe('AllCreatorsPage — three-chip filters (AH-079)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('loads with no filter params on mount (all three chips default to "all")', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: undefined,
      kyc_status: undefined,
      connected: undefined,
      page: 1,
      per_page: 25,
    })
    // One fetch on mount: the table's echo is not a second request.
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(h.wrapper.find('[data-testid="admin-all-creators-name-01HQABCD"]').text()).toContain(
      'Jane Doe',
    )
  })

  it('sends the status param when a status chip is clicked, leaving the other two chips at "all"', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper
      .find('[data-testid="admin-all-creators-filter-status-approved"]')
      .trigger('click')
    await flushPromises()

    await vi.waitFor(() =>
      expect(h.router.currentRoute.value.query).toEqual({ status: 'approved' }),
    )
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: 'approved',
      kyc_status: undefined,
      connected: undefined,
      page: 1,
      per_page: 25,
    })
  })

  it('sends the kyc_status param when a KYC chip is clicked', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-all-creators-filter-kyc-verified"]').trigger('click')
    await flushPromises()

    await vi.waitFor(() =>
      expect(h.router.currentRoute.value.query).toEqual({ kyc_status: 'verified' }),
    )
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: undefined,
      kyc_status: 'verified',
      connected: undefined,
      page: 1,
      per_page: 25,
    })
  })

  it('sends connected=true when the "Connected" chip is clicked', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-all-creators-filter-connected-yes"]').trigger('click')
    await flushPromises()

    await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ connected: 'yes' }))
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: undefined,
      kyc_status: undefined,
      connected: true,
      page: 1,
      per_page: 25,
    })
  })

  it('sends connected=false when the "Not connected" chip is clicked', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-all-creators-filter-connected-no"]').trigger('click')
    await flushPromises()

    await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ connected: 'no' }))
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: undefined,
      kyc_status: undefined,
      connected: false,
      page: 1,
      per_page: 25,
    })
  })

  it('AND-composes all three chips at once (D4 — chips are combinable, not exclusive)', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    await h.wrapper
      .find('[data-testid="admin-all-creators-filter-status-approved"]')
      .trigger('click')
    await flushPromises()
    await h.wrapper.find('[data-testid="admin-all-creators-filter-kyc-verified"]').trigger('click')
    await flushPromises()
    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-all-creators-filter-connected-yes"]').trigger('click')
    await flushPromises()

    // The URL carries all three.
    await vi.waitFor(() =>
      expect(h.router.currentRoute.value.query).toEqual({
        status: 'approved',
        kyc_status: 'verified',
        connected: 'yes',
      }),
    )
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: 'approved',
      kyc_status: 'verified',
      connected: true,
      page: 1,
      per_page: 25,
    })
  })

  it('resets to page 1 when a filter changes after paging forward', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 100, 4, 2))

    // Page 2 arrives through the URL, as it does on a back from a detail page.
    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all', query: { page: '2' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: undefined,
      kyc_status: undefined,
      connected: undefined,
      page: 2,
      per_page: 25,
    })

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-all-creators-filter-connected-yes"]').trigger('click')
    await flushPromises()

    // The page is gone from the URL and the one request is for page 1.
    await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ connected: 'yes' }))
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: undefined,
      kyc_status: undefined,
      connected: true,
      page: 1,
      per_page: 25,
    })
  })

  it('navigates to the detail page when a row name is clicked', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    const push = vi.spyOn(h.router, 'push').mockResolvedValue(undefined)
    await h.wrapper.find('[data-testid="admin-all-creators-name-01HQABCD"]').trigger('click')
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

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-all-creators-error"]').exists()).toBe(true)
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

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-all-creators-name-01HQERASED"]').text()).toBe(
      'Deleted creator',
    )
    expect(h.wrapper.find('[data-testid="admin-all-creators-name-01HQLEAVING"]').text()).toContain(
      'Jane Doe',
    )
    expect(h.wrapper.text()).not.toContain('erased.invalid')
    // One chip: on the leaving creator, none on the erased one or the ordinary ones.
    const chips = h.wrapper.findAll('[data-testid="admin-deletion-requested-chip"]')
    expect(chips).toHaveLength(1)
    expect(chips[0]!.text()).toBe('Deletion requested')
  })

  describe('URL-backed browse context (AH-117)', () => {
    it('seeds all three chips and the page from the URL, with one request; a junk URL reads as the defaults', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Seeded', 120, 12, 3))

      const seeded = await mountCreatorPage(AllCreatorsPage, {
        initialRoute: {
          name: 'app.creators.all',
          query: {
            status: 'approved',
            kyc_status: 'verified',
            connected: 'no',
            page: '3',
            per_page: '10',
          },
        },
      })
      teardown = seeded.unmount
      await flushPromises()

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        status: 'approved',
        kyc_status: 'verified',
        connected: false,
        page: 3,
        per_page: 10,
      })
      // Nothing was rewritten: the URL the operator arrived on is the URL they keep.
      await new Promise((resolve) => setTimeout(resolve, 50))
      expect(seeded.router.currentRoute.value.query).toEqual({
        status: 'approved',
        kyc_status: 'verified',
        connected: 'no',
        page: '3',
        per_page: '10',
      })
      seeded.unmount()
      teardown = null
      vi.mocked(adminCreatorsApi.list).mockClear()

      const junk = await mountCreatorPage(AllCreatorsPage, {
        initialRoute: {
          name: 'app.creators.all',
          query: {
            status: 'bogus',
            kyc_status: 'nope',
            connected: 'maybe',
            page: '-2',
            per_page: '100000',
          },
        },
      })
      teardown = junk.unmount
      await flushPromises()

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        status: undefined,
        kyc_status: undefined,
        connected: undefined,
        page: 1,
        per_page: 25,
      })
    })

    it('re-seeds when the route changes underneath it: a bare sidebar URL resets all three chips and the page', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 5, 3))

      const h = await mountCreatorPage(AllCreatorsPage, {
        initialRoute: {
          name: 'app.creators.all',
          query: { status: 'approved', kyc_status: 'verified', connected: 'yes', page: '3' },
        },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminCreatorsApi.list).mockClear()

      await h.router.replace({ name: 'app.creators.all' })
      await flushPromises()

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({}))
      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        status: undefined,
        kyc_status: undefined,
        connected: undefined,
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

      const h = await mountCreatorPage(AllCreatorsPage, {
        initialRoute: { name: 'app.creators.all', query: { page: '9' } },
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
        status: undefined,
        kyc_status: undefined,
        connected: undefined,
        page: 2,
        per_page: 25,
      })
      expect(h.wrapper.text()).toContain('Last page row')
      expect(h.wrapper.text()).not.toContain('Out of range row')
    })

    it('pages through the table by REPLACING the URL, and offers 10, 25, 50 and 100 rows (no "All")', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 5))

      const h = await mountCreatorPage(AllCreatorsPage, {
        initialRoute: { name: 'app.creators.all' },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminCreatorsApi.list).mockClear()
      const push = vi.spyOn(h.router, 'push')

      expect(
        h.wrapper.findComponent({ name: 'VDataTableServer' }).props('itemsPerPageOptions'),
      ).toEqual([10, 25, 50, 100])

      await h.wrapper.find('button[aria-label="Next page"]').trigger('click')
      await flushPromises()
      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ page: '2' }))

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        status: undefined,
        kyc_status: undefined,
        connected: undefined,
        page: 2,
        per_page: 25,
      })
      expect(push).not.toHaveBeenCalled()
    })

    it('a page-size change from the table is one request and lands in the URL', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 3))

      const h = await mountCreatorPage(AllCreatorsPage, {
        initialRoute: { name: 'app.creators.all' },
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
        status: undefined,
        kyc_status: undefined,
        connected: undefined,
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

      const h = await mountCreatorPage(AllCreatorsPage, {
        initialRoute: { name: 'app.creators.all' },
      })
      teardown = h.unmount
      await flushPromises()

      await h.wrapper
        .find('[data-testid="admin-all-creators-filter-status-approved"]')
        .trigger('click')
      await flushPromises()
      expect(h.wrapper.text()).toContain('Newer row')

      resolveFirst(pagedResponse('Stale row', 1, 1))
      await flushPromises()

      expect(h.wrapper.text()).toContain('Newer row')
      expect(h.wrapper.text()).not.toContain('Stale row')
    })
  })
})
