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

// ─────────────────────────────────────────────────────────────────────────────
// AH-010 — Relationship messaging (1:1 connected agency↔creator)
//
// A parallel wire contract to the campaign messaging types above, mirroring the
// backend's mirrored spine (RelationshipMessageResource). Differences from the
// campaign shape: NO `system_event_key` (relationship threads have no system
// messages); a `read_by_counterparty` two-state read tick on own messages (D10);
// and attachments are a discriminated union of `file` | `link` (D4).
// ─────────────────────────────────────────────────────────────────────────────

/** Relationship messages are never system messages; sender is always human. */
export type RelationshipMessageKind = 'text' | 'attachment_only'

export type RelationshipMessageSenderRole = 'creator' | 'agency_user'

/** A stored file attachment + a freshly-minted signed GET URL. */
export interface RelationshipFileAttachment {
  kind: 'file'
  s3_path: string | null
  mime_type: string | null
  name: string | null
  size_bytes: number | null
  view_url: string | null
}

/** A net-new link attachment (http/https only; validated server-side). */
export interface RelationshipLinkAttachment {
  kind: 'link'
  url: string | null
  name: string | null
}

export type RelationshipMessageAttachment = RelationshipFileAttachment | RelationshipLinkAttachment

export interface RelationshipMessageAttributes {
  kind: RelationshipMessageKind
  sender_role: RelationshipMessageSenderRole
  body: string | null
  attachments: RelationshipMessageAttachment[]
  is_own: boolean
  sender: MessageSender | null
  /** D10: whether the counterparty has read this OWN message. Null on incoming. */
  read_by_counterparty: boolean | null
  created_at: string
}

export interface RelationshipMessageResource {
  id: string
  type: 'relationship_message'
  attributes: RelationshipMessageAttributes
}

export interface RelationshipThreadMeta {
  /**
   * The thread ULID, or `null` for a TRANSIENT thread (AH-012 D1) — a
   * gate-passing conversation opened but not yet provisioned (no message sent).
   * The row materializes on the first send / attachment-upload.
   */
  id: string | null
  last_message_at: string | null
  unread_count: number
}

export interface RelationshipMessageFeedEnvelope {
  data: RelationshipMessageResource[]
  meta: {
    thread: RelationshipThreadMeta
    has_more: boolean
  }
}

export interface RelationshipMessageEnvelope {
  data: RelationshipMessageResource
}

/** A link attachment on the way out (the send payload half). */
export interface SendRelationshipLink {
  url: string
  name?: string
}

export interface SendRelationshipMessagePayload {
  body?: string
  attachments?: SendMessageAttachment[]
  links?: SendRelationshipLink[]
}

/** A row in the AGENCY relationship inbox (keyed by the creator). */
export interface AgencyRelationshipThreadRow {
  id: string
  type: 'relationship_thread'
  attributes: {
    creator: { id: string | null; display_name: string | null }
    last_message_at: string | null
    last_message_preview: string | null
    unread_count: number
  }
}

/** A row in the CREATOR relationship inbox (keyed by the agency). */
export interface CreatorRelationshipThreadRow {
  id: string
  type: 'relationship_thread'
  attributes: {
    agency: { id: string | null; name: string | null; logo_path: string | null }
    last_message_at: string | null
    last_message_preview: string | null
    unread_count: number
  }
}

export interface AgencyRelationshipInboxEnvelope {
  data: AgencyRelationshipThreadRow[]
}

export interface CreatorRelationshipInboxEnvelope {
  data: CreatorRelationshipThreadRow[]
}

// ─────────────────────────────────────────────────────────────────────────────
// AH-012 — gate-filtered contact pickers (new-conversation flow)
//
// The set-valued half of the messaging gate: contacts the picker may open a
// conversation with. Only messageable contacts appear (roster + approved +
// non-blacklisted), so picking one can never 403 on the subsequent send.
// ─────────────────────────────────────────────────────────────────────────────

/** A creator the agency may message (agency-side picker row). */
export interface MessageableCreatorRow {
  id: string
  type: 'messageable_creator'
  attributes: {
    display_name: string | null
  }
}

/** Paginated envelope for the agency picker (D6: rosters can be large). */
export interface MessageableCreatorsEnvelope {
  data: MessageableCreatorRow[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
  }
}

/** An agency the creator may message (creator-side picker row). */
export interface MessageableAgencyRow {
  id: string
  type: 'messageable_agency'
  attributes: {
    name: string | null
    logo_path: string | null
  }
}

/** Unpaginated envelope for the creator picker (small list, D6). */
export interface MessageableAgenciesEnvelope {
  data: MessageableAgencyRow[]
}
