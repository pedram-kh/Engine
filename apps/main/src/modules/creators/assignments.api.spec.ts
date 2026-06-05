/**
 * Unit tests for the creator-side campaign-assignment wrapper (Sprint 8 Chunk
 * 2, D-9). Pins the creator-self path + verb of `list`/`accept`/`decline`/
 * `counter`. The HTTP singleton is mocked so no transport runs.
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
import { creatorAssignmentsApi } from './assignments.api'

const mockHttp = vi.mocked(http)

describe('creatorAssignmentsApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({ data: [] })
    mockHttp.post.mockResolvedValue({
      data: { id: 'a', type: 'campaign_assignment', attributes: { status: 'accepted' } },
      meta: { code: 'assignment.accepted' },
    })
  })

  it('GETs the creator-self assignments path (no id, no query)', () => {
    void creatorAssignmentsApi.list()
    expect(mockHttp.get).toHaveBeenCalledWith('/creators/me/assignments')
  })

  it('POSTs the assignment ULID to the accept path', () => {
    void creatorAssignmentsApi.accept('01ASSIGNMENT')
    expect(mockHttp.post).toHaveBeenCalledWith('/creators/me/assignments/01ASSIGNMENT/accept')
  })

  it('POSTs the assignment ULID to the decline path', () => {
    void creatorAssignmentsApi.decline('01ASSIGNMENT')
    expect(mockHttp.post).toHaveBeenCalledWith('/creators/me/assignments/01ASSIGNMENT/decline')
  })

  it('POSTs the countered fee to the counter path', () => {
    void creatorAssignmentsApi.counter('01ASSIGNMENT', {
      countered_fee_minor_units: 750000,
      countered_fee_currency: 'EUR',
    })
    expect(mockHttp.post).toHaveBeenCalledWith('/creators/me/assignments/01ASSIGNMENT/counter', {
      countered_fee_minor_units: 750000,
      countered_fee_currency: 'EUR',
    })
  })
})
