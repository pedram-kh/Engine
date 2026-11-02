/**
 * `useMessageableCreatorIds` — which creators this agency can message right now (AH-116, item 7).
 *
 * The Applications "Message" action opens the existing relationship thread, and the thread's
 * first send is gated: the creator must be on the roster, not blacklisted, approved and not
 * leaving. That gate is `MessageableContactsFinder`, behind `GET …/messageable-creators`. The
 * application row carries none of it, so rather than re-deriving the rule on the client (and
 * drifting from it) this reads the list the contact picker already uses and asks "is this
 * creator in it". A Message shown for a creator in the set cannot 403 on the first send.
 *
 * Paged, because rosters are large: 100 a page (the endpoint's maximum), at most
 * {@link MAX_PAGES} pages, so 1000 creators. It FAILS CLOSED, on purpose:
 *   - a load error empties the set (no Message anywhere, no error shown: the action is an extra);
 *   - a roster past the cap leaves the creators beyond it out of the set, so they get no Message
 *     rather than a Message nobody checked.
 *
 * Lazy and once: nothing is read until {@link MessageableCreatorIds.ensureLoaded} is called (the
 * caller does so only when a pending row and an ability to act both exist), and a second call
 * returns the same read. The follow-up that would replace all of this is a `can_message` flag
 * on the application row (logged, not built).
 */

import { relationshipMessagingApi } from '@/modules/messaging/api/relationshipMessaging.api'
import { shallowRef, type ShallowRef } from 'vue'

/** The endpoint caps `per_page` at 100. */
const PAGE_SIZE = 100
/** 10 pages, so 1000 creators: past that, the creator is not in the set. */
export const MAX_PAGES = 10

export interface MessageableCreatorIds {
  /** The creator ULIDs loaded so far (empty until loaded, and after a failed load). */
  ids: ShallowRef<ReadonlySet<string>>
  /** Starts the read on the first call; later calls return the same promise. Never rejects. */
  ensureLoaded: () => Promise<void>
  /** True only for a creator that was loaded into the set. */
  canMessage: (creatorUlid: string | null | undefined) => boolean
}

export function useMessageableCreatorIds(agencyId: () => string): MessageableCreatorIds {
  const ids = shallowRef<ReadonlySet<string>>(new Set())
  let started: Promise<void> | null = null

  async function read(): Promise<void> {
    const loaded = new Set<string>()
    try {
      const agency = agencyId()
      let page = 1
      let lastPage = 1
      do {
        const res = await relationshipMessagingApi.messageableCreators(agency, {
          page,
          perPage: PAGE_SIZE,
        })
        for (const row of res.data) loaded.add(row.id)
        lastPage = res.meta.last_page
        page += 1
      } while (page <= lastPage && page <= MAX_PAGES)
    } catch {
      // Fail closed: a partial read is dropped too, so a failure never leaves a half-checked set.
      ids.value = new Set()
      return
    }
    ids.value = loaded
  }

  function ensureLoaded(): Promise<void> {
    started ??= read()
    return started
  }

  function canMessage(creatorUlid: string | null | undefined): boolean {
    return creatorUlid !== null && creatorUlid !== undefined && ids.value.has(creatorUlid)
  }

  return { ids, ensureLoaded, canMessage }
}
