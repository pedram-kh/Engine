/**
 * AH-119 Stage B — `GET /agencies/{agency}/message-search?q=&cursor=`: full-text search over the
 * message bodies of one agency (any active member). 404 while `messages_v2_edit_search_enabled` is
 * off; `q` is 2 to 200 characters (422 below 2, so the caller enforces the minimum first).
 */

import type { MessageSearchResponse } from '@catalyst/api-client'

import { http } from '@/core/api'

export const MESSAGE_SEARCH_MIN_CHARS = 2
export const MESSAGE_SEARCH_MAX_CHARS = 200

/** Builds the query string: `q` always, `cursor` only for a following page. */
export function messageSearchQuery(q: string, cursor?: string | null): string {
  const query = new URLSearchParams()
  query.set('q', q.slice(0, MESSAGE_SEARCH_MAX_CHARS))
  if (cursor !== undefined && cursor !== null && cursor !== '') {
    query.set('cursor', cursor)
  }
  return `?${query.toString()}`
}

export const messageSearchApi = {
  search(agencyId: string, q: string, cursor?: string | null): Promise<MessageSearchResponse> {
    return http.get<MessageSearchResponse>(
      `/agencies/${agencyId}/message-search${messageSearchQuery(q, cursor)}`,
    )
  },
}
