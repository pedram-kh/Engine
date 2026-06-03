/**
 * Weekly recurrence-rule (RRULE) build/parse helpers for the availability
 * dialog (Sprint 5 Chunk B, D-b11/D-b12).
 *
 * The backend `WeeklyRecurrenceRule` accepts ONLY:
 *   FREQ=WEEKLY (required) + optional INTERVAL + optional BYDAY (plain
 *   MO..SU) + optional UNTIL.
 * Everything else (other FREQ, BYMONTHDAY, COUNT, numeric-prefixed BYDAY,
 * embedded DTSTART) is rejected. This builder emits only the allowed set.
 *
 * ⚠ D-b12 — the UNTIL instant. RRULE `UNTIL` is a precise datetime bound,
 * not a date. A midnight UNTIL silently EXCLUDES a same-day occurrence
 * whose clock-time is after midnight. So the "ends on date X" control
 * emits UNTIL at END-OF-DAY (in the creator's tz, converted to UTC) — at
 * or after any same-day occurrence — guaranteeing date X is included.
 * Nothing server-side compensates; this is the UI's responsibility.
 */

import { DateTime } from 'luxon'

/** Plain weekday codes, Monday-first, matching the backend allowlist. */
export const WEEKDAY_CODES = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as const

export type WeekdayCode = (typeof WEEKDAY_CODES)[number]

export interface WeeklyRuleInput {
  /** "every N weeks"; 1 = every week (INTERVAL omitted). */
  interval: number
  /** Selected weekday codes (BYDAY); empty = omit (recurs on DTSTART's weekday). */
  byday: readonly string[]
  /** "ends on" calendar date `'YYYY-MM-DD'` in `zone`, or null for no end. */
  endsOn: string | null
  /** The creator's resolved timezone, used to anchor the UNTIL instant. */
  zone: string
}

/**
 * Emit the UNTIL instant for an "ends on date X" choice: end-of-day in the
 * creator's tz, converted to UTC, in RRULE basic format
 * (`yyyyMMdd'T'HHmmss'Z'`). End-of-day (23:59:59) sits at or after any
 * same-day occurrence's clock-time, so date X is always included (D-b12).
 */
export function untilInstant(endsOnDate: string, zone: string): string | null {
  const dt = DateTime.fromISO(endsOnDate, { zone }).endOf('day')
  if (!dt.isValid) {
    return null
  }
  return dt.toUTC().toFormat("yyyyMMdd'T'HHmmss'Z'")
}

/** Build the weekly RRULE body from the dialog's recurrence state. */
export function buildWeeklyRule(input: WeeklyRuleInput): string {
  const segments = ['FREQ=WEEKLY']

  if (input.interval > 1) {
    segments.push(`INTERVAL=${input.interval}`)
  }
  if (input.byday.length > 0) {
    segments.push(`BYDAY=${input.byday.join(',')}`)
  }
  if (input.endsOn !== null && input.endsOn !== '') {
    const until = untilInstant(input.endsOn, input.zone)
    if (until !== null) {
      segments.push(`UNTIL=${until}`)
    }
  }

  return segments.join(';')
}

export interface ParsedWeeklyRule {
  interval: number
  byday: string[]
  /** Raw RRULE UNTIL token (e.g. `20260131T235959Z`), or null. */
  until: string | null
}

/** Parse an existing RRULE body back into editable builder state. */
export function parseWeeklyRule(rule: string): ParsedWeeklyRule {
  const parts: Record<string, string> = {}
  for (const segment of rule.split(';')) {
    const [key, value] = segment.split('=')
    if (key !== undefined && value !== undefined && key.trim() !== '' && value.trim() !== '') {
      parts[key.trim().toUpperCase()] = value.trim().toUpperCase()
    }
  }

  const intervalRaw = parts.INTERVAL !== undefined ? parseInt(parts.INTERVAL, 10) : 1
  const interval = Number.isFinite(intervalRaw) && intervalRaw >= 1 ? intervalRaw : 1
  const byday =
    parts.BYDAY !== undefined
      ? parts.BYDAY.split(',')
          .map((d) => d.trim())
          .filter((d) => d !== '')
      : []
  const until = parts.UNTIL ?? null

  return { interval, byday, until }
}

/**
 * Convert a raw RRULE UNTIL token back to the `'YYYY-MM-DD'` calendar date
 * the creator picked, in their tz (the inverse of {@link untilInstant} for
 * editing). Handles both the datetime form we emit and a bare date form.
 */
export function untilToDate(until: string, zone: string): string | null {
  const hasTime = until.includes('T')
  const isUtc = until.endsWith('Z')
  const format = hasTime ? (isUtc ? "yyyyMMdd'T'HHmmss'Z'" : "yyyyMMdd'T'HHmmss") : 'yyyyMMdd'
  const parsed = DateTime.fromFormat(until, format, { zone: isUtc ? 'utc' : zone })
  if (!parsed.isValid) {
    return null
  }
  return parsed.setZone(zone).toISODate()
}
