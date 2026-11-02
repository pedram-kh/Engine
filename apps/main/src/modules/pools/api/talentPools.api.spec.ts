/**
 * Unit tests for the talent-pools API wrapper's members query (AH-116).
 *
 * Pins the `members()` query-builder: only the params that are set are sent, an empty string is
 * "no filter", and `country` / `language` reach the real query string (the AH-096 class of bug:
 * a typed param the page sets and the wrapper drops). The HTTP singleton is mocked so no
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
import { talentPoolsApi } from './talentPools.api'

const mockHttp = vi.mocked(http)

function lastGetUrl(): string {
  return mockHttp.get.mock.calls.at(-1)![0] as string
}

function lastQuery(): URLSearchParams {
  return new URLSearchParams(lastGetUrl().split('?')[1] ?? '')
}

describe('talentPoolsApi.members', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({ data: [], meta: {}, links: {} })
  })

  it('omits the query string entirely with no params', () => {
    void talentPoolsApi.members('agency-ulid', 'pool-ulid')
    expect(mockHttp.get).toHaveBeenCalledWith(
      '/agencies/agency-ulid/talent-pools/pool-ulid/creators',
    )
  })

  it('threads paging as before', () => {
    void talentPoolsApi.members('agency-ulid', 'pool-ulid', { page: 2, per_page: 25 })
    expect(lastGetUrl()).toBe(
      '/agencies/agency-ulid/talent-pools/pool-ulid/creators?page=2&per_page=25',
    )
  })

  it('AH-116: threads country and language into the real query string', () => {
    void talentPoolsApi.members('agency-ulid', 'pool-ulid', {
      page: 1,
      per_page: 25,
      country: 'PT',
      language: 'it',
    })
    const query = lastQuery()
    expect(query.get('country')).toBe('PT')
    expect(query.get('language')).toBe('it')
    expect(query.get('page')).toBe('1')
    expect(query.get('per_page')).toBe('25')
  })

  it('AH-116: each filter is independent, and an empty string is no filter', () => {
    void talentPoolsApi.members('agency-ulid', 'pool-ulid', { country: 'PT' })
    expect(lastQuery().get('country')).toBe('PT')
    expect(lastQuery().has('language')).toBe(false)

    void talentPoolsApi.members('agency-ulid', 'pool-ulid', { country: '', language: '' })
    expect(mockHttp.get).toHaveBeenLastCalledWith(
      '/agencies/agency-ulid/talent-pools/pool-ulid/creators',
    )
  })
})
