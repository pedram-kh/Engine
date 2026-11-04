/**
 * AgencyListPage unit tests (Sprint 13, D-3).
 *
 * Focus: the list wiring — initial load, the status filter re-querying
 * the backend with the right param, search debounce, and the
 * click-through navigation to the detail drill-in. The backend owns the
 * platform_admin gate + the cross-agency read; this spec asserts the SPA
 * sends the right query and renders/navigates the rows.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/agencies/api/agencies.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/agencies/api/agencies.api')>(
    '@/modules/agencies/api/agencies.api',
  )
  return {
    ...actual,
    adminAgenciesApi: {
      list: vi.fn(),
      show: vi.fn(),
      suspend: vi.fn(),
      reactivate: vi.fn(),
    },
  }
})

import { adminAgenciesApi, type AdminAgencyListResponse } from '@/modules/agencies/api/agencies.api'

import { mountAgencyPage } from '../../../../tests/unit/helpers/mountAgencyPage'
import AgencyListPage from './AgencyListPage.vue'

function listResponse(
  overrides: Partial<AdminAgencyListResponse['data'][number]['attributes']> = {},
  total = 1,
): AdminAgencyListResponse {
  return {
    data: [
      {
        id: '01HQAGENCY',
        type: 'agencies',
        attributes: {
          name: 'Acme Talent',
          slug: 'acme-talent',
          country_code: 'US',
          subscription_tier: 'pro',
          subscription_status: 'active',
          is_active: true,
          is_suspended: false,
          suspended_at: null,
          suspended_reason: null,
          member_count: 4,
          created_at: '2026-05-10T00:00:00Z',
          ...overrides,
        },
      },
    ],
    meta: { total, page: 1, per_page: 25, last_page: 1 },
  }
}

function pagedResponse(
  name: string,
  total: number,
  lastPage: number,
  page = 1,
): AdminAgencyListResponse {
  const base = listResponse({ name }, total)
  return { ...base, meta: { total, page, per_page: 25, last_page: lastPage } }
}

describe('AgencyListPage (Sprint 13, D-3)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('loads with the "all" filter on mount and renders rows', async () => {
    vi.mocked(adminAgenciesApi.list).mockResolvedValue(listResponse())

    const h = await mountAgencyPage(AgencyListPage, {
      initialRoute: { name: 'app.agencies.list' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(adminAgenciesApi.list).toHaveBeenCalledWith({
      status: undefined,
      search: undefined,
      page: 1,
      per_page: 25,
    })
    // One fetch on mount: the table's echo is not a second request.
    expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
    expect(h.wrapper.find('[data-testid="admin-agency-list-name-01HQAGENCY"]').text()).toContain(
      'Acme Talent',
    )
  })

  it('re-queries with status=suspended when the suspended chip is clicked', async () => {
    vi.mocked(adminAgenciesApi.list).mockResolvedValue(listResponse())

    const h = await mountAgencyPage(AgencyListPage, {
      initialRoute: { name: 'app.agencies.list' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminAgenciesApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-agency-list-filter-suspended"]').trigger('click')
    await flushPromises()

    await vi.waitFor(() =>
      expect(h.router.currentRoute.value.query).toEqual({ status: 'suspended' }),
    )
    expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
    expect(adminAgenciesApi.list).toHaveBeenCalledWith({
      status: 'suspended',
      search: undefined,
      page: 1,
      per_page: 25,
    })
  })

  it('navigates to the detail page when a row name is clicked', async () => {
    vi.mocked(adminAgenciesApi.list).mockResolvedValue(listResponse())

    const h = await mountAgencyPage(AgencyListPage, {
      initialRoute: { name: 'app.agencies.list' },
    })
    teardown = h.unmount
    await flushPromises()

    const push = vi.spyOn(h.router, 'push').mockResolvedValue(undefined)
    await h.wrapper.find('[data-testid="admin-agency-list-name-01HQAGENCY"]').trigger('click')
    await flushPromises()

    expect(push).toHaveBeenCalledWith({
      name: 'app.agencies.detail',
      params: { ulid: '01HQAGENCY' },
    })
  })

  it('reloads with no search term (not a crash) when the clearable search is cleared to null', async () => {
    vi.useFakeTimers()
    vi.mocked(adminAgenciesApi.list).mockResolvedValue(listResponse())

    // A term is applied (it arrives through the URL), then cleared.
    const h = await mountAgencyPage(AgencyListPage, {
      initialRoute: { name: 'app.agencies.list', query: { q: 'acme' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(adminAgenciesApi.list).toHaveBeenCalledWith({
      status: undefined,
      search: 'acme',
      page: 1,
      per_page: 25,
    })

    vi.mocked(adminAgenciesApi.list).mockClear()

    // Vuetify's `clearable` v-text-field emits `null` (not '') when the
    // clear (X) button is pressed. The load() must coerce it instead of
    // calling `null.trim()`, which previously threw and blanked the table.
    // The clear travels through the draft, the 300 ms pause, and the applied
    // term (`q`), which then leaves the URL.
    const searchField = h.wrapper.findComponent<typeof import('vuetify/components').VTextField>(
      '[data-testid="admin-agency-list-search"]',
    )
    searchField.vm.$emit('update:modelValue', null)
    await flushPromises()
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
    expect(adminAgenciesApi.list).toHaveBeenCalledWith({
      status: undefined,
      search: undefined,
      page: 1,
      per_page: 25,
    })
    expect(h.wrapper.find('[data-testid="admin-agency-list-error"]').exists()).toBe(false)

    vi.useRealTimers()
  })

  it('surfaces the API error code when the list load fails', async () => {
    vi.mocked(adminAgenciesApi.list).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountAgencyPage(AgencyListPage, {
      initialRoute: { name: 'app.agencies.list' },
    })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-agency-list-error"]').exists()).toBe(true)
  })

  describe('URL-backed browse context (AH-117)', () => {
    afterEach(() => {
      vi.useRealTimers()
    })

    it('seeds the first request and the text field from the URL, with one request; a junk URL reads as the defaults', async () => {
      vi.mocked(adminAgenciesApi.list).mockResolvedValue(pagedResponse('Seeded', 120, 12, 2))

      const seeded = await mountAgencyPage(AgencyListPage, {
        initialRoute: {
          name: 'app.agencies.list',
          query: { q: 'acme', status: 'suspended', page: '2', per_page: '10' },
        },
      })
      teardown = seeded.unmount
      await flushPromises()

      expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
      expect(adminAgenciesApi.list).toHaveBeenCalledWith({
        status: 'suspended',
        search: 'acme',
        page: 2,
        per_page: 10,
      })
      const input = seeded.wrapper.find('[data-testid="admin-agency-list-search"] input')
      expect((input.element as HTMLInputElement).value).toBe('acme')
      // Nothing was rewritten: the URL the operator arrived on is the URL they keep.
      await new Promise((resolve) => setTimeout(resolve, 400))
      expect(seeded.router.currentRoute.value.query).toEqual({
        q: 'acme',
        status: 'suspended',
        page: '2',
        per_page: '10',
      })
      expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
      seeded.unmount()
      teardown = null
      vi.mocked(adminAgenciesApi.list).mockClear()

      const junk = await mountAgencyPage(AgencyListPage, {
        initialRoute: {
          name: 'app.agencies.list',
          query: { status: 'bogus', page: '-2', per_page: '100000' },
        },
      })
      teardown = junk.unmount
      await flushPromises()

      expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
      expect(adminAgenciesApi.list).toHaveBeenCalledWith({
        status: undefined,
        search: undefined,
        page: 1,
        per_page: 25,
      })
    })

    it('a typed term reaches the URL ONCE after the pause, and resets to page 1 with one request', async () => {
      vi.mocked(adminAgenciesApi.list).mockResolvedValue(pagedResponse('Row', 120, 5, 3))

      const h = await mountAgencyPage(AgencyListPage, {
        initialRoute: { name: 'app.agencies.list', query: { page: '3' } },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminAgenciesApi.list).mockClear()
      const replace = vi.spyOn(h.router, 'replace')

      vi.useFakeTimers()
      const field = h.wrapper.findComponent<typeof import('vuetify/components').VTextField>(
        '[data-testid="admin-agency-list-search"]',
      )
      for (const typed of ['a', 'ac', 'acm', 'acme']) {
        field.vm.$emit('update:modelValue', typed)
        await flushPromises()
        vi.advanceTimersByTime(100)
      }
      // Mid-typing: no request, and the URL still holds page 3 and no term.
      expect(adminAgenciesApi.list).not.toHaveBeenCalled()
      expect(replace).not.toHaveBeenCalled()
      expect(h.router.currentRoute.value.query).toEqual({ page: '3' })

      vi.advanceTimersByTime(300)
      vi.useRealTimers()
      await flushPromises()

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ q: 'acme' }))
      expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
      expect(adminAgenciesApi.list).toHaveBeenCalledWith({
        status: undefined,
        search: 'acme',
        page: 1,
        per_page: 25,
      })
    })

    it('a trailing space is not a new term: no URL write, no request', async () => {
      vi.mocked(adminAgenciesApi.list).mockResolvedValue(listResponse())

      const h = await mountAgencyPage(AgencyListPage, {
        initialRoute: { name: 'app.agencies.list', query: { q: 'acme' } },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminAgenciesApi.list).mockClear()

      vi.useFakeTimers()
      const field = h.wrapper.findComponent<typeof import('vuetify/components').VTextField>(
        '[data-testid="admin-agency-list-search"]',
      )
      field.vm.$emit('update:modelValue', 'acme ')
      await flushPromises()
      vi.advanceTimersByTime(400)
      vi.useRealTimers()
      await flushPromises()
      await new Promise((resolve) => setTimeout(resolve, 50))

      expect(adminAgenciesApi.list).not.toHaveBeenCalled()
      expect(h.router.currentRoute.value.query).toEqual({ q: 'acme' })
      const input = h.wrapper.find('[data-testid="admin-agency-list-search"] input')
      expect((input.element as HTMLInputElement).value).toBe('acme ')
    })

    it('re-seeds when the route changes underneath it: a bare sidebar URL clears the text field and resets the list', async () => {
      vi.mocked(adminAgenciesApi.list).mockResolvedValue(pagedResponse('Row', 120, 5, 3))

      const h = await mountAgencyPage(AgencyListPage, {
        initialRoute: {
          name: 'app.agencies.list',
          query: { q: 'acme', status: 'suspended', page: '3' },
        },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminAgenciesApi.list).mockClear()

      await h.router.replace({ name: 'app.agencies.list' })
      await flushPromises()

      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({}))
      const input = h.wrapper.find('[data-testid="admin-agency-list-search"] input')
      expect((input.element as HTMLInputElement).value).toBe('')
      // Let any (wrongly) armed debounce fire before counting.
      await new Promise((resolve) => setTimeout(resolve, 400))
      expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
      expect(adminAgenciesApi.list).toHaveBeenCalledWith({
        status: undefined,
        search: undefined,
        page: 1,
        per_page: 25,
      })
    })

    it('a route re-seed drops the keystrokes still waiting on the pause', async () => {
      vi.mocked(adminAgenciesApi.list).mockResolvedValue(pagedResponse('Row', 120, 5, 3))

      const h = await mountAgencyPage(AgencyListPage, {
        initialRoute: { name: 'app.agencies.list', query: { q: 'acme', page: '3' } },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminAgenciesApi.list).mockClear()

      const field = h.wrapper.findComponent<typeof import('vuetify/components').VTextField>(
        '[data-testid="admin-agency-list-search"]',
      )
      field.vm.$emit('update:modelValue', 'acmex')
      await flushPromises()
      await new Promise((resolve) => setTimeout(resolve, 100))

      // The operator clicks the sidebar link before the pause ends.
      await h.router.replace({ name: 'app.agencies.list' })
      await flushPromises()
      await new Promise((resolve) => setTimeout(resolve, 450))

      // The stale draft was not applied over the reset: no `q`, one reset request.
      expect(h.router.currentRoute.value.query).toEqual({})
      expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
      expect(adminAgenciesApi.list).toHaveBeenCalledWith({
        status: undefined,
        search: undefined,
        page: 1,
        per_page: 25,
      })
    })

    it('clamps a page past the last one to the last page: ONE refetch, the empty page never shown', async () => {
      let resolveLast!: (value: AdminAgencyListResponse) => void
      vi.mocked(adminAgenciesApi.list).mockImplementation((params) =>
        params?.page === 2
          ? new Promise<AdminAgencyListResponse>((resolve) => {
              resolveLast = resolve
            })
          : Promise.resolve({
              ...pagedResponse('Out of range row', 30, 2, 9),
              meta: { total: 30, page: 9, per_page: 25, last_page: 2 },
            }),
      )

      const h = await mountAgencyPage(AgencyListPage, {
        initialRoute: { name: 'app.agencies.list', query: { page: '9' } },
      })
      teardown = h.unmount
      await flushPromises()

      // The out-of-range response is on screen at no point while the refetch is out.
      expect(h.wrapper.text()).not.toContain('Out of range row')

      resolveLast(pagedResponse('Last page row', 30, 2, 2))
      await flushPromises()
      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ page: '2' }))

      expect(adminAgenciesApi.list).toHaveBeenCalledTimes(2)
      expect(vi.mocked(adminAgenciesApi.list).mock.calls[1]![0]).toEqual({
        status: undefined,
        search: undefined,
        page: 2,
        per_page: 25,
      })
      expect(h.wrapper.text()).toContain('Last page row')
      expect(h.wrapper.text()).not.toContain('Out of range row')
    })

    it('pages through the table by REPLACING the URL, and offers 10, 25, 50 and 100 rows (no "All")', async () => {
      vi.mocked(adminAgenciesApi.list).mockResolvedValue(pagedResponse('Row', 120, 5))

      const h = await mountAgencyPage(AgencyListPage, {
        initialRoute: { name: 'app.agencies.list' },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminAgenciesApi.list).mockClear()
      const push = vi.spyOn(h.router, 'push')

      expect(
        h.wrapper.findComponent({ name: 'VDataTableServer' }).props('itemsPerPageOptions'),
      ).toEqual([10, 25, 50, 100])

      await h.wrapper.find('button[aria-label="Next page"]').trigger('click')
      await flushPromises()
      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ page: '2' }))

      expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
      expect(adminAgenciesApi.list).toHaveBeenCalledWith({
        status: undefined,
        search: undefined,
        page: 2,
        per_page: 25,
      })
      expect(push).not.toHaveBeenCalled()
    })

    it('a page-size change from the table is one request and lands in the URL', async () => {
      vi.mocked(adminAgenciesApi.list).mockResolvedValue(pagedResponse('Row', 120, 3))

      const h = await mountAgencyPage(AgencyListPage, {
        initialRoute: { name: 'app.agencies.list' },
      })
      teardown = h.unmount
      await flushPromises()
      vi.mocked(adminAgenciesApi.list).mockClear()

      h.wrapper.findComponent({ name: 'VDataTableServer' }).vm.$emit('update:options', {
        page: 1,
        itemsPerPage: 50,
        sortBy: [],
        groupBy: [],
        search: undefined,
      })
      await flushPromises()
      await vi.waitFor(() => expect(h.router.currentRoute.value.query).toEqual({ per_page: '50' }))
      expect(adminAgenciesApi.list).toHaveBeenCalledTimes(1)
      expect(adminAgenciesApi.list).toHaveBeenCalledWith({
        status: undefined,
        search: undefined,
        page: 1,
        per_page: 50,
      })
    })

    it('drops a stale response: the slow first request cannot overwrite the newer one', async () => {
      let resolveFirst!: (value: AdminAgencyListResponse) => void
      vi.mocked(adminAgenciesApi.list)
        .mockImplementationOnce(
          () =>
            new Promise<AdminAgencyListResponse>((resolve) => {
              resolveFirst = resolve
            }),
        )
        .mockResolvedValue(pagedResponse('Newer row', 1, 1))

      const h = await mountAgencyPage(AgencyListPage, {
        initialRoute: { name: 'app.agencies.list' },
      })
      teardown = h.unmount
      await flushPromises()

      await h.wrapper.find('[data-testid="admin-agency-list-filter-active"]').trigger('click')
      await flushPromises()
      expect(h.wrapper.text()).toContain('Newer row')

      resolveFirst(pagedResponse('Stale row', 1, 1))
      await flushPromises()

      expect(h.wrapper.text()).toContain('Newer row')
      expect(h.wrapper.text()).not.toContain('Stale row')
    })
  })
})
