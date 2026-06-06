/**
 * Wire-contract types for the Sprint 11 messaging surfaces.
 *
 * These mirror the backend `MessageResource` + the agency/creator endpoint
 * envelopes VERBATIM (snake_case keys, ISO 8601 + offset timestamps, ULID
 * identifiers) — the same FE↔BE no-re-casing discipline as the other type
 * modules.
 *
 * System-message body text is NEVER on the wire (D-5): the backend exposes the
 * `system_event_key` + the structured primitives, and the SPA renders the
 * localized line client-side.
 */

/** Mirrors `App\Modules\Messaging\Enums\MessageKind`. */
export type MessageKind = 'text' | 'system' | 'attachment_only'

/** Mirrors `App\Modules\Messaging\Enums\MessageSenderRole`. */
export type MessageSenderRole = 'creator' | 'agency_user' | 'brand_user' | 'system' | 'admin'

/** A presented attachment: stored metadata + a freshly-minted signed GET URL. */
export interface MessageAttachment {
  s3_path: string | null
  mime_type: string | null
  name: string | null
  size_bytes: number | null
  view_url: string | null
}

export interface MessageSender {
  name: string
}

export interface MessageAttributes {
  kind: MessageKind
  sender_role: MessageSenderRole
  body: string | null
  attachments: MessageAttachment[]
  system_event_key: string | null
  is_own: boolean
  sender: MessageSender | null
  created_at: string
}

export interface MessageResource {
  id: string
  type: 'message'
  attributes: MessageAttributes
}

/** The thread meta envelope returned beside each message page. */
export interface MessageThreadMeta {
  id: string
  assignment_id: string | null
  last_message_at: string | null
  unread_count: number
  human_send_blocked: boolean
}

export interface MessageFeedEnvelope {
  data: MessageResource[]
  meta: {
    thread: MessageThreadMeta
    has_more: boolean
  }
}

export interface MessageEnvelope {
  data: MessageResource
}

export interface MessageMarkReadEnvelope {
  meta: {
    marked: number
    unread_count: number
  }
}

/** A row in the agency Messages-tab roll-up. */
export interface MessageThreadRollupRow {
  id: string
  type: 'message_thread'
  attributes: {
    assignment_id: string | null
    status: string | null
    creator: { display_name: string | null }
    last_message_at: string | null
    last_message_preview: string | null
    unread_count: number
  }
}

export interface MessageThreadRollupEnvelope {
  data: MessageThreadRollupRow[]
}

export interface SendMessageAttachment {
  upload_id: string
  mime_type: string
  name: string
  size_bytes: number
}

export interface SendMessagePayload {
  body?: string
  attachments?: SendMessageAttachment[]
}

export interface MessageAttachmentInitPayload {
  mime_type: string
  size_bytes: number
}

export interface MessageAttachmentInitEnvelope {
  data: {
    upload_url: string
    upload_id: string
    storage_path: string
    expires_at: string
    max_bytes: number
  }
}

export interface MessageAttachmentCompleteEnvelope {
  data: {
    storage_path: string
  }
}
