/**
 * Unit tests for the availability API wrapper (Sprint 5 Chunk B).
 *
 * Pins the endpoint shapes: list builds the `from`/`to` query, create/
 * update/delete hit the creator-self path with the right verbs. The HTTP
 * singleton is mocked so no transport runs.
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
import { availabilityApi } from './availability.api'

const mockHttp = vi.mocked(http)

describe('availabilityApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('list() GETs the creator-self path with from/to query params', () => {
    mockHttp.get.mockResolvedValue({ data: [], meta: { window: { from: 'a', to: 'b' } } })
    void availabilityApi.list({ from: '2026-06-01T00:00:00Z', to: '2026-07-01T00:00:00Z' })
    expect(mockHttp.get).toHaveBeenCalledTimes(1)
    const url = mockHttp.get.mock.calls[0]![0] as string
    expect(url.startsWith('/creators/me/availability?')).toBe(true)
    const query = new URLSearchParams(url.split('?')[1])
    expect(query.get('from')).toBe('2026-06-01T00:00:00Z')
    expect(query.get('to')).toBe('2026-07-01T00:00:00Z')
  })

  it('create() POSTs to the collection path', () => {
    mockHttp.post.mockResolvedValue({ data: {} })
    const payload = {
      starts_at: '2026-06-15T13:00:00Z',
      ends_at: '2026-06-15T14:00:00Z',
      is_all_day: false,
      block_type: 'hard' as const,
      kind: 'vacation' as const,
      is_recurring: false,
    }
    void availabilityApi.create(payload)
    expect(mockHttp.post).toHaveBeenCalledWith('/creators/me/availability', payload)
  })

  it('update() PATCHes the block ULID path (series-level, full replace)', () => {
    mockHttp.patch.mockResolvedValue({ data: {} })
    void availabilityApi.update('01ABCBLOCKULID', {
      starts_at: 'x',
      ends_at: 'y',
      is_all_day: false,
      block_type: 'soft',
      kind: 'other',
      is_recurring: false,
    })
    expect(mockHttp.patch).toHaveBeenCalledWith(
      '/creators/me/availability/01ABCBLOCKULID',
      expect.objectContaining({ block_type: 'soft' }),
    )
  })

  it('delete() DELETEs the block ULID path', () => {
    mockHttp.delete.mockResolvedValue(undefined)
    void availabilityApi.delete('01ABCBLOCKULID')
    expect(mockHttp.delete).toHaveBeenCalledWith('/creators/me/availability/01ABCBLOCKULID')
  })
})
