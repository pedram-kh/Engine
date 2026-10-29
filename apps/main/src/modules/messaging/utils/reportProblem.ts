/**
 * AH-112 (D8) — the "Report a problem" mailto, shared by the two creator thread surfaces.
 *
 * App Review guideline 1.2 expects a way to report objectionable content in user-to-user
 * messaging. This is the minimal one: a link that opens the user's mail client addressed to the
 * support inbox, with a subject that lets support find the conversation. Because a phone with no
 * mail account cannot open a mailto, the same address and reference are ALSO shown as plain,
 * selectable text with a copy button (see ReportFallback.vue), so the report never depends on a
 * mail client. The client holds no
 * thread ulid (`ThreadMetaLike` has none), so the subject carries the id the page is already
 * keyed by: the AGENCY ulid for a relationship thread, the ASSIGNMENT ulid for a campaign
 * thread. A fixed English prefix, a kind word and one ulid; never a name, address or message
 * text.
 */

import { SUPPORT_EMAIL } from '@/core/config/support'

export type ReportKind = 'relationship' | 'assignment'

export function reportSubject(kind: ReportKind, ulid: string): string {
  return `Catalyst app report: ${kind} ${ulid}`
}

export function reportHref(subject: string): string {
  return `mailto:${SUPPORT_EMAIL}?subject=${encodeURIComponent(subject)}`
}

/**
 * The reference shown to the user is the ulid the subject already carries: the last word of the
 * subject. The subject shape is pinned by `reportProblem.spec.ts`, so this cannot drift unseen.
 */
export function reportReference(subject: string): string {
  return subject.slice(subject.lastIndexOf(' ') + 1)
}

/**
 * What the copy button puts on the clipboard: the address and the reference, in a fixed English
 * form (support reads English), regardless of the app language.
 */
export function reportCopyText(reference: string): string {
  return `${SUPPORT_EMAIL} \u2014 reference: ${reference}`
}
