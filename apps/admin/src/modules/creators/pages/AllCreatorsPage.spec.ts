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

    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      status: 'approved',
      kyc_status: 'verified',
      connected: true,
      page: 1,
      per_page: 25,
    })
  })

  it('resets to page 1 when a filter changes after paging forward', async () => {
    vi.mocked(adminCreatorsApi.list).mockResolvedValue(listResponse({}, 100))

    const h = await mountCreatorPage(AllCreatorsPage, {
      initialRoute: { name: 'app.creators.all' },
    })
    teardown = h.unmount
    await flushPromises()

    await h.wrapper
      .findComponent({ name: 'VDataTableServer' })
      .vm.$emit('update:options', { page: 3, itemsPerPage: 25 })
    await flushPromises()

    vi.mocked(adminCreatorsApi.list).mockClear()
    await h.wrapper.find('[data-testid="admin-all-creators-filter-connected-yes"]').trigger('click')
    await flushPromises()

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
})
