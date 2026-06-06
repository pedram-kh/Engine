/**
 * The notification LIVE-SET registry — the single source of truth for every
 * property that depends on a type having a live emit site (S11.0 Ch3a + Ch3b).
 *
 * The body text is never on the wire — it renders client-side from
 * `notification_type` + the row's `data` bag. Only the 8 notification types with
 * a LIVE emit site (Ch1/Ch2) are defined here; everything else — the
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

import type { NotificationType, UserType } from '@catalyst/api-client'

const FALLBACK_KEY = 'notifications.types.fallback'

/** The principal a live notification type is delivered to (Ch3b role filter). */
export type NotificationRecipientRole = 'creator' | 'agency'

/** The Ch3b prefs-UI category a live type is grouped under. */
export type NotificationPreferenceGroup = 'assignment' | 'creator'

interface LiveNotificationType {
  /** Flat i18n key under `notifications.types.*` (the Ch3a body template). */
  templateKey: string
  /** The recipient principal this type actually reaches. */
  recipient: NotificationRecipientRole
  /** The prefs-UI grouping. */
  group: NotificationPreferenceGroup
}

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
  },
  'assignment.contracted': {
    templateKey: 'notifications.types.assignment_contracted',
    recipient: 'agency',
    group: 'assignment',
  },
  // Creator review/lifecycle rows — only creators receive these.
  'assignment.revision_requested': {
    templateKey: 'notifications.types.assignment_revision_requested',
    recipient: 'creator',
    group: 'assignment',
  },
  'assignment.draft_approved': {
    templateKey: 'notifications.types.assignment_draft_approved',
    recipient: 'creator',
    group: 'assignment',
  },
  'assignment.draft_rejected': {
    templateKey: 'notifications.types.assignment_draft_rejected',
    recipient: 'creator',
    group: 'assignment',
  },
  'assignment.manually_verified': {
    templateKey: 'notifications.types.assignment_manually_verified',
    recipient: 'creator',
    group: 'assignment',
  },
  'creator.approved': {
    templateKey: 'notifications.types.creator_approved',
    recipient: 'creator',
    group: 'creator',
  },
  'creator.rejected': {
    templateKey: 'notifications.types.creator_rejected',
    recipient: 'creator',
    group: 'creator',
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

/** A prefs-UI group with the live types (for one recipient role) it contains. */
export interface NotificationPreferenceGroupView {
  group: NotificationPreferenceGroup
  types: NotificationType[]
}

/** Stable display order of the prefs groups. */
const PREFERENCE_GROUP_ORDER: readonly NotificationPreferenceGroup[] = ['assignment', 'creator']

/**
 * The prefs toggles a given recipient role can meaningfully set — only the live
 * types that actually TARGET this role (Ch3b honesty: no dead per-role control),
 * grouped + ordered for the UI. Derived entirely from {@link LIVE_TYPES}, so it
 * can never list a type Ch3a can't render or omit one the user receives.
 */
export function preferenceGroupsForRole(
  role: NotificationRecipientRole,
): NotificationPreferenceGroupView[] {
  const byGroup = new Map<NotificationPreferenceGroup, NotificationType[]>()

  for (const [type, definition] of Object.entries(LIVE_TYPES) as Array<
    [NotificationType, LiveNotificationType]
  >) {
    if (definition.recipient !== role) {
      continue
    }
    const existing = byGroup.get(definition.group) ?? []
    existing.push(type)
    byGroup.set(definition.group, existing)
  }

  return PREFERENCE_GROUP_ORDER.filter((group) => byGroup.has(group)).map((group) => ({
    group,
    types: byGroup.get(group) ?? [],
  }))
}
