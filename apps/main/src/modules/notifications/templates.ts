/**
 * The notification LIVE-SET registry — the single source of truth for every
 * property that depends on a type having a live emit site (S11.0 Ch3a + Ch3b).
 *
 * The body text is never on the wire — it renders client-side from
 * `notification_type` + the row's `data` bag. Only the notification types with
 * a LIVE emit site (Ch1/Ch2 + Sprint 11 messaging) are defined here; everything
 * else — the
 * forward-declared deferred-S10 payment verbs, the lifecycle verbs still
 * awaiting an emitter, AND any string the backend might send that the FE union
 * doesn't even know about — resolves to the generic `fallback` template and is
 * never offered as a preference toggle.
 *
 * ⚠ ONE source of truth (S11.0 Ch3b review seam): each live type is defined ONCE
 * with everything liveness implies —
 *   - `templateKey` — the Ch3a body template it renders through;
 *   - `recipient`   — the principal it is actually delivered to (the Ch3b prefs
 *                     role filter: a creator must not see a toggle for a
 *                     notification only agencies receive, and vice versa);
 *   - `group`       — the Ch3b prefs-UI category.
 * The renderer's template lookup, the prefs-exposed list, AND the per-role
 * partition all derive from {@link LIVE_TYPES}, so they cannot drift: a type can
 * never appear as a toggle the renderer can't render, nor be omitted for a user
 * who actually receives it.
 *
 * Per-template data binding (Ch3a): each template interpolates ONLY the keys its
 * emit site sends (creator review rows carry campaign_name / creator_name /
 * feedback; agency fan-out rows carry creator_name / campaign_name; creator.approved
 * may be `{}`). No param is universal, so every template reads correctly with a
 * null actor and only its own keys present.
 */

import type { NotificationChannel, NotificationType, UserType } from '@catalyst/api-client'

const FALLBACK_KEY = 'notifications.types.fallback'

/** The principal a live notification type is delivered to (Ch3b role filter). */
export type NotificationRecipientRole = 'creator' | 'agency'

/** The Ch3b prefs-UI category a live type is grouped under. */
export type NotificationPreferenceGroup = 'assignment' | 'creator' | 'messaging'

interface LiveNotificationType {
  /** Flat i18n key under `notifications.types.*` (the Ch3a body template). */
  templateKey: string
  /** The recipient principal this type actually reaches. */
  recipient: NotificationRecipientRole
  /** The prefs-UI grouping. */
  group: NotificationPreferenceGroup
  /**
   * The channels this type supports a toggle for (Sprint 11, D-10). A channel
   * appears here ONLY when a consumer actually delivers it — never ship a
   * toggle for a channel that gates nothing (dead control). All live types
   * support `in_app` (the Ch3a feed). Messaging additionally supports `digest`
   * (the daily email job, opt-in / default OFF) the moment that job consumes
   * it.
   */
  channels: NotificationChannel[]
}

/** Every live type supports the in-app feed; this is the common case. */
const IN_APP_ONLY: NotificationChannel[] = ['in_app']

/**
 * The live-set. The dotted enum values can't be `t()`-pathed directly (vue-i18n
 * would treat the dot as nesting), so the flat-underscore `templateKey` applies;
 * the map's key-set is also the only-8 allowlist.
 */
