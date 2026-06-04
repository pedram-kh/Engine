/**
 * Unit tests for the agency roster API wrapper.
 *
 * Pins the `list()` query-builder — specifically the Sprint 6.5 (D-6)
 * availability window threading: BOTH `available_from` + `available_to` are
 * sent together, and a one-sided / empty range sends NEITHER (the backend
 * ignores a half range, so we don't waste it on the wire). The HTTP singleton
 * is mocked so no transport runs.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'

vi.mock('@/core/api', () => ({
  http: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

import { http } from '@/core/api'
import { rosterApi } from './roster.api'

const mockHttp = vi.mocked(http)

function lastListUrl(): URLSearchParams {
  const url = mockHttp.get.mock.calls.at(-1)![0] as string
  return new URLSearchParams(url.split('?')[1] ?? '')
}

describe('rosterApi.list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 25, last_page: 1 },
    })
  })

  it('threads both availability bounds when both are present', () => {
    void rosterApi.list('agency-ulid', {
      available_from: '2026-06-08',
      available_to: '2026-06-12',
    })
    const query = lastListUrl()
    expect(query.get('available_from')).toBe('2026-06-08')
    expect(query.get('available_to')).toBe('2026-06-12')
  })

  it('sends NEITHER bound for a one-sided range (only available_from)', () => {
    void rosterApi.list('agency-ulid', { available_from: '2026-06-08' })
    const query = lastListUrl()
    expect(query.has('available_from')).toBe(false)
    expect(query.has('available_to')).toBe(false)
  })

  it('sends NEITHER bound for an empty-string range', () => {
    void rosterApi.list('agency-ulid', { available_from: '', available_to: '' })
    const query = lastListUrl()
    expect(query.has('available_from')).toBe(false)
    expect(query.has('available_to')).toBe(false)
  })

  it('omits the query string entirely with no params', () => {
    void rosterApi.list('agency-ulid')
    expect(mockHttp.get).toHaveBeenCalledWith('/agencies/agency-ulid/creators')
  })
})

describe('rosterApi.blacklist / unblacklist (Sprint 7)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.post.mockResolvedValue({ data: {} })
    mockHttp.delete.mockResolvedValue({ data: {} })
  })

  it('POSTs to the dedicated /blacklist endpoint (NOT the rating/notes PATCH)', () => {
    void rosterApi.blacklist('agency-ulid', 'creator-ulid', {
      scope: 'agency',
      type: 'hard',
      reason: 'spammy',
    })
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/agencies/agency-ulid/creators/creator-ulid/blacklist',
      {
        scope: 'agency',
        type: 'hard',
        reason: 'spammy',
      },
    )
    expect(mockHttp.patch).not.toHaveBeenCalled()
  })

  it('threads brand_id for a brand-scoped blacklist', () => {
    void rosterApi.blacklist('agency-ulid', 'creator-ulid', {
      scope: 'brand',
      type: 'soft',
      reason: 'off-brand',
      brand_id: 'brand-ulid',
    })
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/agencies/agency-ulid/creators/creator-ulid/blacklist',
      {
        scope: 'brand',
        type: 'soft',
        reason: 'off-brand',
        brand_id: 'brand-ulid',
      },
    )
  })

  it('DELETEs with the scope body to lift a blacklist', () => {
    void rosterApi.unblacklist('agency-ulid', 'creator-ulid', { scope: 'agency' })
    expect(mockHttp.delete).toHaveBeenCalledWith(
      '/agencies/agency-ulid/creators/creator-ulid/blacklist',
      { scope: 'agency' },
    )
  })
})
