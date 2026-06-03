/**
 * Timezone + calendar date helpers for the creator availability UI
 * (Sprint 5 Chunk B, D-b3/D-b7).
 *
 * Luxon is the tz engine. The contract for the whole calendar:
 *   - The API speaks ISO 8601 UTC instants.
 *   - The creator sees/edits everything in their RESOLVED timezone
 *     (`users.timezone`, falling back to the browser tz when null).
 *   - Reads convert UTC → resolved tz; writes convert resolved tz → UTC.
 *
 * These functions are pure (no Vue, no store) so they unit-test directly
 * with an explicit `zone` argument — the tz round-trip is the whole game
 * for a calendar, so it gets covered in isolation.
 */

import { DateTime } from 'luxon'

/** The browser's IANA timezone (e.g. `'Europe/Lisbon'`), or UTC as a last resort. */
export function browserTimezone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
}

/**
 * Resolve the timezone to render in: the creator's stored tz, or the
 * browser tz when it is null/undefined (D-b7).
 */
export function resolveTimezone(userTimezone: string | null | undefined): string {
  return userTimezone ?? browserTimezone()
}

export interface ZonedDateTimeParts {
  /** `'YYYY-MM-DD'` in the target zone. */
  date: string
  /** `'HH:mm'` (24-hour) in the target zone. */
  time: string
}

/** Split a UTC ISO instant into `{ date, time }` in the target zone (for form fields). */
export function utcIsoToZoned(iso: string, zone: string): ZonedDateTimeParts {
  const dt = DateTime.fromISO(iso).setZone(zone)
  return { date: dt.toISODate() ?? '', time: dt.toFormat('HH:mm') }
}

/** Combine a zoned `date` + `time` back into a UTC ISO instant (for write payloads). */
export function zonedToUtcIso(date: string, time: string, zone: string): string {
  const dt = DateTime.fromISO(`${date}T${time}`, { zone })
  return dt.toUTC().toISO({ suppressMilliseconds: true }) ?? ''
}

/** Just the `'HH:mm'` clock time of a UTC instant in the target zone (for chips). */
export function zonedTime(iso: string, zone: string): string {
  return DateTime.fromISO(iso).setZone(zone).toFormat('HH:mm')
}

/**
 * The set of `'YYYY-MM-DD'` day keys (in `zone`) an occurrence covers,
 * END-EXCLUSIVE at midnight: a block `00:00 → next-day 00:00` paints
 * exactly one day, and a multi-day block paints each spanned day. This is
 * DAY-LEVEL rendering only — never intra-day lane math (that is the
 * deferred week view; D-b1 / honest-deviation trigger).
 */
export function eachDayKey(startIso: string, endIso: string, zone: string): string[] {
  const start = DateTime.fromISO(startIso).setZone(zone).startOf('day')
  const end = DateTime.fromISO(endIso).setZone(zone)

  let last = end.startOf('day')
  // End-exclusive: an end exactly on midnight does NOT paint that day.
  if (+end === +last) {
    last = last.minus({ days: 1 })
  }
  if (+last < +start) {
    last = start
  }

  const out: string[] = []
  for (let cur = start; +cur <= +last; cur = cur.plus({ days: 1 })) {
    const key = cur.toISODate()
    if (key !== null) {
      out.push(key)
    }
  }
  return out
}

/**
 * The UTC `{ from, to }` window to request for a displayed month. Padded
 * generously around the month so the full 6×7 grid (which bleeds into the
 * adjacent months) is covered — well within the backend's 366-day clamp.
 * The caller still renders from `meta.window` (D-b6) and buckets by day
 * key, so over-fetching a few days is harmless.
 */
export function monthQueryWindow(
  year: number,
  month: number,
  zone: string,
): { from: string; to: string } {
  const first = DateTime.fromObject({ year, month, day: 1 }, { zone }).startOf('day')
  const from = first.minus({ days: 7 })
  const to = first.plus({ months: 1 }).plus({ days: 14 })
  return {
    from: from.toUTC().toISO({ suppressMilliseconds: true }) ?? '',
    to: to.toUTC().toISO({ suppressMilliseconds: true }) ?? '',
  }
}

/** Localized `"June 2026"`-style month + year header. */
export function monthLabel(year: number, month: number, locale: string): string {
  return DateTime.fromObject({ year, month, day: 1 }).setLocale(locale).toFormat('LLLL yyyy')
}

/**
 * Seven localized short weekday labels in display order. `weekStartsOn`
 * 1 = Monday-first (default; en/pt/it lean EU), 0 = Sunday-first.
 */
export function weekdayLabels(locale: string, weekStartsOn: 0 | 1 = 1): string[] {
  // 2024-01-01 is a Monday; 2023-12-31 is the preceding Sunday.
  const anchor =
    weekStartsOn === 1 ? DateTime.fromISO('2024-01-01') : DateTime.fromISO('2023-12-31')
  return Array.from({ length: 7 }, (_, i) =>
    anchor.plus({ days: i }).setLocale(locale).toFormat('ccc'),
  )
}

/** `'YYYY-MM-DD'` for "today" in the target zone (drives CMonthGrid's today marker). */
export function todayKey(zone: string): string {
  return DateTime.now().setZone(zone).toISODate() ?? ''
}

/** Shift a `'YYYY-MM-DD'` key by `n` calendar days (tz-agnostic; pure date math). */
export function addDays(dateKey: string, n: number): string {
  const dt = DateTime.fromISO(dateKey)
  return dt.plus({ days: n }).toISODate() ?? dateKey
}
