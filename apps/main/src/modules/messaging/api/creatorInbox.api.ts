/**
 * AH-115 L4 — the creator unified inbox (`GET /creators/me/message-inbox`) and message search
 * (`GET /creators/me/message-search?q=&cursor=`). Both answer 404 while
 * `messages_v2_creator_inbox_enabled` is off; the page only calls them when the `/me` switch
 * `features.messages_v2_creator_inbox` is on.
 */

import type { CreatorInboxResponse, CreatorInboxSearchResponse } from '@catalyst/api-client'

import { http } from '@/core/api'

import { messageSearchQuery } from './messageSearch.api'

export const creatorInboxApi = {
  /**
   * `filters` are the creator's two inbox filters (AH-115 walk): `unread` (threads with unread only) and
   * `direct` (Direct threads only). Each is sent only when on, so a clean inbox has a clean request.
   */
  inbox(
    cursor?: string | null,
    filters?: { unread?: boolean; direct?: boolean },
  ): Promise<CreatorInboxResponse> {
    const query = new URLSearchParams()
    if (filters?.unread === true) {
      query.set('unread', '1')
    }
    if (filters?.direct === true) {
      query.set('direct', '1')
    }
    if (cursor !== undefined && cursor !== null && cursor !== '') {
      query.set('cursor', cursor)
    }
    const qs = query.toString()
    return http.get<CreatorInboxResponse>(`/creators/me/message-inbox${qs === '' ? '' : `?${qs}`}`)
  },

  search(q: string, cursor?: string | null): Promise<CreatorInboxSearchResponse> {
    return http.get<CreatorInboxSearchResponse>(
      `/creators/me/message-search${messageSearchQuery(q, cursor)}`,
    )
  },
}
