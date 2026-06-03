/**
 * Unit tests for the creator-side connection-request inbox wrapper
 * (Sprint 6.6c, D-d4). Pins the path + verb of `list`/`accept`/`decline`.
 * The HTTP singleton is mocked so no transport runs.
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
import { connectionRequestsApi } from './connectionRequests.api'

const mockHttp = vi.mocked(http)

describe('connectionRequestsApi.list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({ data: [] })
  })

  it('GETs the creator-self inbox path (no id, no query)', () => {
    void connectionRequestsApi.list()
    expect(mockHttp.get).toHaveBeenCalledWith('/creators/me/connection-requests')
  })
})

describe('connectionRequestsApi.accept', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.post.mockResolvedValue({
      data: {
        id: 'rel',
        type: 'connection_request',
        attributes: { relationship_status: 'roster' },
      },
      meta: { code: 'connection.accepted' },
    })
  })

  it('POSTs the row ULID to the accept path', () => {
    void connectionRequestsApi.accept('01RELATIONULID')
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/creators/me/connection-requests/01RELATIONULID/accept',
    )
  })
})

describe('connectionRequestsApi.decline', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.post.mockResolvedValue({
      data: {
        id: 'rel',
        type: 'connection_request',
        attributes: { relationship_status: 'declined' },
      },
      meta: { code: 'connection.declined' },
    })
  })

  it('POSTs the row ULID to the decline path', () => {
    void connectionRequestsApi.decline('01RELATIONULID')
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/creators/me/connection-requests/01RELATIONULID/decline',
    )
  })
})
