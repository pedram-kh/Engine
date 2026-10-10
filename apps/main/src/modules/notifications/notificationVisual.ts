/**
 * What a notification row looks like in the native app (AH-100d, Figma frame 09):
 * a tinted type-icon disc on the left and a short relative time on the right.
 * Both come from fields the feed already carries — `notification_type` and
 * `created_at` — so no API shape is involved.
 *
 * Deliberately not here: the frame's title/subtitle split ("New offer: …" over
 * "€25.00 per video · Tap to review"). The feed renders one body string from a
 * client template, so a second line needs a per-type subtitle template and the
 * data to fill it — deferred, not guessed at.
 */

import type { NotificationType } from '@catalyst/api-client'

export type NotificationTone = 'accent' | 'success' | 'info' | 'danger' | 'neutral'

export interface NotificationVisual {
  icon: string
  tone: NotificationTone
}

const FALLBACK: NotificationVisual = { icon: 'mdi-bell-outline', tone: 'neutral' }

const VISUALS: Partial<Record<NotificationType, NotificationVisual>> = {
  // An offer (frame 09: gift, info).
  'assignment.invited': { icon: 'mdi-gift-outline', tone: 'info' },
  'campaign.job_posted': { icon: 'mdi-briefcase-outline', tone: 'info' },
  // Account decisions (frame 09: check-circle, success).
  'creator.approved': { icon: 'mdi-check-circle-outline', tone: 'success' },
  'creator.rejected': { icon: 'mdi-close-circle-outline', tone: 'danger' },
  // Agency connection (frame 09: link, accent).
  'agency_creator_relation.admin_connected': { icon: 'mdi-link-variant', tone: 'accent' },
  'agency_creator_relation.disconnected': { icon: 'mdi-link-variant-off', tone: 'neutral' },
  // Assignment lifecycle.
  'assignment.accepted': { icon: 'mdi-check-circle-outline', tone: 'success' },
  'assignment.contracted': { icon: 'mdi-file-document-check-outline', tone: 'success' },
  'assignment.draft_approved': { icon: 'mdi-check-circle-outline', tone: 'success' },
  'assignment.completed_on_approval': { icon: 'mdi-check-circle-outline', tone: 'success' },
  'assignment.manually_verified': { icon: 'mdi-check-circle-outline', tone: 'success' },
  'assignment.live_verified': { icon: 'mdi-check-circle-outline', tone: 'success' },
  'assignment.declined': { icon: 'mdi-close-circle-outline', tone: 'danger' },
  'assignment.draft_rejected': { icon: 'mdi-close-circle-outline', tone: 'danger' },
  'assignment.cancelled': { icon: 'mdi-close-circle-outline', tone: 'danger' },
  'assignment.countered': { icon: 'mdi-swap-horizontal', tone: 'info' },
  'assignment.draft_submitted': { icon: 'mdi-upload-outline', tone: 'info' },
  'assignment.revision_requested': { icon: 'mdi-pencil-outline', tone: 'info' },
  'assignment.payment_funded': { icon: 'mdi-cash', tone: 'success' },
  'assignment.payment_released': { icon: 'mdi-cash-check', tone: 'success' },
  // Messages.
  'message.received_by_creator': { icon: 'mdi-message-outline', tone: 'accent' },
  'message.received_by_agency': { icon: 'mdi-message-outline', tone: 'accent' },
  'message.relationship_received_by_creator': { icon: 'mdi-message-outline', tone: 'accent' },
  'message.relationship_received_by_agency': { icon: 'mdi-message-outline', tone: 'accent' },
  // Job-board applications.
  'campaign_application.submitted': { icon: 'mdi-briefcase-outline', tone: 'info' },
  'campaign_application.accepted': { icon: 'mdi-check-circle-outline', tone: 'success' },
  'campaign_application.rejected': { icon: 'mdi-close-circle-outline', tone: 'danger' },
}

/** The icon + tone for a type; an unknown type (the wire may send one) is a neutral bell. */
export function notificationVisual(type: string): NotificationVisual {
  return VISUALS[type as NotificationType] ?? FALLBACK
}

const MINUTE = 60_000
const HOUR = 60 * MINUTE
const DAY = 24 * HOUR

/**
 * The frame's compact age: "5m", "2h", "1d", then a short date once it is a
 * week old. Units are localised by `Intl` (narrow unit display), so there is no
 * English string to translate.
 */
export function compactAge(iso: string, locale: string, now: number = Date.now()): string {
  const then = Date.parse(iso)
  if (Number.isNaN(then)) return ''
  const elapsed = Math.max(0, now - then)

  const unit = (value: number, name: 'minute' | 'hour' | 'day'): string =>
    new Intl.NumberFormat(locale, {
      style: 'unit',
      unit: name,
      unitDisplay: 'narrow',
      maximumFractionDigits: 0,
    }).format(value)

  if (elapsed < HOUR) return unit(Math.max(1, Math.floor(elapsed / MINUTE)), 'minute')
  if (elapsed < DAY) return unit(Math.floor(elapsed / HOUR), 'hour')
  if (elapsed < 7 * DAY) return unit(Math.floor(elapsed / DAY), 'day')
  return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(then)
}
