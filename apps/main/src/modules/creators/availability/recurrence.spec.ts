/**
 * Unit tests for the weekly recurrence-rule helpers (Sprint 5 Chunk B,
 * D-b11/D-b12 spot-check anchors).
 *
 * Covers: the builder emits ONLY the backend-allowed weekly parts; and the
 * ⚠ UNTIL-instant is at/after the occurrence clock-time on the chosen day
 * so "ends on date X" actually includes date X (the midnight-boundary trap
 * carried from the Chunk-A note).
 */

import { DateTime } from 'luxon'
import { describe, expect, it } from 'vitest'

import { zonedToUtcIso } from './datetime'
import {
  buildWeeklyRule,
  parseWeeklyRule,
  untilInstant,
  untilToDate,
  WEEKDAY_CODES,
} from './recurrence'

const NY = 'America/New_York'

describe('buildWeeklyRule — emits only backend-allowed weekly parts', () => {
  it('is FREQ=WEEKLY alone for the simplest weekly rule', () => {
    expect(buildWeeklyRule({ interval: 1, byday: [], endsOn: null, zone: NY })).toBe('FREQ=WEEKLY')
  })

  it('adds INTERVAL only when > 1 (every N weeks)', () => {
    expect(buildWeeklyRule({ interval: 2, byday: [], endsOn: null, zone: NY })).toBe(
      'FREQ=WEEKLY;INTERVAL=2',
    )
  })

  it('adds BYDAY as plain comma-joined weekday codes', () => {
    expect(buildWeeklyRule({ interval: 1, byday: ['MO', 'WE'], endsOn: null, zone: NY })).toBe(
      'FREQ=WEEKLY;BYDAY=MO,WE',
    )
  })

  it('combines INTERVAL + BYDAY + UNTIL', () => {
    const rule = buildWeeklyRule({
      interval: 2,
      byday: ['MO', 'WE'],
      endsOn: '2026-07-31',
      zone: NY,
    })
    expect(rule).toMatch(/^FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;UNTIL=\d{8}T\d{6}Z$/)
  })

  it('never emits a forbidden part (no FREQ!=WEEKLY, COUNT, BYMONTHDAY, DTSTART)', () => {
    const rule = buildWeeklyRule({ interval: 3, byday: ['FR'], endsOn: '2026-09-01', zone: NY })
    expect(rule.startsWith('FREQ=WEEKLY')).toBe(true)
    expect(rule).not.toMatch(/COUNT|BYMONTHDAY|DTSTART|FREQ=(DAILY|MONTHLY|YEARLY)/)
  })

  it('exposes the seven Monday-first weekday codes', () => {
    expect(WEEKDAY_CODES).toEqual(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'])
  })
})

describe('untilInstant — D-b12: at/after the occurrence clock-time on date X', () => {
  it('is a UTC RRULE instant in basic format', () => {
    expect(untilInstant('2026-07-31', NY)).toMatch(/^\d{8}T\d{6}Z$/)
  })

  it('is >= an occurrence that starts at 09:00 on the chosen end date', () => {
    const until = untilInstant('2026-07-31', NY)
    expect(until).not.toBeNull()
    const untilMs = DateTime.fromFormat(until as string, "yyyyMMdd'T'HHmmss'Z'", {
      zone: 'utc',
    }).toMillis()
    // An occurrence at 09:00 NY on the SAME chosen day must NOT be excluded.
    const occurrenceMs = DateTime.fromISO(zonedToUtcIso('2026-07-31', '09:00', NY)).toMillis()
    expect(untilMs).toBeGreaterThanOrEqual(occurrenceMs)
  })

  it('regression: a naive midnight UNTIL WOULD exclude a 09:00 occurrence (why end-of-day)', () => {
    // Demonstrates the trap the end-of-day choice avoids.
    const midnightMs = DateTime.fromISO(zonedToUtcIso('2026-07-31', '00:00', NY)).toMillis()
    const occurrenceMs = DateTime.fromISO(zonedToUtcIso('2026-07-31', '09:00', NY)).toMillis()
    expect(midnightMs).toBeLessThan(occurrenceMs) // midnight < 09:00 → would drop the day
  })
})

describe('parseWeeklyRule + untilToDate — round-trip for editing', () => {
  it('parses an existing rule into editable parts', () => {
    expect(parseWeeklyRule('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;UNTIL=20260731T235959Z')).toEqual({
      interval: 2,
      byday: ['MO', 'WE'],
      until: '20260731T235959Z',
    })
  })

  it('defaults INTERVAL to 1 and BYDAY to [] when absent', () => {
    expect(parseWeeklyRule('FREQ=WEEKLY')).toEqual({ interval: 1, byday: [], until: null })
  })

  it('round-trips the chosen end date through untilInstant → untilToDate', () => {
    const until = untilInstant('2026-07-31', NY) as string
    expect(untilToDate(until, NY)).toBe('2026-07-31')
  })
})
