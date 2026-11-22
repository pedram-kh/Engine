/**
 * PL-1.3 — how the tracker writes dates and groups steps. Pure functions, no Vue.
 *
 * Dates are plain `Y-m-d` strings and are never put through a time zone: "today" comes from the server (the
 * requester's own day), and a date is formatted from its parts as UTC so the day never shifts.
 */
import type { WaveStepResource, WaveStream } from '@catalyst/api-client'

export const STREAM_ORDER: readonly WaveStream[] = ['ugc_video', 'statics', 'stills']

export type TrackerFilter = 'all' | WaveStream | 'mine'

function parts(ymd: string): { y: number; m: number; d: number } {
  const [y, m, d] = ymd.split('-').map((n) => Number(n))
  return { y: y ?? 0, m: m ?? 1, d: d ?? 1 }
}

function utc(ymd: string): Date {
  const { y, m, d } = parts(ymd)
  return new Date(Date.UTC(y, m - 1, d))
}

/** Whole days from `from` to `to` (`to` later = positive). */
export function dayDiff(from: string, to: string): number {
  return Math.round((utc(to).getTime() - utc(from).getTime()) / 86_400_000)
}

function fmt(ymd: string, locale: string, opts: Intl.DateTimeFormatOptions): string {
  return new Intl.DateTimeFormat(locale, { ...opts, timeZone: 'UTC' }).format(utc(ymd))
}

/** "Oct 22", or "Oct 22, 2027" when the year is not the current one. */
export function shortDate(ymd: string, today: string, locale: string): string {
  const sameYear = parts(ymd).y === parts(today).y
  return fmt(ymd, locale, sameYear ? { month: 'short', day: 'numeric' } : { dateStyle: 'medium' })
}

export interface DueWords {
  today: string
  tomorrow: string
}

/**
 * The Due column. Today and Tomorrow as words; the rest of the coming week with the weekday ("Mon, Oct 19");
 * anything else as "Oct 22" (with the year when it is not this year). When a start date also exists the cell is
 * a range, "Oct 16 → 19" (the day only when the month is the same).
 */
export function formatDue(
  startsOn: string | null,
  dueOn: string | null,
  today: string,
  locale: string,
  words: DueWords,
): string | null {
  if (dueOn === null) {
    return startsOn === null ? null : shortDate(startsOn, today, locale)
  }
  if (startsOn !== null && startsOn !== dueOn) {
    const a = parts(startsOn)
    const b = parts(dueOn)
    const end =
      a.y === b.y && a.m === b.m
        ? fmt(dueOn, locale, { day: 'numeric' })
        : shortDate(dueOn, today, locale)
    return `${shortDate(startsOn, today, locale)} → ${end}`
  }
  const diff = dayDiff(today, dueOn)
  if (diff === 0) return words.today
  if (diff === 1) return words.tomorrow
  if (diff > 1 && diff < 7) {
    const sameYear = parts(dueOn).y === parts(today).y
    return fmt(dueOn, locale, {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      ...(sameYear ? {} : { year: 'numeric' }),
    })
  }
  return shortDate(dueOn, today, locale)
}

export function firstName(name: string): string {
  return name.trim().split(/\s+/)[0] ?? name
}

function isHandBeyond(s: WaveStepResource): boolean {
  return s.attributes.strategy?.hand_set_beyond === true
}

export interface StreamGroup {
  stream: WaveStream
  open: WaveStepResource[]
  completed: WaveStepResource[]
}

/** Steps of the present streams, grouped, in stream then position order, after the filter. */
export function groupSteps(
  steps: readonly WaveStepResource[],
  filter: TrackerFilter,
  meUlid: string | null,
): StreamGroup[] {
  const groups: StreamGroup[] = []
  for (const stream of STREAM_ORDER) {
    const all = steps.filter((s) => s.attributes.stream === stream)
    if (all.length === 0) continue
    if (filter !== 'all' && filter !== 'mine' && filter !== stream) continue
    const shown =
      filter === 'mine'
        ? all.filter((s) => meUlid !== null && s.attributes.owner?.id === meUlid)
        : all
    if (filter === 'mine' && shown.length === 0) continue
    groups.push({
      stream,
      // PL-2.5: a row set to Done by hand beyond what its strategy step has reached is not folded away: its
      // "set by hand" marker has to stay in sight. (`strategy` exists only while strategy is on.)
      open: shown.filter((s) => s.attributes.status !== 'done' || isHandBeyond(s)),
      completed: shown.filter((s) => s.attributes.status === 'done' && !isHandBeyond(s)),
    })
  }
  return groups
}

/** First and last completed step (by position) and the span of their `completed_on` dates. */
export function completedSummary(completed: readonly WaveStepResource[]): {
  first: string
  last: string
  from: string | null
  to: string | null
} {
  const dates = completed
    .map((s) => s.attributes.completed_on)
    .filter((d): d is string => d !== null)
    .sort()
  return {
    first: completed[0]?.attributes.name ?? '',
    last: completed[completed.length - 1]?.attributes.name ?? '',
    from: dates[0] ?? null,
    to: dates[dates.length - 1] ?? null,
  }
}
