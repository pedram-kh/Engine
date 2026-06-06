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

import { notificationsApi } from './notifications.api'

describe('notificationsApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('list() hits the bare feed path with no query when no params given', () => {
    vi.mocked(http.get).mockResolvedValue({} as never)
    void notificationsApi.list()
    expect(http.get).toHaveBeenCalledWith('/me/notifications')
  })

  it('list() forwards page + per_page as snake_case query params', () => {
    vi.mocked(http.get).mockResolvedValue({} as never)
    void notificationsApi.list({ page: 3, perPage: 8 })
    expect(http.get).toHaveBeenCalledWith('/me/notifications?page=3&per_page=8')
  })

  it('unreadCount() hits the count-only endpoint', () => {
    vi.mocked(http.get).mockResolvedValue({} as never)
    void notificationsApi.unreadCount()
    expect(http.get).toHaveBeenCalledWith('/me/notifications/unread-count')
  })

  it('markRead() PATCHes the per-row read endpoint with the ulid', () => {
    vi.mocked(http.patch).mockResolvedValue({} as never)
    void notificationsApi.markRead('01ABCNOTIFICATIONULID')
    expect(http.patch).toHaveBeenCalledWith('/me/notifications/01ABCNOTIFICATIONULID/read')
  })

  it('readAll() POSTs the read-all endpoint', () => {
    vi.mocked(http.post).mockResolvedValue({} as never)
    void notificationsApi.readAll()
    expect(http.post).toHaveBeenCalledWith('/me/notifications/read-all')
  })

  it('takes no agency argument — the API is user-agnostic (no currentAgencyId)', () => {
    // Compile-time contract: list() accepts only NotificationListParams.
    // Runtime guard: the path never interpolates an agency segment.
    vi.mocked(http.get).mockResolvedValue({} as never)
    void notificationsApi.list({ page: 1 })
    expect(http.get).toHaveBeenCalledWith('/me/notifications?page=1')
    expect(vi.mocked(http.get).mock.calls[0]?.[0]).not.toContain('agencies')
  })
})
