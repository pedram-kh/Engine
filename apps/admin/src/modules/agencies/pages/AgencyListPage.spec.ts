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

    const h = await mountAgencyPage(AgencyListPage, {
      initialRoute: { name: 'app.agencies.list' },
    })
    teardown = h.unmount
    await flushPromises()

    vi.mocked(adminAgenciesApi.list).mockClear()

    // Vuetify's `clearable` v-text-field emits `null` (not '') when the
    // clear (X) button is pressed. The load() must coerce it instead of
    // calling `null.trim()`, which previously threw and blanked the table.
    const searchField = h.wrapper.findComponent<typeof import('vuetify/components').VTextField>(
      '[data-testid="admin-agency-list-search"]',
    )
    searchField.vm.$emit('update:modelValue', null)
    await flushPromises()
    vi.advanceTimersByTime(300)
    await flushPromises()

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
})
