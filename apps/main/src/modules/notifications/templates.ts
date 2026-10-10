/**
 * The notification LIVE-SET registry — the single source of truth for every
 * property that depends on a type having a live emit site (S11.0 Ch3a + Ch3b).
 *
 * The body text is never on the wire — it renders client-side from
 * `notification_type` + the row's `data` bag. Only the notification types with
 * a LIVE emit site (Ch1/Ch2 + Sprint 11 messaging + AH-051 relations) are
 * defined here; everything else — the
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
 *   - `preference`  — the Ch3b prefs row, or `null` for an ALWAYS-ON type.
 * The renderer's template lookup, the prefs-exposed list, AND the per-role
 * partition all derive from {@link LIVE_TYPES}, so they cannot drift: a type can
 * never appear as a toggle the renderer can't render, nor be omitted for a user
 * who actually receives it.
 *
 * ⚠ REGISTERING A TYPE IS NOT OPTIONAL. An unregistered live type still gets
 * delivered — it just renders as "You have a new notification.", which is how
 * AH-051's two relation verbs shipped: real emit sites, no registry entries, and
 * a green suite because nothing pinned the two together. `templates.spec.ts` now
 * pins this registry against the backend `NotificationType` union, with the
 * deliberately-deferred verbs as an explicit allowlist, so the next type added
 * without a template fails the build instead of reaching a user as a shrug.
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

/**
 * Delivery reach. `both` is for a genuinely dual-recipient type where ONE verb
 * reaches both sides of a pair — AH-051's relation-disconnected, whose emit site
 * notifies the creator AND every active agency member with the same type and a
 * direction-agnostic `counterparty_name`.
 */
export type NotificationReach = NotificationRecipientRole | 'both'

/** The Ch3b prefs-UI category a live type is grouped under. */
export type NotificationPreferenceGroup = 'assignment' | 'jobs_board' | 'creator' | 'messaging'

/**
 * A live type's preferences row: the group it appears under and the channels it
 * can toggle (Sprint 11, D-10). A channel appears here ONLY when a consumer
 * actually delivers it — never ship a toggle that gates nothing (dead control).
 * All live types support `in_app` (the Ch3a feed). Messaging additionally
 * supports `digest` (the daily email job, opt-in / default OFF).
 */
interface NotificationPreferenceDefinition {
  group: NotificationPreferenceGroup
  channels: NotificationChannel[]
  /**
   * Channels this type delivers that the user CANNOT turn off (AH-101, Q2).
   * Shown in the matrix as an "Always on" caption instead of a switch — the
   * honest rendering of a mail that is account-lifecycle transactional
   * (`creator.approved` / `creator.rejected`). Never overlaps `channels`.
   */
  alwaysOnChannels?: NotificationChannel[]
}

interface LiveNotificationType {
  /** Flat i18n key under `notifications.types.*` (the Ch3a body template). */
  templateKey: string
  /** The recipient principal(s) this type actually reaches. */
  recipient: NotificationReach
  /**
   * The prefs row this type exposes, or `null` when it is deliberately
   * ALWAYS-ON — renderable but not user-mutable.
   *
   * `null` is a POSITIVE assertion, not an oversight: it says "this type has a
   * template and no toggle, on purpose". The backend honours the `in_app`
   * preference for every type and defaults it to enabled, so a `null` here
   * means the notification simply always arrives. Reserved for consequential
   * account news the user must not be able to miss — being connected to, or
   * disconnected from, an agency changes who can see and message them, so it is
   * not something to silence.
   */
  preference: NotificationPreferenceDefinition | null
}

/** Every live type supports the in-app feed; this is the common case. */
const IN_APP_ONLY: NotificationChannel[] = ['in_app']

/**
 * AH-101 — a type whose mail now honours the recipient's `email` preference
 * (`NotificationService::isEmailEnabled`, the one backend guard). A channel is
 * listed ONLY where that guard is wired at the type's send site; the backend's
 * `EmailPreferenceGuardTest` is the other half of that promise.
 */
const IN_APP_AND_EMAIL: NotificationChannel[] = ['in_app', 'email']

/**
 * The matrix's column order — the ONE place a channel's position is decided.
 * The preferences page derives its columns from the union of the registry's
 * channels sorted by this list, so adding a channel (AH-102's `push`) is one
 * entry here plus the enum case and the label — no page change.
 */
