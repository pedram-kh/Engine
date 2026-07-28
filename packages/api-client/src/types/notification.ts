/**
 * Wire-contract types for the per-user notification feed (S11.0).
 *
 * These mirror the backend `NotificationResource` + the four
 * `/me/notifications` endpoint envelopes VERBATIM (snake_case keys,
 * ISO 8601 + offset timestamps, ULID identifiers) — the same FE↔BE
 * no-re-casing discipline as `creator.ts`/`availability.ts`.
 *
 * The body text is NEVER on the wire: the backend exposes the structured
 * primitives (`notification_type` + `data` + `actor`/`subject`) and the SPA
 * renders the localized body client-side (Ch3a). `data` is a free-shape
 * render-param bag whose keys depend on `notification_type` — each emit site
 * sends only the keys its template needs, so consumers MUST treat every key as
 * optional (the `{}`-tolerant `creator.approved` is the canonical trap).
 *
 * `subject` is plumbed for the deferred deep-link-to-subject work (D-7) and is
 * NOT consumed this chunk.
 */

/**
 * Mirrors `App\Modules\Notifications\Enums\NotificationType` — the curated
 * subset of `AuditAction` verbs a recipient sees in their feed. Not all of
 * these have a live emit site: the rest are forward-declared (deferred-S10
 * escrow alerts + lifecycle verbs awaiting a net-new emitter) and fall to the
 * generic fallback template until a producer ships. The authoritative live-set
 * is the FE notifications registry (`LIVE_TYPES` in
 * `apps/main/src/modules/notifications/templates.ts`), whose parity spec pins
 * this union against the backend enum — so a verb added on one side without the
 * other fails the build rather than reaching a user as "You have a new
 * notification."
 */
export type NotificationType =
  | 'assignment.invited'
  | 'assignment.declined'
  | 'assignment.countered'
  | 'assignment.accepted'
  | 'assignment.contracted'
  | 'assignment.draft_submitted'
  | 'assignment.revision_requested'
  | 'assignment.draft_approved'
  | 'assignment.draft_rejected'
  | 'assignment.manually_verified'
  | 'assignment.cancelled'
  | 'assignment.payment_funded'
  | 'assignment.payment_released'
  | 'creator.approved'
  | 'creator.rejected'
  | 'message.received_by_creator'
  | 'message.received_by_agency'
  | 'message.relationship_received_by_creator'
  | 'message.relationship_received_by_agency'
  | 'agency_creator_relation.admin_connected'
  | 'agency_creator_relation.disconnected'
  | 'campaign.job_posted'
  // AH-058 (Jobs Board chunk 4, D6) — the three application verbs. `submitted`
  // reaches the agency; `accepted` and `rejected` reach the creator. `rejected`
  // has two emit sites (the agency's answer and the campaign-terminal
  // auto-reject) distinguished by `data.cause` — see the FE registry.
  | 'campaign_application.submitted'
  | 'campaign_application.accepted'
  | 'campaign_application.rejected'

/** The acting user who drove the event, when one exists (null for system-driven). */
export interface NotificationActor {
  id: string
  name: string
}

/**
 * The notification's polymorphic subject — class basename + public ULID. The
 * `ulid` can be null when the subject row resolves but exposes no route key.
 * Deferred-D-7: not consumed for navigation this chunk.
 */
export interface NotificationSubject {
  type: string
  ulid: string | null
}

export interface NotificationAttributes {
  notification_type: NotificationType
  /**
   * Free-shape render params. Keys vary by `notification_type` and are ALL
   * optional from the client's perspective — bind only the keys a given type's
   * emit site sends (e.g. agency rows carry `campaign_ulid`, NOT
   * `assignment_ulid`; `creator.approved` may be `{}`).
   */
  data: Record<string, unknown>
  /** ISO 8601 with UTC offset, or null when still unread. */
  read_at: string | null
  /** ISO 8601 with UTC offset. */
  created_at: string
  actor: NotificationActor | null
  subject: NotificationSubject | null
}

export interface NotificationResource {
  id: string
  type: 'notifications'
  attributes: NotificationAttributes
}

/**
 * Feed pagination + unread metadata. `unread_count` rides along on every feed
 * fetch so an open dropdown/page doubles as a count reconcile point alongside
 * the steady poll (D-5).
 */
export interface NotificationFeedMeta {
  total: number
  page: number
  per_page: number
  last_page: number
  unread_count: number
}

/** `GET /me/notifications?page=&per_page=` */
export interface NotificationFeedEnvelope {
  data: NotificationResource[]
  meta: NotificationFeedMeta
}

/** `GET /me/notifications/unread-count` — the cheap count-only endpoint. */
export interface NotificationUnreadCountEnvelope {
  data: {
    type: 'notification_unread_count'
    attributes: {
      unread_count: number
    }
  }
}

/** `PATCH /me/notifications/{ulid}/read` — idempotent. */
export interface NotificationMarkReadEnvelope {
  data: {
    type: 'notifications'
    id: string
    attributes: {
      read_at: string | null
    }
  }
  meta: {
    code: 'notification.read'
  }
}

/** `POST /me/notifications/read-all` — idempotent; returns the rows it flipped. */
export interface NotificationReadAllEnvelope {
  data: {
    type: 'notification_read_all'
    attributes: {
      marked_count: number
    }
  }
  meta: {
    code: 'notification.read_all'
  }
}

/**
 * The delivery channels a preference can toggle (S11.0 Ch3b). Mirrors
 * `App\Modules\Notifications\Enums\NotificationChannel`. The Ch3b UI surfaces
 * `in_app` only (`email` rides independently of prefs; `digest` has no consumer
 * until Messaging) — but the wire contract carries all three so the channels
 * light up with no type change when a consumer ships.
 */
export type NotificationChannel = 'in_app' | 'email' | 'digest'

/**
 * A single SPARSE preference row — present ONLY when it diverges from the
 * channel default. The full display state is composed against the `defaults`
 * block: `row?.is_enabled ?? defaults[channel]` (the channel default contract
 * is never hardcoded client-side).
 */
export interface NotificationPreferenceRow {
  notification_type: NotificationType
  channel: NotificationChannel
  is_enabled: boolean
}

/**
 * `GET` / `PATCH /me/notification-preferences`. The read returns the caller's
 * sparse rows AND the server-authoritative channel `defaults`; the write
 * (sparse upsert/delete) returns the recomputed state in the same shape.
 */
export interface NotificationPreferencesEnvelope {
  data: {
    type: 'notification_preferences'
    attributes: {
      preferences: NotificationPreferenceRow[]
      /** Channel value → its preserve-current default (`in_app`/`email` on, `digest` off). */
      defaults: Record<NotificationChannel, boolean>
    }
  }
}

/** `PATCH /me/notification-preferences` body — a batch of per-row toggles. */
export interface UpdateNotificationPreferencesPayload {
  preferences: NotificationPreferenceRow[]
}
