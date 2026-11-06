/**
 * AH-119 Stage B — drives the agency inbox "Messages" result group.
 *
 * The term is debounced (300 ms) and searched only from 2 characters on; one character shows the
 * `minChars` hint, an empty term shows nothing. A stale response (the term or the agency changed
 * while it was in flight) is dropped. "Load more" follows the opaque cursor.
 *
 * States: `idle` (empty term or search off), `minChars`, `loading`, `results`, `empty`, `error`.
 */

import type { MessageSearchResult } from '@catalyst/api-client'
import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue'

import { MESSAGE_SEARCH_MIN_CHARS, messageSearchApi } from '../api/messageSearch.api'

export const MESSAGE_SEARCH_DEBOUNCE_MS = 300

export type MessageSearchState = 'idle' | 'minChars' | 'loading' | 'results' | 'empty' | 'error'

export function useMessageSearch(
  agencyId: Ref<string | null>,
  term: Ref<string>,
  enabled: Ref<boolean>,
) {
  const results = ref<MessageSearchResult[]>([])
  const nextCursor = ref<string | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const failed = ref(false)

  let timer: ReturnType<typeof setTimeout> | null = null
  // Bumped on every new search; a response carrying an older number is ignored.
  let generation = 0

  const trimmed = computed(() => term.value.trim())

  const state = computed<MessageSearchState>(() => {
    if (!enabled.value || trimmed.value === '') {
      return 'idle'
    }
    if (trimmed.value.length < MESSAGE_SEARCH_MIN_CHARS) {
      return 'minChars'
    }
    if (failed.value) {
      return 'error'
    }
    if (loading.value) {
      return 'loading'
    }
    return results.value.length > 0 ? 'results' : 'empty'
  })

  const hasMore = computed(() => nextCursor.value !== null)

  function clearTimer(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function reset(): void {
    generation++
    clearTimer()
    results.value = []
    nextCursor.value = null
    loading.value = false
    loadingMore.value = false
    failed.value = false
  }

  async function run(q: string, agency: string): Promise<void> {
    const mine = ++generation
    loading.value = true
    failed.value = false
    try {
      const res = await messageSearchApi.search(agency, q)
      if (mine !== generation) {
        return
      }
      results.value = [...res.data]
      nextCursor.value = res.meta.next_cursor
    } catch {
      if (mine !== generation) {
        return
      }
      results.value = []
      nextCursor.value = null
      failed.value = true
    } finally {
      if (mine === generation) {
        loading.value = false
      }
    }
  }

  async function loadMore(): Promise<void> {
    const agency = agencyId.value
    const cursor = nextCursor.value
    if (agency === null || cursor === null || loadingMore.value || loading.value) {
      return
    }
    const mine = generation
    loadingMore.value = true
    try {
      const res = await messageSearchApi.search(agency, trimmed.value, cursor)
      if (mine !== generation) {
        return
      }
      const known = new Set(results.value.map((r) => r.attributes.message_ulid))
      results.value = [
        ...results.value,
        ...res.data.filter((r) => !known.has(r.attributes.message_ulid)),
      ]
      nextCursor.value = res.meta.next_cursor
    } catch {
      // Transient: the cursor stays so the user can ask again.
    } finally {
      loadingMore.value = false
    }
  }

  watch(
    [trimmed, enabled, agencyId],
    ([q, on, agency]) => {
      reset()
      if (!on || agency === null || q.length < MESSAGE_SEARCH_MIN_CHARS) {
        return
      }
      // Mark loading at once so the result group never flashes "no messages" during the debounce.
      loading.value = true
      timer = setTimeout(() => {
        timer = null
        void run(q, agency)
      }, MESSAGE_SEARCH_DEBOUNCE_MS)
    },
    { immediate: true },
  )

  onBeforeUnmount(() => {
    generation++
    clearTimer()
  })

  return { results, state, hasMore, loading, loadingMore, loadMore }
}
