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

    expect(adminCreatorsApi.list).toHaveBeenCalledWith({
      kyc_status: 'verified',
      page: 1,
      per_page: 25,
    })
  })
})
