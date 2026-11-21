/**
 * PL-2.3 — the rules of step 4 in one place, with no Vue in it, so the screens and the specs read the same numbers.
 * Everything derives from the concept rows (the summary the server sends): nothing is stored and nothing here ever
 * locks a step.
 */
import type {
  ConceptMarketCounts,
  ConceptsSummary,
  ConceptStatus,
  FunnelStage,
} from '@catalyst/api-client'

export const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'] as const

/** The note a stage pre-fills when the note is empty or still the previous default (the frame's three samples). */
export const FUNNEL_DEFAULT_NOTES: Record<FunnelStage, string> = {
  tofu: 'awareness',
  mofu: 'consideration',
  bofu: 'hard sell',
}
export const FUNNEL_STAGES: FunnelStage[] = ['tofu', 'mofu', 'bofu']

export function marketCounts(
  concepts: ConceptsSummary | undefined,
  market: string,
): ConceptMarketCounts | null {
  return concepts?.by_market.find((m) => m.market === market) ?? null
}

/** A market is complete when it has at least one concept and every one is approved. */
export function isMarketComplete(counts: Pick<ConceptMarketCounts, 'total' | 'approved'>): boolean {
  return counts.total > 0 && counts.approved === counts.total
}

/**
 * The banner of a market: none when it has no concept, green when every concept is approved, amber otherwise.
 */
export type BannerState = 'none' | 'amber' | 'green'
export function bannerState(counts: ConceptMarketCounts | null): BannerState {
  if (counts === null || counts.total === 0) return 'none'
  return isMarketComplete(counts) ? 'green' : 'amber'
}

/** True for one or two concepts: the soft "three or more are recommended" line (never for none, never a block). */
export function showUnderMinimum(counts: ConceptMarketCounts | null, minimum: number): boolean {
  return counts !== null && counts.total > 0 && counts.total < minimum
}

/**
 * Steps 5 and 6 carry a warning only while there is something to say about concepts: the wave has no concept at all
 * ("no concepts yet"), or at least one concept is not approved ("{approved} of {total} approved"; Awaiting client and
 * Changes requested both count as not approved). The counts are the wave's, across every market. A market that has no
 * concept of its own is not counted against the others: when every concept that exists is approved there is no warning,
 * whatever the other markets hold. It is a message, never a block.
 */
export type StepWarning =
  | { kind: 'none' }
  | { kind: 'empty' }
  | { kind: 'counts'; approved: number; total: number }

export function stepWarning(concepts: ConceptsSummary | undefined): StepWarning {
  if (concepts === undefined) return { kind: 'none' }
  if (concepts.total === 0) return { kind: 'empty' }
  if (concepts.approved < concepts.total) {
    return { kind: 'counts', approved: concepts.approved, total: concepts.total }
  }
  return { kind: 'none' }
}

/** Step 4's own line: "{approved} of {total} approved", or "No concepts yet". */
export function stepFourLine(
  concepts: ConceptsSummary | undefined,
): { kind: 'empty' } | { kind: 'counts'; approved: number; total: number } {
  if (concepts === undefined || concepts.total === 0) return { kind: 'empty' }
  return { kind: 'counts', approved: concepts.approved, total: concepts.total }
}

/** Only an `https` URL may become an href (the server checks it too; a row written another way still renders as text). */
export function safeHref(url: string): string | null {
  return /^https:\/\//i.test(url.trim()) && !/[\s\u0000-\u001f\u007f]/.test(url.trim())
    ? url.trim()
    : null
}

/** The same shape the server accepts, repeated only so the message can appear before any request. */
export function linkProblem(raw: string): 'empty' | 'scheme' | 'invalid' | null {
  const url = raw.trim()
  if (url === '') return 'empty'
  if (!/^https:\/\//i.test(url)) return /^[a-z][a-z0-9+.-]*:/i.test(url) ? 'scheme' : 'invalid'
  if (/[\s\u0000-\u001f\u007f]/.test(url) || url.length > 2048) return 'invalid'
  let parsed: URL
  try {
    parsed = new URL(url)
  } catch {
    return 'invalid'
  }
  const host = parsed.hostname.toLowerCase()
  if (parsed.username !== '' || parsed.password !== '') return 'invalid'
  if (host === 'localhost' || host.endsWith('.localhost')) return 'invalid'
  if (host.startsWith('[') || /^\d{1,3}(\.\d{1,3}){3}$/.test(host)) return 'invalid'
  if (!host.includes('.')) return 'invalid'
  return null
}

export function initialsOf(name: string): string {
  const parts = name
    .trim()
    .split(/\s+/)
    .filter((p) => p !== '')
  const first = Array.from(parts[0] ?? '')[0] ?? ''
  const last = parts.length > 1 ? (Array.from(parts[parts.length - 1] ?? '')[0] ?? '') : ''
  return (first + last).toUpperCase()
}

export function formatMegabytes(bytes: number): string {
  const mb = bytes / (1024 * 1024)
  return mb >= 10 || Number.isInteger(mb) ? String(Math.round(mb)) : mb.toFixed(1)
}

export interface UploadCandidate {
  name: string
  type: string
  size: number
}

export type UploadRefusal = 'type' | 'empty' | 'count' | 'bytes'

export interface UploadPlan {
  accepted: number[]
  refused: { index: number; reason: UploadRefusal }[]
}

/**
 * Decides, before any byte moves and before any request, which of the chosen files may start: the type (JPG, PNG or
 * WebP by the browser's type; the server reads the real bytes), a non-empty file, the 10-image count and the 300 MB
 * total, counting what is already stored plus the files accepted earlier in the same choice. The server repeats every
 * check and has the last word.
 */
export function planUploads(
  files: UploadCandidate[],
  used: { count: number; bytes: number },
  limits: { images: number; bytes: number },
): UploadPlan {
  const accepted: number[] = []
  const refused: UploadPlan['refused'] = []
  let count = used.count
  let bytes = used.bytes
  files.forEach((f, index) => {
    if (!(IMAGE_MIME_TYPES as readonly string[]).includes(f.type)) {
      refused.push({ index, reason: 'type' })
    } else if (f.size < 1) {
      refused.push({ index, reason: 'empty' })
    } else if (count >= limits.images) {
      refused.push({ index, reason: 'count' })
    } else if (bytes + f.size > limits.bytes) {
      refused.push({ index, reason: 'bytes' })
    } else {
      accepted.push(index)
      count += 1
      bytes += f.size
    }
  })
  return { accepted, refused }
}

export const STATUS_ORDER: ConceptStatus[] = ['approved', 'awaiting', 'changes_requested']

/**
 * The note after the stage changes: the new stage's default when the note is empty or is still the previous stage's
 * default; a note somebody typed is kept.
 */
export function nextFunnelNote(
  previousStage: FunnelStage | null,
  note: string | null,
  nextStage: FunnelStage | null,
): string | null {
  const wasDefault =
    note === null ||
    note.trim() === '' ||
    (previousStage !== null && note === FUNNEL_DEFAULT_NOTES[previousStage])
  if (!wasDefault) return note
  return nextStage === null ? null : FUNNEL_DEFAULT_NOTES[nextStage]
}
