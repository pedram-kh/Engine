/**
 * KycQueuePage unit tests (Sprint 13, D-4).
 *
 * The KYC queue is the SAME backend list endpoint with the orthogonal
 * `?kyc_status=` filter. This spec proves the page defaults to the
 * pending KYC filter, re-queries on chip change, and feeds the pending
 * count into the `kycQueue` nav badge.
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

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

import { useNavBadges } from '@/core/stores/useNavBadges'
import {
  adminCreatorsApi,
  type AdminCreatorListResponse,
} from '@/modules/creators/api/creators.api'

import { mountCreatorPage } from '../../../../tests/unit/helpers/mountCreatorPage'
import KycQueuePage from './KycQueuePage.vue'

function pagedResponse(
  name: string,
  total: number,
  lastPage: number,
  page = 1,
): AdminCreatorListResponse {
  const base = listResponse(total)
  return {
    data: [{ ...base.data[0]!, attributes: { ...base.data[0]!.attributes, display_name: name } }],
    meta: { total, page, per_page: 25, last_page: lastPage },
  }
}

function listResponse(total = 1): AdminCreatorListResponse {
  return {
    data: [
      {
        id: '01HQABCD',
        type: 'creators',
        attributes: {
          display_name: 'Jane Doe',
          email: 'jane@example.com',
          application_status: 'pending',
          kyc_status: 'pending',
          profile_completeness_score: 80,
          submitted_at: '2026-05-14T00:00:00Z',
          created_at: '2026-05-10T00:00:00Z',
          is_erased: false,
          deletion_pending: false,
        },
      },
    ],
    meta: { total, page: 1, per_page: 25, last_page: 1 },
  }
}

describe('KycQueuePage (Sprint 13, D-4)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('defaults to the pending KYC filter and feeds the nav badge', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse(3))

    const h = await mountCreatorPage(KycQueuePage, {
      initialRoute: { name: 'app.creators.kyc' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      kyc_status: 'pending',
      page: 1,
      per_page: 25,
    })
    expect(useNavBadges().kycQueue).toBe(3)
    // One fetch on mount: the table's echo is not a second request.
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(h.wrapper.find('[data-testid="admin-kyc-queue-name-01HQABCD"]').text()).toContain(
      'Jane Doe',
    )
  })

  it('drops the kyc_status param when the "all" chip is selected', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(KycQueuePage, {
      initialRoute: { name: 'app.creators.kyc' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-kyc-queue-filter-all"]').trigger('click')
    await flushPromises()

    // `all` is not the default here, so it is the one value the URL carries.
    await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ kyc_status: 'all' }))
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      kyc_status: undefined,
      page: 1,
      per_page: 25,
    })
  })

  it('re-queries with the chosen kyc_status when a chip is clicked', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse())

    const h = await mountCreatorPage(KycQueuePage, {
      initialRoute: { name: 'app.creators.kyc' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-kyc-queue-filter-verified"]').trigger('click')
    await flushPromises()

    await vi.waitFor(() =>
      expect(h.router.currentRoute.value.query).toEqual({ kyc_status: 'verified' }),
    )
    expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      kyc_status: 'verified',
      page: 1,
      per_page: 25,
    })
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

    const h = await mountCreatorPage(KycQueuePage, { initialRoute: { name: 'app.creators.kyc' } })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-kyc-queue-name-01HQERASED"]').text()).toBe(
      'Deleted creator',
    )
    expect(h.wrapper.find('[data-testid="admin-kyc-queue-name-01HQLEAVING"]').text()).toContain(
      'Jane Doe',
    )
    expect(h.wrapper.text()).not.toContain('erased.invalid')
    // One chip: on the leaving creator, none on the erased one or the ordinary ones.
    const chips = h.wrapper.findAll('[data-testid="admin-deletion-requested-chip"]')
    expect(chips).toHaveLength(1)
    expect(chips[0]!.text()).toBe('Deletion requested')
  })

  describe('URL-backed browse context (AH-117)', () => {
    it('seeds the first request from the URL, with one request; a junk URL reads as the defaults', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Seeded', 120, 12, 3))

      const seeded = await mountCreatorPage(KycQueuePage, {
        initialRoute: {
          name: 'app.creators.kyc',
          query: { kyc_status: 'verified', page: '3', per_page: '10' },
        },
      })
      teardown = seeded.unmount
      await flushPromises()

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        kyc_status: 'verified',
        page: 3,
        per_page: 10,
      })
      // Nothing was rewritten: the URL the operator arrived on is the URL they keep.
      await new Promise((resolve) => setTimeout(resolve, 50))
      expect(seeded.router.currentRoute.value.query).toEqual({
        kyc_status: 'verified',
        page: '3',
        per_page: '10',
      })
      seeded.unmount()
      teardown = null
      vi.mocked(adminCreatorsApi.list).mockClear()

      const junk = await mountCreatorPage(KycQueuePage, {
        initialRoute: {
          name: 'app.creators.kyc',
          query: { kyc_status: 'bogus', page: '-2', per_page: '100000' },
        },
      })
      teardown = junk.unmount
      await flushPromises()

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        kyc_status: 'pending',
        page: 1,
        per_page: 25,
      })
    })

    it('goes back to page 1 when a chip is clicked from page 3, with one request', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 5, 3))

      const h = await mountCreatorPage(KycQueuePage, {
        initialRoute: { name: 'app.creators.kyc', query: { page: '3' } },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminCreatorsApi.list).mockClear()

      await h.wrapper.find('[data-testid="admin-kyc-queue-filter-verified"]').trigger('click')
      await flushPromises()

      await vi.waitFor(() =>
        expect(h.router.currentRoute.value.query).toEqual({ kyc_status: 'verified' }),
      )
      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        kyc_status: 'verified',
        page: 1,
        per_page: 25,
      })
    })

    it('does not write the nav badge for a seeded non-pending filter', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse(7))

      const h = await mountCreatorPage(KycQueuePage, {
        initialRoute: { name: 'app.creators.kyc', query: { kyc_status: 'verified' } },
      })
      teardown = h.unmount
      await flushPromises()

      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(useNavBadges().kycQueue).not.toBe(7)
    })

    it('re-seeds when the route changes underneath it: a bare sidebar URL resets the list', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 5, 3))

      const h = await mountCreatorPage(KycQueuePage, {
        initialRoute: { name: 'app.creators.kyc', query: { kyc_status: 'verified', page: '3' } },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminCreatorsApi.list).mockClear()

      await h.router.replace({ name: 'app.creators.kyc' })
      await flushPromises()

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({}))
      expect(adminCreatorsApi.list).toHaveBeenCalledTimes(1)
      expect(adminCreatorsApi.list).toHaveBeenCalledWith({
        kyc_status: 'pending',
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

      const h = await mountCreatorPage(KycQueuePage, {
        initialRoute: { name: 'app.creators.kyc', query: { page: '9' } },
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
        kyc_status: 'pending',
        page: 2,
        per_page: 25,
      })
      expect(h.wrapper.text()).toContain('Last page row')
      expect(h.wrapper.text()).not.toContain('Out of range row')
    })

    it('pages through the table by REPLACING the URL, and offers 10, 25, 50 and 100 rows (no "All")', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 5))

      const h = await mountCreatorPage(KycQueuePage, {
        initialRoute: { name: 'app.creators.kyc' },
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
        kyc_status: 'pending',
        page: 2,
        per_page: 25,
      })
      expect(push).not.toHaveBeenCalled()
    })

    it('a page-size change from the table is one request and lands in the URL', async () => {
      vi.mocked(adminCreatorsApi.list).mockResolvedValue(pagedResponse('Row', 120, 3))

      const h = await mountCreatorPage(KycQueuePage, {
        initialRoute: { name: 'app.creators.kyc' },
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
        kyc_status: 'pending',
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

      const h = await mountCreatorPage(KycQueuePage, {
        initialRoute: { name: 'app.creators.kyc' },
      })
      teardown = h.unmount
      await flushPromises()

      await h.wrapper.find('[data-testid="admin-kyc-queue-filter-verified"]').trigger('click')
      await flushPromises()
      expect(h.wrapper.text()).toContain('Newer row')

      resolveFirst(pagedResponse('Stale row', 1, 1))
      await flushPromises()

      expect(h.wrapper.text()).toContain('Newer row')
      expect(h.wrapper.text()).not.toContain('Stale row')
    })
  })
})
