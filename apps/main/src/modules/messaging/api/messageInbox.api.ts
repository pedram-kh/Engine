/**
 * AH-119 — typed wrappers for the unified agency inbox and the creator-owner reassign.
 *
 *   - GET   /agencies/{agency}/message-inbox            (404 while `messages_v2_inbox_enabled` is off)
 *   - PATCH /agencies/{agency}/creators/{creator}/owner  (agency admin / manager)
 */

import type { MessageInboxParams, MessageInboxResponse } from '@catalyst/api-client'

import { http } from '@/core/api'

/** Builds the query string; `brand[]` / `campaign[]` repeat, empty selections are left out. */
export function messageInboxQuery(params: MessageInboxParams): string {
  const query = new URLSearchParams()
  for (const id of params.brand ?? []) {
    query.append('brand[]', id)
  }
  for (const id of params.campaign ?? []) {
    query.append('campaign[]', id)
  }
  if (params.unread === true) {
    query.set('unread', '1')
  }
  if (params.owner !== undefined) {
    query.set('owner', params.owner)
  }
  if (params.cursor !== undefined && params.cursor !== null && params.cursor !== '') {
    query.set('cursor', params.cursor)
  }
  if (params.perPage !== undefined) {
    query.set('per_page', String(params.perPage))
  }
  const qs = query.toString()
  return qs === '' ? '' : `?${qs}`
}

export const messageInboxApi = {
  list(agencyId: string, params: MessageInboxParams = {}): Promise<MessageInboxResponse> {
    return http.get<MessageInboxResponse>(
      `/agencies/${agencyId}/message-inbox${messageInboxQuery(params)}`,
    )
  },

  /** Reassign who owns a creator relationship. `userUlid` must be an active member. */
  reassignOwner(agencyId: string, creatorUlid: string, userUlid: string): Promise<unknown> {
    return http.patch(`/agencies/${agencyId}/creators/${creatorUlid}/owner`, {
      owner_user_id: userUlid,
    })
  },
}
