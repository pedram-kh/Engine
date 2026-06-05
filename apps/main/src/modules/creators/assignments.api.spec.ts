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

  // ── Sprint 9 Chunk 1 — submission surface ──────────────────────────────────

  it('GETs the assignment detail path (show)', () => {
    void creatorAssignmentsApi.show('01ASSIGNMENT')
    expect(mockHttp.get).toHaveBeenCalledWith('/creators/me/assignments/01ASSIGNMENT')
  })

  it('POSTs a draft to the drafts path', () => {
    const payload = { caption: 'hi', hashtags: ['#ad'], mentions: null, media: [] }
    void creatorAssignmentsApi.submitDraft('01ASSIGNMENT', payload)
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/creators/me/assignments/01ASSIGNMENT/drafts',
      payload,
    )
  })

  it('POSTs to the draft media init + complete paths', () => {
    void creatorAssignmentsApi.initDraftMedia('01ASSIGNMENT', {
      mime_type: 'video/mp4',
      declared_bytes: 100,
    })
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/creators/me/assignments/01ASSIGNMENT/drafts/media/init',
      {
        mime_type: 'video/mp4',
        declared_bytes: 100,
      },
    )

    void creatorAssignmentsApi.completeDraftMedia('01ASSIGNMENT', {
      upload_id: 'creators/x/drafts/y.mp4',
    })
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/creators/me/assignments/01ASSIGNMENT/drafts/media/complete',
      {
        upload_id: 'creators/x/drafts/y.mp4',
      },
    )
  })

  it('POSTs posted content to the posted-content path', () => {
    void creatorAssignmentsApi.submitPostedContent('01ASSIGNMENT', {
      platform: 'instagram',
      post_url: 'https://instagram.com/p/x',
    })
    expect(mockHttp.post).toHaveBeenCalledWith(
      '/creators/me/assignments/01ASSIGNMENT/posted-content',
      {
        platform: 'instagram',
        post_url: 'https://instagram.com/p/x',
      },
    )
  })
})
