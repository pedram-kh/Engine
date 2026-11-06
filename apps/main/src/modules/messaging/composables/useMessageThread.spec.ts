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
      system_event_data: null,
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

// AH-119 Stage B — optimistic edit and delete, rollback, and the poll picking up the other side's
// edit or delete.
describe('useMessageThread — edit and delete (AH-119 Stage B)', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  const own = (id: string, attrs: Partial<MessageResource['attributes']> = {}) =>
    msg(id, { is_own: true, body: 'first draft', ...attrs })

  function deferred<T>() {
    let resolve!: (v: T) => void
    let reject!: (e: unknown) => void
    const promise = new Promise<T>((res, rej) => {
      resolve = res
      reject = rej
    })
    return { promise, resolve, reject }
  }

  it('editMessage shows the new text at once, then adopts the server message', async () => {
    const gate = deferred<{ data: MessageResource }>()
    const transport = makeTransport({
      list: vi.fn().mockResolvedValue(feed([own('m1')])),
      edit: vi.fn().mockReturnValue(gate.promise),
    })
    const t = useMessageThread(ref(transport))
    await t.start()

    const pending = t.editMessage('m1', 'second draft')
    // Optimistic: visible before the server answers, flagged edited.
    expect(t.messages.value[0]?.attributes.body).toBe('second draft')
    expect(t.messages.value[0]?.attributes.edited_at).toBeTruthy()
    expect(transport.edit).toHaveBeenCalledWith('m1', { body: 'second draft' })

    gate.resolve({
      data: own('m1', { body: 'second draft', edited_at: '2026-01-01T00:05:00+00:00' }),
    })
    await pending
    expect(t.messages.value[0]?.attributes.edited_at).toBe('2026-01-01T00:05:00+00:00')
    t.stop()
  })

  it('editMessage rolls back to the original message when the server refuses, and rethrows', async () => {
    const boom = new Error('edit_window_closed')
    const transport = makeTransport({
      list: vi.fn().mockResolvedValue(feed([own('m1')])),
      edit: vi.fn().mockRejectedValue(boom),
    })
    const t = useMessageThread(ref(transport))
    await t.start()
    const before = t.messages.value[0]

    await expect(t.editMessage('m1', 'second draft')).rejects.toBe(boom)

    expect(t.messages.value[0]).toBe(before)
    expect(t.messages.value[0]?.attributes.body).toBe('first draft')
    expect(t.messages.value[0]?.attributes.edited_at ?? null).toBeNull()
    t.stop()
  })

  it('deleteMessage masks the message at once (no body, no attachments) and adopts the server copy', async () => {
    const gate = deferred<{ data: MessageResource }>()
    const attachment = {
      s3_path: 'p',
      mime_type: 'image/png',
      name: 'a.png',
      size_bytes: 1,
      view_url: 'https://x/a.png',
    }
    const transport = makeTransport({
      list: vi.fn().mockResolvedValue(feed([own('m1', { attachments: [attachment] })])),
      remove: vi.fn().mockReturnValue(gate.promise),
    })
    const t = useMessageThread(ref(transport))
    await t.start()

    const pending = t.deleteMessage('m1')
    expect(t.messages.value[0]?.attributes.is_deleted).toBe(true)
    expect(t.messages.value[0]?.attributes.body).toBeNull()
    expect(t.messages.value[0]?.attributes.attachments).toEqual([])
    expect(transport.remove).toHaveBeenCalledWith('m1')

    gate.resolve({ data: own('m1', { body: null, attachments: [], is_deleted: true }) })
    await pending
    expect(t.messages.value[0]?.attributes.is_deleted).toBe(true)
    t.stop()
  })

  it('deleteMessage rolls back (body and attachments restored) when the server refuses', async () => {
    const attachment = {
      s3_path: 'p',
      mime_type: 'image/png',
      name: 'a.png',
      size_bytes: 1,
      view_url: 'https://x/a.png',
    }
    const transport = makeTransport({
      list: vi.fn().mockResolvedValue(feed([own('m1', { attachments: [attachment] })])),
      remove: vi.fn().mockRejectedValue(new Error('nope')),
    })
    const t = useMessageThread(ref(transport))
    await t.start()

    await expect(t.deleteMessage('m1')).rejects.toThrow('nope')

    expect(t.messages.value[0]?.attributes.is_deleted ?? false).toBe(false)
    expect(t.messages.value[0]?.attributes.body).toBe('first draft')
    expect(t.messages.value[0]?.attributes.attachments).toHaveLength(1)
    t.stop()
  })

  it('refuses when the transport has no edit / remove (a surface without Stage B)', async () => {
    const t = useMessageThread(ref(makeTransport()))
    await t.start()
    await expect(t.editMessage('m1', 'x')).rejects.toThrow()
    await expect(t.deleteMessage('m1')).rejects.toThrow()
    t.stop()
  })

  it('the poll merges edited_at and is_deleted of messages already held', async () => {
    const list = vi
      .fn()
      .mockResolvedValueOnce(feed([msg('m1', { body: 'hi' }), msg('m2', { body: 'yo' })]))
      .mockResolvedValue(
        feed([
          msg('m1', { body: 'hi there', edited_at: '2026-01-01T00:03:00+00:00' }),
          msg('m2', { body: null, attachments: [], is_deleted: true }),
        ]),
      )
    const t = useMessageThread(ref(makeTransport({ list })))
    await t.start()
    expect(t.messages.value[0]?.attributes.body).toBe('hi')

    await vi.advanceTimersByTimeAsync(THREAD_POLL_INTERVAL_MS)

    expect(t.messages.value[0]?.attributes.body).toBe('hi there')
    expect(t.messages.value[0]?.attributes.edited_at).toBe('2026-01-01T00:03:00+00:00')
    expect(t.messages.value[1]?.attributes.is_deleted).toBe(true)
    expect(t.messages.value[1]?.attributes.body).toBeNull()
    t.stop()
  })

  it('a poll that lands while an edit is in flight does not undo the optimistic text', async () => {
    const gate = deferred<{ data: MessageResource }>()
    const list = vi
      .fn()
      .mockResolvedValueOnce(feed([own('m1')]))
      .mockResolvedValue(feed([own('m1', { edited_at: '2026-01-01T00:01:00+00:00' })]))
    const transport = makeTransport({ list, edit: vi.fn().mockReturnValue(gate.promise) })
    const t = useMessageThread(ref(transport))
    await t.start()

    const pending = t.editMessage('m1', 'second draft')
    await vi.advanceTimersByTimeAsync(THREAD_POLL_INTERVAL_MS)
    expect(t.messages.value[0]?.attributes.body).toBe('second draft')

    gate.resolve({
      data: own('m1', { body: 'second draft', edited_at: '2026-01-01T00:02:00+00:00' }),
    })
    await pending
    t.stop()
  })

  it('an unchanged message in the poll keeps its object identity (no needless re-render)', async () => {
    const list = vi.fn().mockResolvedValue(feed([msg('m1')]))
    const t = useMessageThread(ref(makeTransport({ list })))
    await t.start()
    const first = t.messages.value[0]

    await vi.advanceTimersByTimeAsync(THREAD_POLL_INTERVAL_MS)

    expect(t.messages.value[0]).toBe(first)
    t.stop()
  })
})
