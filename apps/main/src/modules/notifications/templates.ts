/**
 * The notification body-template registry (S11.0 Ch3a, D-6).
 *
 * The body text is never on the wire — it renders client-side from
 * `notification_type` + the row's `data` bag. This map is the SINGLE place that
 * decides which i18n template a type renders through, and it is deliberately
 * PARTIAL: only the 8 notification types that have a LIVE emit site (Ch1/Ch2)
 * are mapped. Everything else — the forward-declared deferred-S10 payment
 * verbs, the lifecycle verbs still awaiting an emitter, AND any string the
 * backend might send that the FE union doesn't even know about — resolves to
 * the generic `fallback` template.
 *
 * Making the allowlist a STRUCTURAL FACT (unmapped → fallback) rather than a
 * convention means a future type can never throw a missing-i18n-key error, and
 * "template only the live 8 + fallback" is enforced by construction, not by
 * reviewer vigilance.
 *
 * ⚠ Per-template data binding: each template interpolates ONLY the keys its
 * emit site actually sends (verified against `SendAssignmentNotifications` +
 * `AdminCreatorController`):
 *   - creator-facing review rows carry campaign_name / creator_name / outcome /
 *     feedback / assignment_ulid;
 *   - agency fan-out rows (draft_submitted / contracted) carry creator_name /
 *     campaign_name / campaign_ulid — NOT assignment_ulid;
 *   - creator.approved's data may be `{}` → its template requires NO param;
 *   - creator.rejected carries rejection_reason (rendered as a detail line,
 *     not interpolated into the title).
 * No param is universal, so every template is written to read correctly with a
 * null actor and with only its own keys present.
 */

import type { NotificationType } from '@catalyst/api-client'

const FALLBACK_KEY = 'notifications.types.fallback'

/**
 * notification_type (dotted) → flat i18n key under `notifications.types.*`.
 * The dotted enum values can't be `t()`-pathed directly (vue-i18n would treat
 * the dot as nesting), so the flat-underscore key precedent
 * (assignmentStatus/draftStatus) applies; this map is also the only-8 allowlist.
 */
const LIVE_TEMPLATE_KEYS: Partial<Record<NotificationType, string>> = {
  'assignment.draft_approved': 'notifications.types.assignment_draft_approved',
  'assignment.revision_requested': 'notifications.types.assignment_revision_requested',
  'assignment.draft_rejected': 'notifications.types.assignment_draft_rejected',
  'assignment.manually_verified': 'notifications.types.assignment_manually_verified',
  'assignment.draft_submitted': 'notifications.types.assignment_draft_submitted',
  'assignment.contracted': 'notifications.types.assignment_contracted',
  'creator.approved': 'notifications.types.creator_approved',
  'creator.rejected': 'notifications.types.creator_rejected',
}

/**
 * Resolve the i18n key for a notification's body. Accepts a plain `string`
 * (not just `NotificationType`) on purpose: a genuinely unknown type the
 * backend might add later is still routed to the fallback rather than throwing.
 */
export function notificationTemplateKey(type: string): string {
  return LIVE_TEMPLATE_KEYS[type as NotificationType] ?? FALLBACK_KEY
}

/** True when a type has a bespoke (non-fallback) template. Exported for tests. */
export function hasLiveTemplate(type: string): boolean {
  return Object.prototype.hasOwnProperty.call(LIVE_TEMPLATE_KEYS, type)
}

export const NOTIFICATION_FALLBACK_KEY = FALLBACK_KEY
