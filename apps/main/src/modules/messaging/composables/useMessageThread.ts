/**
 * Drives one chat thread: initial load, a ~15s steady poll (D-12, the
 * BulkInvitePage setTimeout-reschedule + onBeforeUnmount cleanup, refs-only, no
 * localStorage), "load earlier" history paging, optimistic send append, and
 * mark-read on poll.
 *
 * Surface-agnostic AND message-shape-agnostic: it is generic over the message /
 * thread-meta / send-payload types and consumes any transport matching
 * {@link GenericChatTransport}, so it backs BOTH the campaign `ChatTransport`
 * (default type params — campaign behavior is byte-for-byte unchanged) and the
 * AH-010 relationship transport (explicit type params). The poll is independent
 * of the notification bell's own 45s poll.
 */

import type { MessageResource, MessageThreadMeta, SendMessagePayload } from '@catalyst/api-client'
import { computed, getCurrentInstance, onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue'

export const THREAD_POLL_INTERVAL_MS = 15000

/** The minimal message shape the engine needs: an id + a created_at. */
interface ThreadMessageLike {
  id: string
  attributes: { created_at: string }
}

/** The minimal thread-meta shape: unread count + last-message stamp (+ optional campaign-only block flag). */
interface ThreadMetaLike {
  unread_count: number
  last_message_at: string | null
  human_send_blocked?: boolean
}

/** The minimal transport the engine drives (a structural subset of both surfaces' transports). */
export interface GenericChatTransport<
  TMessage extends ThreadMessageLike,
  TMeta extends ThreadMetaLike,
  TSend,
> {
  list(before?: string): Promise<{ data: TMessage[]; meta: { thread: TMeta; has_more: boolean } }>
  send(payload: TSend): Promise<{ data: TMessage }>
  markRead(): Promise<unknown>
}

export function useMessageThread<
  TMessage extends ThreadMessageLike = MessageResource,
  TMeta extends ThreadMetaLike = MessageThreadMeta,
  TSend = SendMessagePayload,
>(transport: Ref<GenericChatTransport<TMessage, TMeta, TSend> | null>) {
  const messages = ref<TMessage[]>([]) as Ref<TMessage[]>
  const threadMeta = ref<TMeta | null>(null) as Ref<TMeta | null>
  const hasMore = ref(false)
  const loading = ref(false)
  const loadingOlder = ref(false)
  const sending = ref(false)
  const loadError = ref(false)

  let cancelled = false
  let timer: ReturnType<typeof setTimeout> | null = null

  const humanSendBlocked = computed(() => threadMeta.value?.human_send_blocked ?? false)
  const unreadCount = computed(() => threadMeta.value?.unread_count ?? 0)

  function clearTimer(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  async function refresh(options: { markRead?: boolean } = {}): Promise<void> {
    const client = transport.value
    if (client === null) {
      return
    }

    loading.value = messages.value.length === 0
    try {
      const res = await client.list()
      if (cancelled) {
        return
      }

      // Merge by id so a "load earlier" history is not discarded by a poll and
      // the scroll position is preserved — append only genuinely-new messages.
      if (messages.value.length === 0) {
        messages.value = [...res.data]
      } else {
        const known = new Set(messages.value.map((m) => m.id))
        const fresh = res.data.filter((m) => !known.has(m.id))
        if (fresh.length > 0) {
          messages.value = [...messages.value, ...fresh]
        }
      }

      threadMeta.value = res.meta.thread
      hasMore.value = res.meta.has_more
      loadError.value = false

      if (options.markRead === true && (threadMeta.value?.unread_count ?? 0) > 0) {
        await client.markRead()
        if (!cancelled && threadMeta.value !== null) {
          threadMeta.value = { ...threadMeta.value, unread_count: 0 }
        }
      }
    } catch {
      // Transient failures keep the existing feed; only flag when we have none.
      if (messages.value.length === 0) {
        loadError.value = true
      }
    } finally {
      loading.value = false
    }
  }

  async function loadOlder(): Promise<void> {
    const client = transport.value
    if (client === null || !hasMore.value || loadingOlder.value) {
      return
    }
    const oldest = messages.value[0]
    if (oldest === undefined) {
      return
    }

    loadingOlder.value = true
    try {
      const res = await client.list(oldest.id)
      if (cancelled) {
        return
      }
      hasMore.value = res.meta.has_more
      const known = new Set(messages.value.map((m) => m.id))
      const older = res.data.filter((m) => !known.has(m.id))
      messages.value = [...older, ...messages.value]
    } catch {
      // Transient — leave hasMore so the user can retry.
    } finally {
      loadingOlder.value = false
    }
  }

  async function sendMessage(payload: TSend): Promise<TMessage> {
    const client = transport.value
    if (client === null) {
      throw new Error('Chat transport is not ready.')
    }

    sending.value = true
    try {
      const res = await client.send(payload)
      messages.value = [...messages.value, res.data]
      if (threadMeta.value !== null) {
        threadMeta.value = {
          ...threadMeta.value,
          last_message_at: res.data.attributes.created_at,
        }
      }
      return res.data
    } finally {
      sending.value = false
    }
  }

  async function tick(): Promise<void> {
    if (cancelled) {
      return
    }
    await refresh({ markRead: true })
    if (cancelled) {
      return
    }
    timer = setTimeout(() => {
      void tick()
    }, THREAD_POLL_INTERVAL_MS)
  }

  async function start(): Promise<void> {
    cancelled = false
    clearTimer()
    await refresh({ markRead: true })
    if (cancelled) {
      return
    }
    timer = setTimeout(() => {
      void tick()
    }, THREAD_POLL_INTERVAL_MS)
  }

  function stop(): void {
    cancelled = true
    clearTimer()
  }

  // Re-bind when the transport identity changes (e.g. the agency operator opens
  // a different thread from the roll-up).
  watch(transport, () => {
    messages.value = []
    threadMeta.value = null
    hasMore.value = false
    void start()
  })

  if (getCurrentInstance() !== null) {
    onMounted(() => {
      void start()
    })
    onBeforeUnmount(stop)
  }

  return {
    messages,
    threadMeta,
    hasMore,
    loading,
    loadingOlder,
    sending,
    loadError,
    humanSendBlocked,
    unreadCount,
    refresh,
    loadOlder,
    sendMessage,
    start,
    stop,
  }
}
