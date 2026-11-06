/**
 * AH-119 — `assignmentThreadTransport`: the adapter that lets the agency thread view read an
 * assignment conversation. Pins the message mapping (file attachments, system lines, read state),
 * the thread meta (`can_send` is the inverse of `human_send_blocked`) and what send forwards.
 */

import type { MessageResource } from '@catalyst/api-client'
import { describe, expect, it, vi } from 'vitest'

import { assignmentThreadTransport } from './agencyThreadTransport'
import type { ChatTransport } from './messaging.api'

function message(id: string, attrs: Partial<MessageResource['attributes']> = {}): MessageResource {
  return {
    id,
    type: 'message',
    attributes: {
      kind: 'text',
      sender_role: 'creator',
      body: 'hi',
      attachments: [],
      is_own: false,
      sender: { name: 'Maya' },
      created_at: '2026-01-01T09:30:00+00:00',
      system_event_key: null,
      system_event_data: null,
      ...attrs,
    },
  } as unknown as MessageResource
}

function chat(overrides: Partial<ChatTransport> = {}): ChatTransport {
  return {
    list: vi.fn().mockResolvedValue({
      data: [],
      meta: {
        thread: {
          id: 'thread-1',
          last_message_at: '2026-01-02T00:00:00+00:00',
          unread_count: 3,
          human_send_blocked: false,
        },
        has_more: true,
      },
    }),
    send: vi.fn(),
    markRead: vi.fn().mockResolvedValue({ meta: { marked: 1, unread_count: 0 } }),
    attachmentInit: vi.fn().mockResolvedValue({ data: { upload_id: 'u1' } }),
    attachmentComplete: vi.fn().mockResolvedValue({ data: { ok: true } }),
    ...overrides,
  } as ChatTransport
}

describe('assignmentThreadTransport.list', () => {
  it('maps thread meta: can_send is the inverse of human_send_blocked, closed_reason is null', async () => {
    const open = await assignmentThreadTransport(chat()).list()
    expect(open.meta.thread).toEqual({
      id: 'thread-1',
      last_message_at: '2026-01-02T00:00:00+00:00',
      unread_count: 3,
      can_send: true,
      closed_reason: null,
    })

    const blocked = await assignmentThreadTransport(
      chat({
        list: vi.fn().mockResolvedValue({
          data: [],
          meta: {
            thread: { id: 't', last_message_at: null, unread_count: 0, human_send_blocked: true },
            has_more: false,
          },
        }),
      }),
    ).list()
    expect(blocked.meta.thread.can_send).toBe(false)
    expect(blocked.meta.thread.closed_reason).toBeNull()
  })

  it('keeps has_more', async () => {
    expect((await assignmentThreadTransport(chat()).list()).meta.has_more).toBe(true)
  })

  it('passes the before cursor through', async () => {
    const c = chat()
    await assignmentThreadTransport(c).list('m5')
    expect(c.list).toHaveBeenCalledWith('m5')
  })

  it('gives attachments kind "file" and keeps their fields', async () => {
    const att = {
      s3_path: 'x',
      mime_type: 'image/png',
      name: 'a.png',
      size_bytes: 10,
      view_url: 'https://f/a.png',
    }
    const res = await assignmentThreadTransport(
      chat({
        list: vi.fn().mockResolvedValue({
          data: [message('m1', { attachments: [att] as never })],
          meta: {
            thread: { id: 't', last_message_at: null, unread_count: 0, human_send_blocked: false },
            has_more: false,
          },
        }),
      }),
    ).list()
    expect(res.data[0]?.attributes.attachments).toEqual([{ kind: 'file', ...att }])
  })

  it('preserves system_event_key / system_event_data and the system kind', async () => {
    const data = { previous_amount: 100, new_amount: 200, currency: 'EUR' }
    const res = await assignmentThreadTransport(
      chat({
        list: vi.fn().mockResolvedValue({
          data: [
            message('s1', {
              kind: 'system',
              body: null,
              system_event_key: 'assignment.offer_edited',
              system_event_data: data,
            }),
          ],
          meta: {
            thread: { id: 't', last_message_at: null, unread_count: 0, human_send_blocked: false },
            has_more: false,
          },
        }),
      }),
    ).list()
    const a = res.data[0]!.attributes
    expect(a.kind).toBe('system')
    expect(a.system_event_key).toBe('assignment.offer_edited')
    expect(a.system_event_data).toEqual(data)
  })

  it('carries id, type, body, sender, role and created_at; read state: null incoming, false own', async () => {
    const res = await assignmentThreadTransport(
      chat({
        list: vi.fn().mockResolvedValue({
          data: [message('in', { is_own: false }), message('out', { is_own: true })],
          meta: {
            thread: { id: 't', last_message_at: null, unread_count: 0, human_send_blocked: false },
            has_more: false,
          },
        }),
      }),
    ).list()
    const [incoming, own] = res.data
    expect(incoming).toMatchObject({
      id: 'in',
      type: 'message',
      attributes: {
        body: 'hi',
        sender: { name: 'Maya' },
        sender_role: 'creator',
        created_at: '2026-01-01T09:30:00+00:00',
        is_own: false,
        read_by_counterparty: null,
      },
    })
    expect(own?.attributes.read_by_counterparty).toBe(false)
  })
})

describe('assignmentThreadTransport.send', () => {
  it('drops links and forwards body and attachments, mapping the result', async () => {
    const send = vi.fn().mockResolvedValue({
      data: message('m9', { is_own: true, body: 'reply', attachments: [] }),
    })
    const t = assignmentThreadTransport(chat({ send }))
    const attachments = [{ upload_id: 'u1' }] as never
    const res = await t.send({
      body: 'reply',
      attachments,
      links: [{ url: 'https://x.example' }],
    })
    expect(send).toHaveBeenCalledWith({ body: 'reply', attachments })
    expect(res.data.id).toBe('m9')
    expect(res.data.attributes.is_own).toBe(true)
    expect(res.data.attributes.read_by_counterparty).toBe(false)
  })

  it('sends only what is present (no undefined keys)', async () => {
    const send = vi.fn().mockResolvedValue({ data: message('m9') })
    await assignmentThreadTransport(chat({ send })).send({ body: 'just text' })
    expect(send.mock.calls[0]![0]).toStrictEqual({ body: 'just text' })

    await assignmentThreadTransport(chat({ send })).send({ links: [{ url: 'https://x.example' }] })
    expect(send.mock.calls[1]![0]).toStrictEqual({})
  })
})

describe('assignmentThreadTransport delegation', () => {
  it('markRead, attachmentInit and attachmentComplete delegate to the chat transport', async () => {
    const c = chat()
    const t = assignmentThreadTransport(c)

    await expect(t.markRead()).resolves.toEqual({ meta: { marked: 1, unread_count: 0 } })
    expect(c.markRead).toHaveBeenCalledTimes(1)

    const init = { filename: 'a.png' } as never
    await t.attachmentInit(init)
    expect(c.attachmentInit).toHaveBeenCalledWith(init)

    await t.attachmentComplete('u1')
    expect(c.attachmentComplete).toHaveBeenCalledWith('u1')
  })
})
