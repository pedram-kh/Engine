/**
 * AH-103 — resolve a relationship-thread ULID (what the notification carries) to
 * the counterparty id the thread ROUTE needs, by looking it up in the recipient's
 * own inbox (Q4: no `agency_ulid` was added to the payload, so the SPA never
 * invents an id).
 *
 * The inbox endpoints are unpaginated, so "not in the response" really means
 * gone / not theirs. ANY failure (offline, 401, 5xx) is `null` — the resolver then
 * falls back to the list; a lookup must never strand a tap.
 */

import { relationshipMessagingApi } from '@/modules/messaging/api/relationshipMessaging.api'

export async function lookupThreadCounterparty(
  side: 'creator' | 'agency',
  threadUlid: string,
  agencyId: string | null,
): Promise<string | null> {
  try {
    if (side === 'creator') {
      const { data } = await relationshipMessagingApi.creatorInbox()

      return data.find((row) => row.id === threadUlid)?.attributes.agency.id ?? null
    }

    if (agencyId === null) {
      return null
    }

    const { data } = await relationshipMessagingApi.agencyInbox(agencyId)

    return data.find((row) => row.id === threadUlid)?.attributes.creator.id ?? null
  } catch {
    return null
  }
}
