/**
 * AH-119 — the transport shape the agency thread view (`AgencyRelationshipThreadView`) consumes,
 * and the adapter that lets a campaign (assignment) conversation use the same view.
 *
 * The view was written for the relationship message shape. An assignment thread has the same
 * bones (messages, a feed, send, read, attachments) plus system lines and a `human_send_blocked`
 * flag instead of `can_send`. {@link assignmentThreadTransport} maps one onto the other, so the
 * view stays transport-agnostic and `ChatPanel` (shared with the creator) stays untouched.
 * A relationship transport satisfies {@link AgencyThreadTransport} as it is.
 */

import type {
  EditMessagePayload,
  MessageAttachmentCompleteEnvelope,
  MessageAttachmentInitEnvelope,
  MessageAttachmentInitPayload,
  MessageKind,
  MessageMarkReadEnvelope,
  MessageResource,
  MessageSenderRole,
  RelationshipMessageAttributes,
  RelationshipThreadMeta,
  SendRelationshipMessagePayload,
} from '@catalyst/api-client'

import type { ChatTransport } from './messaging.api'

export interface AgencyThreadMessage {
  id: string
  type: string
  attributes: Omit<RelationshipMessageAttributes, 'kind' | 'sender_role'> & {
    kind: MessageKind
    sender_role: MessageSenderRole
    /** Present on an assignment thread's system lines. */
    system_event_key?: string | null
    system_event_data?: Record<string, unknown> | null
  }
}

export interface AgencyThreadTransport {
  list(before?: string): Promise<{
    data: AgencyThreadMessage[]
    meta: { thread: RelationshipThreadMeta; has_more: boolean }
  }>
  send(payload: SendRelationshipMessagePayload): Promise<{ data: AgencyThreadMessage }>
  markRead(): Promise<MessageMarkReadEnvelope>
  attachmentInit(payload: MessageAttachmentInitPayload): Promise<MessageAttachmentInitEnvelope>
  attachmentComplete(uploadId: string): Promise<MessageAttachmentCompleteEnvelope>
  /** AH-119 Stage B (a relationship transport satisfies these as it is). */
  edit?(messageId: string, payload: EditMessagePayload): Promise<{ data: AgencyThreadMessage }>
  remove?(messageId: string): Promise<{ data: AgencyThreadMessage }>
}

function toThreadMessage(message: MessageResource): AgencyThreadMessage {
  const a = message.attributes
  return {
    id: message.id,
    type: message.type,
    attributes: {
      kind: a.kind,
      sender_role: a.sender_role,
      body: a.body,
      attachments: a.attachments.map((att) => ({ kind: 'file' as const, ...att })),
      is_own: a.is_own,
      sender: a.sender,
      // Assignment threads carry no per-message read state.
      read_by_counterparty: a.is_own ? false : null,
      created_at: a.created_at,
      edited_at: a.edited_at ?? null,
      is_deleted: a.is_deleted ?? false,
      system_event_key: a.system_event_key,
      system_event_data: a.system_event_data,
    },
  }
}

export function assignmentThreadTransport(chat: ChatTransport): AgencyThreadTransport {
  return {
    list: async (before) => {
      const res = await chat.list(before)
      const t = res.meta.thread
      return {
        data: res.data.map(toThreadMessage),
        meta: {
          has_more: res.meta.has_more,
          thread: {
            id: t.id,
            last_message_at: t.last_message_at,
            unread_count: t.unread_count,
            can_send: !t.human_send_blocked,
            closed_reason: null,
          },
        },
      }
    },
    send: async (payload) => {
      const res = await chat.send({
        ...(payload.body !== undefined ? { body: payload.body } : {}),
        ...(payload.attachments !== undefined ? { attachments: payload.attachments } : {}),
      })
      return { data: toThreadMessage(res.data) }
    },
    markRead: () => chat.markRead(),
    attachmentInit: (payload) => chat.attachmentInit(payload),
    attachmentComplete: (uploadId) => chat.attachmentComplete(uploadId),
    ...(chat.edit !== undefined
      ? {
          edit: async (messageId: string, payload: EditMessagePayload) => {
            const res = await chat.edit!(messageId, payload)
            return { data: toThreadMessage(res.data) }
          },
        }
      : {}),
    ...(chat.remove !== undefined
      ? {
          remove: async (messageId: string) => {
            const res = await chat.remove!(messageId)
            return { data: toThreadMessage(res.data) }
          },
        }
      : {}),
  }
}
