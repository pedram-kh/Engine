/**
 * AH-119 — drives the unified agency inbox: the first page for the current filters, cursor
 * "load more", a steady 45 s poll that refreshes the head of the list and the facets, and
 * stale-response protection (a slow answer for old filters or an old agency never lands).
 *
 * The filters are owned by the caller (the page keeps them in the route query); this only reads
 * them. Changing a filter or the agency drops the list and loads page one again.
 */

import type { MessageInboxResponse, MessageInboxRow } from '@catalyst/api-client'
import { computed, onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue'

import { messageInboxApi } from '../api/messageInbox.api'

export const INBOX_POLL_INTERVAL_MS = 45000
export const INBOX_PAGE_SIZE = 30

export interface InboxFilters {
  brand: string[]
  campaign: string[]
  unread: boolean
  owner: 'me' | 'all'
}

export const EMPTY_FACETS: MessageInboxResponse['meta']['facets'] = { brands: [], campaigns: [] }

export function rowKey(row: MessageInboxRow): string {
  return `${row.type}:${row.id}`
}

export function useMessageInbox(agencyId: Ref<string | null>, filters: Ref<InboxFilters>) {
  const rows = ref<MessageInboxRow[]>([])
  const facets = ref<MessageInboxResponse['meta']['facets']>(EMPTY_FACETS)
  const unreadThreadsTotal = ref(0)
  const nextCursor = ref<string | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const loadError = ref(false)

  let generation = 0
  let resetGeneration = 0
  let timer: ReturnType<typeof setTimeout> | null = null
  let mounted = false

  const hasMore = computed(() => nextCursor.value !== null)

  function clearTimer(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function params(cursor: string | null = null) {
    const f = filters.value
    return {
      brand: f.brand,
      campaign: f.campaign,
      unread: f.unread,
      owner: f.owner,
      cursor,
      perPage: INBOX_PAGE_SIZE,
    }
  }

  /** Page one. `reset` drops the list first (a filter or agency change); a poll keeps loaded pages. */
  async function load(reset: boolean): Promise<void> {
    const agency = agencyId.value
    if (agency === null) {
      return
    }
    const mine = ++generation
    if (reset) {
      resetGeneration++
      rows.value = []
      nextCursor.value = null
      loading.value = true
    }
    try {
      const res = await messageInboxApi.list(agency, params())
      if (mine !== generation) {
        return
      }
      if (reset || rows.value.length === 0) {
        rows.value = [...res.data]
        nextCursor.value = res.meta.next_cursor
      } else {
        const head = new Set(res.data.map(rowKey))
        const tail = rows.value.filter((row) => !head.has(rowKey(row)))
        // Keep the older pages the user already loaded; the cursor keeps pointing past them.
        rows.value = [...res.data, ...tail]
      }
      facets.value = res.meta.facets
      unreadThreadsTotal.value = res.meta.unread_threads_total
      loadError.value = false
    } catch {
      if (mine === generation && rows.value.length === 0) {
        loadError.value = true
      }
    } finally {
      if (mine === generation) {
        loading.value = false
      }
    }
  }

  async function loadMore(): Promise<void> {
    const agency = agencyId.value
    const cursor = nextCursor.value
    if (agency === null || cursor === null || loadingMore.value) {
      return
    }
    const mine = resetGeneration
    loadingMore.value = true
    try {
      const res = await messageInboxApi.list(agency, params(cursor))
      if (mine !== resetGeneration) {
        return
      }
      const seen = new Set(rows.value.map(rowKey))
      rows.value = [...rows.value, ...res.data.filter((row) => !seen.has(rowKey(row)))]
      nextCursor.value = res.meta.next_cursor
    } catch {
      // The button stays; the next click retries.
    } finally {
      loadingMore.value = false
    }
  }

  function schedule(): void {
    clearTimer()
    timer = setTimeout(() => {
      void tick()
    }, INBOX_POLL_INTERVAL_MS)
  }

  async function tick(): Promise<void> {
    if (!mounted) {
      return
    }
    await load(false)
    if (mounted) {
      schedule()
    }
  }

  watch(
    [agencyId, () => JSON.stringify(filters.value)],
    () => {
      if (!mounted) {
        return
      }
      void load(true)
      schedule()
    },
    { flush: 'sync' },
  )

  onMounted(() => {
    mounted = true
    void load(true)
    schedule()
  })

  onBeforeUnmount(() => {
    mounted = false
    generation++
    clearTimer()
  })

  return {
    rows,
    facets,
    unreadThreadsTotal,
    hasMore,
    loading,
    loadingMore,
    loadError,
    loadMore,
    reload: () => load(true),
  }
}
