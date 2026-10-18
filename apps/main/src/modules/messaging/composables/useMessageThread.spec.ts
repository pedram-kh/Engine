import type { MessageResource } from '@catalyst/api-client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import type { ChatTransport } from '../api/messaging.api'
import { NOTIFICATIONS_REFRESH_EVENT } from '@/modules/notifications/composables/notificationsRefresh'
import { THREAD_POLL_INTERVAL_MS, useMessageThread } from './useMessageThread'

function msg(id: string, attrs: Partial<MessageResource['attributes']> = {}): MessageResource {
  return {
    id,
    type: 'message',
    attributes: {
      kind: 'text',
      sender_role: 'agency_user',
      body: 'hello',
      attachments: [],
      system_event_key: null,
      is_own: false,
      sender: { name: 'Agency' },
      created_at: '2026-01-01T00:00:00+00:00',
      ...attrs,
    },
  }
}

function feed(
  messages: MessageResource[],
  opts: { unread?: number; blocked?: boolean; hasMore?: boolean } = {},
) {
  return {
    data: messages,
    meta: {
      thread: {
        id: 'thread-ulid',
        assignment_id: 'assignment-ulid',
        last_message_at: null,
        unread_count: opts.unread ?? 0,
        human_send_blocked: opts.blocked ?? false,
      },
      has_more: opts.hasMore ?? false,
    },
  }
}

function makeTransport(overrides: Partial<ChatTransport> = {}): ChatTransport {
  return {
    list: vi.fn().mockResolvedValue(feed([msg('m1')])),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 0, unread_count: 0 } }),
    attachmentInit: vi.fn(),
    attachmentComplete: vi.fn(),
    ...overrides,
  }
}

describe('useMessageThread', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('loads on start, polls every 15s, and stops cleanly on cancel', async () => {
    const transport = makeTransport()
    const t = useMessageThread(ref(transport))

    await t.start()
    expect(transport.list).toHaveBeenCalledTimes(1)
    expect(t.messages.value).toHaveLength(1)

    await vi.advanceTimersByTimeAsync(THREAD_POLL_INTERVAL_MS)
    expect(transport.list).toHaveBeenCalledTimes(2)

    // The cleanup contract: after stop(), no further polls fire (the
    // onBeforeUnmount discipline, refs-only — D-12).
    t.stop()
    await vi.advanceTimersByTimeAsync(THREAD_POLL_INTERVAL_MS * 3)
    expect(transport.list).toHaveBeenCalledTimes(2)
  })

  it('marks the thread read on poll only when there is something unread', async () => {
    const transport = makeTransport({
      list: vi.fn().mockResolvedValue(feed([msg('m1')], { unread: 3 })),
    })
    const t = useMessageThread(ref(transport))

    await t.start()
    expect(transport.markRead).toHaveBeenCalledTimes(1)
    expect(t.unreadCount.value).toBe(0)

    t.stop()
  })

  // AH-108 — the server clears the thread's message notifications inside /read, so
  // the Messages badge must re-read NOW, not on the next 45 s tick.
  it('asks the unread polls to refresh once /read has resolved, and only then', async () => {
    const order: string[] = []
    const onRefresh = (): void => {
      order.push('refresh')
    }
    document.addEventListener(NOTIFICATIONS_REFRESH_EVENT, onRefresh)
    try {
      const transport = makeTransport({
        list: vi.fn().mockResolvedValue(feed([msg('m1')], { unread: 2 })),
        markRead: vi.fn().mockImplementation(async () => {
          order.push('read')
          return { meta: { marked: 2, unread_count: 0 } }
        }),
      })
      const t = useMessageThread(ref(transport))

      await t.start()

      expect(order).toEqual(['read', 'refresh'])
      t.stop()
    } finally {
      document.removeEventListener(NOTIFICATIONS_REFRESH_EVENT, onRefresh)
    }
  })

  it('does not ask for a refresh when there was nothing to mark read', async () => {
    const onRefresh = vi.fn()
    document.addEventListener(NOTIFICATIONS_REFRESH_EVENT, onRefresh)
    try {
      const t = useMessageThread(ref(makeTransport()))
      await t.start()
      expect(onRefresh).not.toHaveBeenCalled()
      t.stop()
    } finally {
      document.removeEventListener(NOTIFICATIONS_REFRESH_EVENT, onRefresh)
    }
  })

  it('does not mark read when nothing is unread', async () => {
    const transport = makeTransport()
    const t = useMessageThread(ref(transport))

    await t.start()
    expect(transport.markRead).not.toHaveBeenCalled()

    t.stop()
  })

  it('appends only genuinely-new messages across polls (history preserved)', async () => {
    const list = vi
      .fn()
      .mockResolvedValueOnce(feed([msg('m1')]))
      .mockResolvedValueOnce(feed([msg('m1'), msg('m2')]))
    const transport = makeTransport({ list })
    const t = useMessageThread(ref(transport))

    await t.start()
    await vi.advanceTimersByTimeAsync(THREAD_POLL_INTERVAL_MS)

    expect(t.messages.value.map((m) => m.id)).toEqual(['m1', 'm2'])
    t.stop()
  })

  it('reflects the terminal-state human-send block from thread meta', async () => {
    const transport = makeTransport({
      list: vi.fn().mockResolvedValue(feed([msg('m1')], { blocked: true })),
    })
    const t = useMessageThread(ref(transport))

    await t.start()
    expect(t.humanSendBlocked.value).toBe(true)

    t.stop()
  })

  it('optimistically appends a sent message', async () => {
    const sent = msg('m9', { is_own: true, body: 'my reply' })
    const transport = makeTransport({ send: vi.fn().mockResolvedValue({ data: sent }) })
    const t = useMessageThread(ref(transport))

    await t.start()
    await t.sendMessage({ body: 'my reply' })

    expect(transport.send).toHaveBeenCalledWith({ body: 'my reply' })
    expect(t.messages.value.at(-1)?.id).toBe('m9')
    t.stop()
  })
})
