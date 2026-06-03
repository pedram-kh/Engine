/**
 * Unit tests for the availability tz/date helpers (Sprint 5 Chunk B,
 * D-b7 spot-check anchor: tz round-trip + null fallback).
 *
 * Zones are passed explicitly (not the runner's local tz) so the assertions
 * are deterministic on any CI machine. The round-trip is asserted by epoch
 * comparison / inverse-function equality rather than string format, so the
 * tests don't couple to Luxon's ISO formatting choices.
 */

import { DateTime } from 'luxon'
import { describe, expect, it } from 'vitest'

import {
  addDays,
  browserTimezone,
  eachDayKey,
  monthLabel,
  monthQueryWindow,
  resolveTimezone,
  todayKey,
  utcIsoToZoned,
  weekdayLabels,
  zonedTime,
  zonedToUtcIso,
} from './datetime'

const NY = 'America/New_York' // UTC-4 in June (EDT)
const TOKYO = 'Asia/Tokyo' // UTC+9

describe('resolveTimezone (D-b7 null fallback)', () => {
  it('returns the creator tz when present', () => {
    expect(resolveTimezone('Europe/Lisbon')).toBe('Europe/Lisbon')
  })

  it('falls back to the browser tz when the creator tz is null/undefined', () => {
    expect(resolveTimezone(null)).toBe(browserTimezone())
    expect(resolveTimezone(undefined)).toBe(browserTimezone())
    expect(resolveTimezone(null)).not.toBe('')
  })
})

describe('utcIsoToZoned — renders a UTC instant in the target tz', () => {
  it('shifts the clock time into the zone', () => {
    expect(utcIsoToZoned('2026-06-15T13:30:00Z', NY)).toEqual({ date: '2026-06-15', time: '09:30' })
    expect(utcIsoToZoned('2026-06-15T13:30:00Z', TOKYO)).toEqual({
      date: '2026-06-15',
      time: '22:30',
    })
  })

  it('places an instant on the correct DAY for the zone (the day can shift)', () => {
    // 02:00 UTC is the previous evening in New York → previous calendar day.
    expect(utcIsoToZoned('2026-06-15T02:00:00Z', NY)).toEqual({ date: '2026-06-14', time: '22:00' })
  })
})

describe('zonedToUtcIso — converts creator input back to a UTC instant', () => {
  it('round-trips through utcIsoToZoned losslessly', () => {
    const iso = zonedToUtcIso('2026-06-15', '09:30', NY)
    expect(DateTime.fromISO(iso).toMillis()).toBe(
      DateTime.fromISO('2026-06-15T13:30:00Z').toMillis(),
    )
    expect(utcIsoToZoned(iso, NY)).toEqual({ date: '2026-06-15', time: '09:30' })
  })

  it('zonedTime reads the clock time of an instant in the zone', () => {
    expect(zonedTime('2026-06-15T13:30:00Z', NY)).toBe('09:30')
  })
})

describe('eachDayKey — day-level coverage, end-exclusive at midnight', () => {
  it('returns a single day for an intra-day timed block', () => {
    expect(eachDayKey('2026-06-15T13:00:00Z', '2026-06-15T14:00:00Z', NY)).toEqual(['2026-06-15'])
  })

  it('paints exactly one day for an all-day block (00:00 → next 00:00)', () => {
    // 04:00Z = 00:00 NY; next day 04:00Z = 00:00 NY → end-exclusive → one day.
    expect(eachDayKey('2026-06-15T04:00:00Z', '2026-06-16T04:00:00Z', NY)).toEqual(['2026-06-15'])
  })

  it('paints each spanned day for a multi-day block', () => {
    expect(eachDayKey('2026-06-15T04:00:00Z', '2026-06-18T04:00:00Z', NY)).toEqual([
      '2026-06-15',
      '2026-06-16',
      '2026-06-17',
    ])
  })

  it('buckets by the zone-local day, not the UTC day', () => {
    // 02:00–03:00 UTC is 22:00–23:00 the PREVIOUS day in NY.
    expect(eachDayKey('2026-06-15T02:00:00Z', '2026-06-15T03:00:00Z', NY)).toEqual(['2026-06-14'])
  })
})

describe('monthQueryWindow', () => {
  it('pads around the month and stays well within the 366-day clamp', () => {
    const { from, to } = monthQueryWindow(2026, 6, NY)
    const fromDt = DateTime.fromISO(from)
    const toDt = DateTime.fromISO(to)
    expect(fromDt.isValid).toBe(true)
    expect(toDt.isValid).toBe(true)
    expect(fromDt < toDt).toBe(true)
    // from is ~a week before June 1; to is ~mid-July.
    expect(fromDt.toUTC() < DateTime.fromISO('2026-06-01T00:00:00Z')).toBe(true)
    expect(toDt.diff(fromDt, 'days').days).toBeLessThan(60)
  })
})

describe('label + date helpers', () => {
  it('formats a localized month label', () => {
    expect(monthLabel(2026, 6, 'en')).toBe('June 2026')
  })

  it('orders weekday labels by weekStartsOn', () => {
    expect(weekdayLabels('en', 1)[0]).toBe('Mon')
    expect(weekdayLabels('en', 0)[0]).toBe('Sun')
    expect(weekdayLabels('en', 1)).toHaveLength(7)
  })

  it('addDays shifts a date key across month/year boundaries', () => {
    expect(addDays('2026-06-15', 1)).toBe('2026-06-16')
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
  })

  it('todayKey returns a YYYY-MM-DD key', () => {
    expect(todayKey('UTC')).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })
})