export const PREFERENCE_CHANNEL_ORDER: readonly NotificationChannel[] = [
  'in_app',
  'email',
  'digest',
]

/**
 * The live-set. The dotted enum values can't be `t()`-pathed directly (vue-i18n
 * would treat the dot as nesting), so the flat-underscore `templateKey` applies.
 */
const LIVE_TYPES: Partial<Record<NotificationType, LiveNotificationType>> = {
  // AH-083 (①, kickoff Q2) — dual-emit: the creator's invite in-app row rides
  // alongside the new invite EMAIL (SendAssignmentNotifications, backend).
  // ONE type covers both `assignment.invited` (fresh) AND the `re_invited`
  // audit verb (no separate NotificationType exists for re-invite — the
  // in-app row reads identically either way; the mail side is what carries the
  // fresh/re-offer copy split).
  'assignment.invited': {
    templateKey: 'notifications.types.assignment_invited',
    recipient: 'creator',
    preference: { group: 'assignment', channels: IN_APP_AND_EMAIL },
  },
  // Agency fan-out rows (admins + managers) — only agency users receive these.
  'assignment.draft_submitted': {
    templateKey: 'notifications.types.assignment_draft_submitted',
    recipient: 'agency',
    preference: { group: 'assignment', channels: IN_APP_ONLY },
  },
  'assignment.contracted': {
    templateKey: 'notifications.types.assignment_contracted',
    recipient: 'agency',
    preference: { group: 'assignment', channels: IN_APP_ONLY },
  },
  // Creator review/lifecycle rows — only creators receive these.
  'assignment.revision_requested': {
    templateKey: 'notifications.types.assignment_revision_requested',
    recipient: 'creator',
    preference: { group: 'assignment', channels: IN_APP_AND_EMAIL },
  },
  'assignment.draft_approved': {
    templateKey: 'notifications.types.assignment_draft_approved',
    recipient: 'creator',
    preference: { group: 'assignment', channels: IN_APP_AND_EMAIL },
  },
  'assignment.draft_rejected': {
    templateKey: 'notifications.types.assignment_draft_rejected',
    recipient: 'creator',
    preference: { group: 'assignment', channels: IN_APP_AND_EMAIL },
  },
  // AH-069 (D5) — sits BESIDE the draft-approved row, not instead of it: on a
  // campaign that hands off at approval the creator gets both, and together they
  // read "approved" then "and that's it". The body interpolates
  // {campaign_name} only — no round, because this row is about the assignment
  // ending rather than about a round of review.
  'assignment.completed_on_approval': {
    templateKey: 'notifications.types.assignment_completed_on_approval',
    recipient: 'creator',
    preference: { group: 'assignment', channels: IN_APP_AND_EMAIL },
  },
  'assignment.manually_verified': {
    templateKey: 'notifications.types.assignment_manually_verified',
    recipient: 'creator',
    preference: { group: 'assignment', channels: IN_APP_AND_EMAIL },
  },
  // AH-101 (⑦) — the creator's post passed AUTOMATIC verification: the silent
  // success branch, now a notice. Creator-only. The mail leg is behind
  // `post_verified_mail_enabled` (default OFF) on the backend, but the `email`
  // cell is still honest here: the preference is read the moment the flag arms,
  // so opting out early is real, not decorative.
  'assignment.live_verified': {
    templateKey: 'notifications.types.assignment_live_verified',
    recipient: 'creator',
    preference: { group: 'assignment', channels: IN_APP_AND_EMAIL },
  },
  'creator.approved': {
    templateKey: 'notifications.types.creator_approved',
    recipient: 'creator',
    preference: { group: 'creator', channels: IN_APP_ONLY, alwaysOnChannels: ['email'] },
  },
  'creator.rejected': {
    templateKey: 'notifications.types.creator_rejected',
    recipient: 'creator',
    preference: { group: 'creator', channels: IN_APP_ONLY, alwaysOnChannels: ['email'] },
  },
  // Messaging (Sprint 11, D-7) — dual-recipient new-message notifications. Each
  // direction targets exactly one role, so each gets its own toggle (no dead
  // control). by_creator → the creator receives (agency sent); by_agency → the
  // agency receives (creator sent). Messaging is the first type to expose the
  // `digest` channel (D-10): the daily-digest job consumes it, so the toggle
  // ships WITH its consumer (no dead control, no un-opt-out-able digest). The
  // creator direction also carries `email` (AH-101) — the debounced new-message
  // mail; the agency direction has no message mail, so no email cell.
  'message.received_by_creator': {
    templateKey: 'notifications.types.message_received_by_creator',
    recipient: 'creator',
    preference: { group: 'messaging', channels: ['in_app', 'email', 'digest'] },
  },
  'message.received_by_agency': {
    templateKey: 'notifications.types.message_received_by_agency',
    recipient: 'agency',
    preference: { group: 'messaging', channels: ['in_app', 'digest'] },
  },
  // Relationship messaging (AH-010, D5) — the 1:1 connected agency↔creator DM,
  // distinct from the campaign-assignment thread above. Dual-recipient, same as
  // the campaign pair. UNLIKE campaign messaging there is no `digest` cell: the
  // relationship-message digest is deferred (AH-010 D5), so no toggle ships until
  // that job consumes them (no dead control). The creator side gained an `email`
  // cell in AH-101 (the debounced mail now reads the preference); the AGENCY side
  // has no message mail, so it stays `in_app` only.
  'message.relationship_received_by_creator': {
    templateKey: 'notifications.types.message_relationship_received_by_creator',
    recipient: 'creator',
    preference: { group: 'messaging', channels: IN_APP_AND_EMAIL },
  },
  'message.relationship_received_by_agency': {
    templateKey: 'notifications.types.message_relationship_received_by_agency',
    recipient: 'agency',
    preference: { group: 'messaging', channels: IN_APP_ONLY },
  },
  // Relation lifecycle (AH-051, D7) — admin-mediated connect + disconnect.
  // ALWAYS-ON (`preference: null`): both change who can see the creator's
  // contact details and who may message them, and the disconnect notice is the
  // only in-app signal that a relationship ended. `admin_connected` reaches the
  // creator alone (the agency asked for the arrangement, so it is not news to
  // them); `disconnected` reaches both sides with the same verb, reading the
  // direction-agnostic `counterparty_name` from its data bag.
  'agency_creator_relation.admin_connected': {
    templateKey: 'notifications.types.agency_creator_relation_admin_connected',
    recipient: 'creator',
    preference: null,
  },
  'agency_creator_relation.disconnected': {
    templateKey: 'notifications.types.agency_creator_relation_disconnected',
    recipient: 'both',
    preference: null,
  },
  // Jobs board (AH-056 D8 + AH-058 D6) — the four jobs-board types, now their
  // own group.
  //
  // ⚠ `campaign.job_posted` MOVED here from `assignment` (AH-058, Q5). AH-056
  // grouped it under `assignment` because one type does not earn a group, and
  // wrote the trigger for revisiting down: the split happens when a second
  // jobs-board type exists to split with. Chunk 4 adds three, so the accepted
  // label tension ("Assignments" over a job-posted toggle) is resolved rather
  // than multiplied by four.
  //
  // Directions are asymmetric and each type has exactly ONE recipient role, so
  // each toggle is honest for whoever sees it: `submitted` reaches the AGENCY (a
  // creator applying is not news to the creator), `job_posted` / `accepted` /
  // `rejected` reach the CREATOR (the agency performed those acts).
  //
  // All four carry an `email` cell as of AH-101: their send sites now read the
  // recipient's `email` preference (`NotificationService::isEmailEnabled`),
  // INSIDE the Pennant flags that still contain the mail
  // (`job_posted_notifications_enabled`, `application_notifications_enabled`) —
  // the flag is the kill switch, the preference is the user's say.
  'campaign.job_posted': {
    templateKey: 'notifications.types.campaign_job_posted',
    recipient: 'creator',
    preference: { group: 'jobs_board', channels: IN_APP_AND_EMAIL },
  },
  'campaign_application.submitted': {
    templateKey: 'notifications.types.campaign_application_submitted',
    recipient: 'agency',
    preference: { group: 'jobs_board', channels: IN_APP_AND_EMAIL },
  },
  'campaign_application.accepted': {
    templateKey: 'notifications.types.campaign_application_accepted',
    recipient: 'creator',
    preference: { group: 'jobs_board', channels: IN_APP_AND_EMAIL },
  },
  // Two emit sites, one type: the agency's reject and the campaign-terminal
  // auto-reject. The template reads `campaign_name` only — `data.cause`
  // (`agency_rejected` | `campaign_closed`) is part of the contract and selects
  // the MAIL body variant; the in-app line is identical either way, because the
  // answer a creator needs is the same and a per-cause in-app sentence would
  // explain a distinction they cannot act on.
  'campaign_application.rejected': {
    templateKey: 'notifications.types.campaign_application_rejected',
    recipient: 'creator',
    preference: { group: 'jobs_board', channels: IN_APP_AND_EMAIL },
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

/**
 * A single live type's prefs row: the type + the channels it can toggle, plus the
 * channels it delivers that are ALWAYS ON (rendered as a caption, never a switch).
 * Both lists are sorted by {@link PREFERENCE_CHANNEL_ORDER}.
 */
export interface NotificationPreferenceTypeView {
  type: NotificationType
  channels: NotificationChannel[]
  alwaysOn: NotificationChannel[]
}

/** Sort channels into the matrix's column order (unknown channels last, stable). */
function inColumnOrder(channels: readonly NotificationChannel[]): NotificationChannel[] {
  const rank = (channel: NotificationChannel): number => {
    const index = PREFERENCE_CHANNEL_ORDER.indexOf(channel)
    return index === -1 ? PREFERENCE_CHANNEL_ORDER.length : index
  }

  return [...channels].sort((a, b) => rank(a) - rank(b))
}

/** A prefs-UI group with the live types (for one recipient role) it contains. */
export interface NotificationPreferenceGroupView {
  group: NotificationPreferenceGroup
  types: NotificationPreferenceTypeView[]
}

/** Stable display order of the prefs groups. */
const PREFERENCE_GROUP_ORDER: readonly NotificationPreferenceGroup[] = [
  'assignment',
  // Jobs board sits next to assignments because it IS campaign work — the two
  // read as one story (a job is posted, applied to, answered; then the
  // assignment lifecycle takes over) — and ahead of the account/messaging
  // groups, which are about the user rather than the work.
  'jobs_board',
  'creator',
  'messaging',
]

/**
 * The prefs toggles a given recipient role can meaningfully set — only the live
 * types that actually TARGET this role (Ch3b honesty: no dead per-role control)
 * AND expose a preference row, grouped + ordered for the UI. Derived entirely
 * from {@link LIVE_TYPES}, so it can never list a type Ch3a can't render or omit
 * one the user receives and is allowed to silence.
 */
export function preferenceGroupsForRole(
  role: NotificationRecipientRole,
): NotificationPreferenceGroupView[] {
  const byGroup = new Map<NotificationPreferenceGroup, NotificationPreferenceTypeView[]>()

  for (const [type, definition] of Object.entries(LIVE_TYPES) as Array<
    [NotificationType, LiveNotificationType]
  >) {
    const preference = definition.preference
    // Always-on types are renderable but never offered as a toggle.
    if (preference === null) {
      continue
    }
    if (definition.recipient !== role && definition.recipient !== 'both') {
      continue
    }
    const existing = byGroup.get(preference.group) ?? []
    existing.push({
      type,
      channels: inColumnOrder(preference.channels),
      alwaysOn: inColumnOrder(preference.alwaysOnChannels ?? []),
    })
    byGroup.set(preference.group, existing)
  }

  return PREFERENCE_GROUP_ORDER.filter((group) => byGroup.has(group)).map((group) => ({
    group,
    types: byGroup.get(group) ?? [],
  }))
}

/**
 * The matrix columns for a set of groups: the union of every type's channels and
 * always-on channels, in {@link PREFERENCE_CHANNEL_ORDER}. Derived — never listed
 * by hand — so a column exists exactly when some row has a cell in it (no empty
 * column), and a new channel appears the moment one type lists it.
 */
export function preferenceColumns(
  groups: readonly NotificationPreferenceGroupView[],
): NotificationChannel[] {
  const present = new Set<NotificationChannel>()

  for (const group of groups) {
    for (const typeView of group.types) {
      typeView.channels.forEach((channel) => present.add(channel))
      typeView.alwaysOn.forEach((channel) => present.add(channel))
    }
  }

  return inColumnOrder([...present])
}

/**
 * Every type this registry considers live. Exported for the parity spec, which
 * pins it against the backend `NotificationType` union.
 */
export function liveNotificationTypes(): NotificationType[] {
  return Object.keys(LIVE_TYPES) as NotificationType[]
}
