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
  inbox(cursor?: string | null): Promise<CreatorInboxResponse> {
    const query = new URLSearchParams()
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
