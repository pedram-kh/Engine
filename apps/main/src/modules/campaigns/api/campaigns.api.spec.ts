/**
 * Unit tests for the campaigns API wrapper (Sprint 8 Chunk 1). Pins the
 * `list()` query-builder (brand / status / date filters threaded; empty values
 * dropped) + the create/update/assignments endpoints. The HTTP singleton is
 * mocked so no transport runs.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/core/api', () => ({
  http: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

import { http } from '@/core/api'
import { campaignsApi } from './campaigns.api'

const mockHttp = vi.mocked(http)

function lastGetUrl(): URLSearchParams {
  const url = mockHttp.get.mock.calls.at(-1)![0] as string
  return new URLSearchParams(url.split('?')[1] ?? '')
}

describe('campaignsApi.list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
    })
  })

  it('threads brand / status / date filters', () => {
    void campaignsApi.list('agency-ulid', {
      brand: 'brand-ulid',
      status: 'active',
      starts_from: '2026-07-01',
      starts_to: '2026-09-01',
    })
    const query = lastGetUrl()
    expect(query.get('brand')).toBe('brand-ulid')
    expect(query.get('status')).toBe('active')
    expect(query.get('starts_from')).toBe('2026-07-01')
    expect(query.get('starts_to')).toBe('2026-09-01')
  })

  it('omits empty filter values', () => {
    void campaignsApi.list('agency-ulid', { brand: '', status: undefined, starts_from: '' })
    const query = lastGetUrl()
    expect(query.has('brand')).toBe(false)
    expect(query.has('status')).toBe(false)
    expect(query.has('starts_from')).toBe(false)
  })

  it('omits the query string entirely with no params', () => {
    void campaignsApi.list('agency-ulid')
    expect(mockHttp.get).toHaveBeenCalledWith('/agencies/agency-ulid/campaigns')
  })
})

describe('campaignsApi create / update / assignments', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.post.mockResolvedValue({ data: {} })
    mockHttp.patch.mockResolvedValue({ data: {} })
    mockHttp.get.mockResolvedValue({ data: [], meta: {} })
  })

  it('POSTs the create payload to the campaigns collection', () => {
    void campaignsApi.create('agency-ulid', {
      brand_id: 'brand-ulid',
      name: 'Summer',
      objective: 'awareness',
      budget_minor_units: 100000,
      budget_currency: 'EUR',
    })
    expect(mockHttp.post).toHaveBeenCalledWith('/agencies/agency-ulid/campaigns', {
      brand_id: 'brand-ulid',
      name: 'Summer',
      objective: 'awareness',
      budget_minor_units: 100000,
      budget_currency: 'EUR',
    })
  })

  it('PATCHes the settings update to the campaign resource', () => {
    void campaignsApi.update('agency-ulid', 'campaign-ulid', { status: 'active' })
    expect(mockHttp.patch).toHaveBeenCalledWith('/agencies/agency-ulid/campaigns/campaign-ulid', {
      status: 'active',
    })
  })

  it('GETs the read-only assignment list for the Creators tab', () => {
    void campaignsApi.assignments('agency-ulid', 'campaign-ulid')
    expect(mockHttp.get).toHaveBeenCalledWith(
      '/agencies/agency-ulid/campaigns/campaign-ulid/assignments',
    )
  })

  it('POSTs an invite to the campaign assignments collection (Chunk 2, D-3)', () => {
    void campaignsApi.invite('agency-ulid', 'campaign-ulid', {
      creator_id: 'creator-ulid',
      agreed_fee_minor_units: 500000,
      agreed_fee_currency: 'EUR',
      acknowledged: true,
    })
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/agencies/agency-ulid/campaigns/campaign-ulid/assignments',
      {
        creator_id: 'creator-ulid',
        agreed_fee_minor_units: 500000,
        agreed_fee_currency: 'EUR',
        acknowledged: true,
      },
    )
  })

  it('POSTs a re-invite to the verb-on-existing path (Chunk 2, D-7)', () => {
    void campaignsApi.reinvite('agency-ulid', 'campaign-ulid', 'assignment-ulid', {
      agreed_fee_minor_units: 650000,
      agreed_fee_currency: 'EUR',
    })
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/agencies/agency-ulid/campaigns/campaign-ulid/assignments/assignment-ulid/reinvite',
      { agreed_fee_minor_units: 650000, agreed_fee_currency: 'EUR' },
    )
  })
})