const LIVE_TYPES: Partial<Record<NotificationType, LiveNotificationType>> = {
  // Agency fan-out rows (admins + managers) — only agency users receive these.
  'assignment.draft_submitted': {
    templateKey: 'notifications.types.assignment_draft_submitted',
    recipient: 'agency',
    group: 'assignment',
    channels: IN_APP_ONLY,
  },
  'assignment.contracted': {
    templateKey: 'notifications.types.assignment_contracted',
    recipient: 'agency',
    group: 'assignment',
    channels: IN_APP_ONLY,
  },
  // Creator review/lifecycle rows — only creators receive these.
  'assignment.revision_requested': {
    templateKey: 'notifications.types.assignment_revision_requested',
    recipient: 'creator',
    group: 'assignment',
    channels: IN_APP_ONLY,
  },
  'assignment.draft_approved': {
    templateKey: 'notifications.types.assignment_draft_approved',
    recipient: 'creator',
    group: 'assignment',
    channels: IN_APP_ONLY,
  },
  'assignment.draft_rejected': {
    templateKey: 'notifications.types.assignment_draft_rejected',
    recipient: 'creator',
    group: 'assignment',
    channels: IN_APP_ONLY,
  },
  'assignment.manually_verified': {
    templateKey: 'notifications.types.assignment_manually_verified',
    recipient: 'creator',
    group: 'assignment',
    channels: IN_APP_ONLY,
  },
  'creator.approved': {
    templateKey: 'notifications.types.creator_approved',
    recipient: 'creator',
    group: 'creator',
    channels: IN_APP_ONLY,
  },
  'creator.rejected': {
    templateKey: 'notifications.types.creator_rejected',
    recipient: 'creator',
    group: 'creator',
    channels: IN_APP_ONLY,
  },
  // Messaging (Sprint 11, D-7) — dual-recipient new-message notifications. Each
  // direction targets exactly one role, so each gets its own toggle (no dead
  // control). by_creator → the creator receives (agency sent); by_agency → the
  // agency receives (creator sent). Messaging is the first type to expose the
  // `digest` channel (D-10): the daily-digest job consumes it, so the toggle
  // ships WITH its consumer (no dead control, no un-opt-out-able digest).
  'message.received_by_creator': {
    templateKey: 'notifications.types.message_received_by_creator',
    recipient: 'creator',
    group: 'messaging',
    channels: ['in_app', 'digest'],
  },
  'message.received_by_agency': {
    templateKey: 'notifications.types.message_received_by_agency',
    recipient: 'agency',
    group: 'messaging',
    channels: ['in_app', 'digest'],
  },
  // Relationship messaging (AH-010, D5) — the 1:1 connected agency↔creator DM,
  // distinct from the campaign-assignment thread above. Dual-recipient, same as
  // the campaign pair. UNLIKE campaign messaging these are `in_app` ONLY: the
  // relationship-message digest is deferred (AH-010 D5), so no `digest` toggle
  // ships until that job consumes them (no dead control).
  'message.relationship_received_by_creator': {
    templateKey: 'notifications.types.message_relationship_received_by_creator',
    recipient: 'creator',
    group: 'messaging',
    channels: IN_APP_ONLY,
  },
  'message.relationship_received_by_agency': {
    templateKey: 'notifications.types.message_relationship_received_by_agency',
    recipient: 'agency',
    group: 'messaging',
    channels: IN_APP_ONLY,
  },
}

/**
 * Resolve the i18n key for a notification's body. Accepts a plain `string`
 * (not just `NotificationType`) on purpose: a genuinely unknown type the
 * backend might add later is still routed to the fallback rather than throwing.
 */
export function notificationTemplateKey(type: string): string {
  return LIVE_TYPES[type as NotificationType]?.templateKey ?? FALLBACK_KEY
}

/** True when a type has a bespoke (non-fallback) template. Exported for tests. */
export function hasLiveTemplate(type: string): boolean {
  return Object.prototype.hasOwnProperty.call(LIVE_TYPES, type)
}

export const NOTIFICATION_FALLBACK_KEY = FALLBACK_KEY

/** Map a `user_type` to the recipient role the prefs list filters on. */
export function recipientRoleForUserType(userType: UserType): NotificationRecipientRole {
  return userType === 'creator' ? 'creator' : 'agency'
}

/** A single live type's prefs row: the type + the channels it can toggle. */
export interface NotificationPreferenceTypeView {
  type: NotificationType
  channels: NotificationChannel[]
}

/** A prefs-UI group with the live types (for one recipient role) it contains. */
export interface NotificationPreferenceGroupView {
  group: NotificationPreferenceGroup
  types: NotificationPreferenceTypeView[]
}

/** Stable display order of the prefs groups. */
const PREFERENCE_GROUP_ORDER: readonly NotificationPreferenceGroup[] = [
  'assignment',
  'creator',
  'messaging',
]

/**
 * The prefs toggles a given recipient role can meaningfully set — only the live
 * types that actually TARGET this role (Ch3b honesty: no dead per-role control),
 * grouped + ordered for the UI. Derived entirely from {@link LIVE_TYPES}, so it
 * can never list a type Ch3a can't render or omit one the user receives.
 */
export function preferenceGroupsForRole(
  role: NotificationRecipientRole,
): NotificationPreferenceGroupView[] {
  const byGroup = new Map<NotificationPreferenceGroup, NotificationPreferenceTypeView[]>()

  for (const [type, definition] of Object.entries(LIVE_TYPES) as Array<
    [NotificationType, LiveNotificationType]
  >) {
    if (definition.recipient !== role) {
      continue
    }
    const existing = byGroup.get(definition.group) ?? []
    existing.push({ type, channels: definition.channels })
    byGroup.set(definition.group, existing)
  }

  return PREFERENCE_GROUP_ORDER.filter((group) => byGroup.has(group)).map((group) => ({
    group,
    types: byGroup.get(group) ?? [],
  }))
}
