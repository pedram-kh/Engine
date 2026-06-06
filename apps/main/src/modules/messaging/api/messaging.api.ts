/**
 * Typed wrappers for the Sprint 11 messaging surfaces (D-11/D-16).
 *
 * There are TWO surfaces onto the same per-assignment thread:
 *   - AGENCY: /agencies/{agency}/campaigns/{campaign}/assignments/{assignment}/messages
 *   - CREATOR: /creators/me/assignments/{assignment}/messages
 *
 * The chat UI (`ChatPanel`) is surface-agnostic: a parent builds a
 * {@link ChatTransport} via {@link agencyChatTransport} / {@link creatorChatTransport}
 * with its ids pre-bound, and the panel just calls the transport. This keeps the
 * one chat component reusable across the agency Messages tab and the creator's
 * inline thread (the "shared component, two mounts" decision).
 */

import type {
  MessageAttachmentCompleteEnvelope,
  MessageAttachmentInitEnvelope,
  MessageAttachmentInitPayload,
  MessageEnvelope,
  MessageFeedEnvelope,
  MessageMarkReadEnvelope,
  MessageThreadRollupEnvelope,
  SendMessagePayload,
} from '@catalyst/api-client'

import { http } from '@/core/api'

function agencyBase(agencyId: string, campaignUlid: string, assignmentUlid: string): string {
  return `/agencies/${agencyId}/campaigns/${campaignUlid}/assignments/${assignmentUlid}/messages`
}

function creatorBase(assignmentUlid: string): string {
  return `/creators/me/assignments/${assignmentUlid}/messages`
}

/** The surface-agnostic contract the chat UI consumes. */
export interface ChatTransport {
  list(before?: string): Promise<MessageFeedEnvelope>
  send(payload: SendMessagePayload): Promise<MessageEnvelope>
  markRead(): Promise<MessageMarkReadEnvelope>
  attachmentInit(payload: MessageAttachmentInitPayload): Promise<MessageAttachmentInitEnvelope>
  attachmentComplete(uploadId: string): Promise<MessageAttachmentCompleteEnvelope>
}

function listUrl(base: string, before?: string): string {
  if (before === undefined || before === '') {
    return base
  }
  return `${base}?before=${encodeURIComponent(before)}`
}

export function agencyChatTransport(
  agencyId: string,
  campaignUlid: string,
  assignmentUlid: string,
): ChatTransport {
  const base = agencyBase(agencyId, campaignUlid, assignmentUlid)
  return {
    list: (before) => http.get<MessageFeedEnvelope>(listUrl(base, before)),
    send: (payload) => http.post<MessageEnvelope>(base, payload),
    markRead: () => http.post<MessageMarkReadEnvelope>(`${base}/read`),
    attachmentInit: (payload) =>
      http.post<MessageAttachmentInitEnvelope>(`${base}/attachments/init`, payload),
    attachmentComplete: (uploadId) =>
      http.post<MessageAttachmentCompleteEnvelope>(`${base}/attachments/complete`, {
        upload_id: uploadId,
      }),
  }
}

export function creatorChatTransport(assignmentUlid: string): ChatTransport {
  const base = creatorBase(assignmentUlid)
  return {
    list: (before) => http.get<MessageFeedEnvelope>(listUrl(base, before)),
    send: (payload) => http.post<MessageEnvelope>(base, payload),
    markRead: () => http.post<MessageMarkReadEnvelope>(`${base}/read`),
    attachmentInit: (payload) =>
      http.post<MessageAttachmentInitEnvelope>(`${base}/attachments/init`, payload),
    attachmentComplete: (uploadId) =>
      http.post<MessageAttachmentCompleteEnvelope>(`${base}/attachments/complete`, {
        upload_id: uploadId,
      }),
  }
}

export const messagingApi = {
  /** GET …/campaigns/{campaign}/message-threads — the agency Messages-tab roll-up. */
  agencyRollup(agencyId: string, campaignUlid: string): Promise<MessageThreadRollupEnvelope> {
    return http.get<MessageThreadRollupEnvelope>(
      `/agencies/${agencyId}/campaigns/${campaignUlid}/message-threads`,
    )
  },
}
