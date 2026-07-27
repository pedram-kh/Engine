/**
 * Unit tests for the creator jobs-board wrapper (AH-056, §5.13).
 *
 * Pins the creator-self path + verb of `list` / `show` / `apply`, and that the
 * apply body carries the note. The HTTP singleton is mocked so no transport
 * runs — the point is the URL, which is the one thing a page cannot check for
 * itself.
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
import { creatorJobsApi } from './jobs.api'

const mockHttp = vi.mocked(http)

describe('creatorJobsApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({ data: [], meta: { total: 0, page: 1, per_page: 12 } })
    mockHttp.post.mockResolvedValue({
      data: { id: 'a', type: 'campaign_application', attributes: { status: 'pending' } },
    })
  })

  it('GETs the creator-self jobs path with no query when unpaginated', () => {
    void creatorJobsApi.list()
    expect(mockHttp.get).toHaveBeenCalledWith('/creators/me/jobs')
  })

  it('serialises page + per_page onto the query string', () => {
    void creatorJobsApi.list({ page: 3, perPage: 24 })
    expect(mockHttp.get).toHaveBeenCalledWith('/creators/me/jobs?page=3&per_page=24')
  })

  it('omits an absent pagination param rather than sending undefined', () => {
    void creatorJobsApi.list({ page: 2 })
    expect(mockHttp.get).toHaveBeenCalledWith('/creators/me/jobs?page=2')
  })

  it('GETs one job by ULID', () => {
    void creatorJobsApi.show('01JOB')
    expect(mockHttp.get).toHaveBeenCalledWith('/creators/me/jobs/01JOB')
  })

  it('POSTs to the apply path with an empty body for a one-tap apply', () => {
    void creatorJobsApi.apply('01JOB')
    expect(mockHttp.post).toHaveBeenCalledWith('/creators/me/jobs/01JOB/apply', {})
  })

  it('carries the optional note in the apply body', () => {
    void creatorJobsApi.apply('01JOB', { note: 'I shoot food content weekly.' })
    expect(mockHttp.post).toHaveBeenCalledWith('/creators/me/jobs/01JOB/apply', {
      note: 'I shoot food content weekly.',
    })
  })
})
