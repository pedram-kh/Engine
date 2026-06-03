/**
 * Unit tests for the creator discovery API wrapper (Sprint 6.6a).
 *
 * Pins the `list()` query-builder (only set params are sent; empty strings are
 * dropped) and the `show()` URL shape. The HTTP singleton is mocked so no
 * transport runs.
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
import { discoveryApi } from './discovery.api'

const mockHttp = vi.mocked(http)

function lastGetUrl(): string {
  return mockHttp.get.mock.calls.at(-1)![0] as string
}

function lastListQuery(): URLSearchParams {
  return new URLSearchParams(lastGetUrl().split('?')[1] ?? '')
}

describe('discoveryApi.list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({
      data: [],
      meta: { total: 0, page: 1, per_page: 24, last_page: 1 },
    })
  })

  it('omits the query string entirely with no params', () => {
    void discoveryApi.list('agency-ulid')
    expect(mockHttp.get).toHaveBeenCalledWith('/agencies/agency-ulid/creators/discover')
  })

  it('threads the filters + search + paging that are set', () => {
    void discoveryApi.list('agency-ulid', {
      country: 'PT',
      language: 'it',
      category: 'fitness',
      q: 'ada',
      page: 2,
      per_page: 24,
    })
    const query = lastListQuery()
    expect(query.get('country')).toBe('PT')
    expect(query.get('language')).toBe('it')
    expect(query.get('category')).toBe('fitness')
    expect(query.get('q')).toBe('ada')
    expect(query.get('page')).toBe('2')
    expect(query.get('per_page')).toBe('24')
  })

  it('drops empty-string filter values', () => {
    void discoveryApi.list('agency-ulid', { country: '', q: '' })
    const query = lastListQuery()
    expect(query.has('country')).toBe(false)
    expect(query.has('q')).toBe(false)
  })
})

describe('discoveryApi.show', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({ data: {} })
  })

  it('targets the public-profile URL with the creator ULID', () => {
    void discoveryApi.show('agency-ulid', 'creator-ulid')
    expect(mockHttp.get).toHaveBeenCalledWith(
      '/agencies/agency-ulid/creators/discover/creator-ulid',
    )
  })
})
