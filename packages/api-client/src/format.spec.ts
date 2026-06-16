/**
 * Unit tests for the shared locale-aware format utilities (S9).
 *
 * Uses `Intl.NumberFormat` / `Intl.DateTimeFormat` as the independent ground
 * truth so assertions are not brittle to future ICU minor-format tweaks while
 * still being deterministic and locale-correct.
 */

import { describe, expect, it } from 'vitest'

import { formatCurrency, formatDate, formatDateTime } from './format'

// ---------------------------------------------------------------------------
// formatCurrency
// ---------------------------------------------------------------------------

describe('formatCurrency', () => {
  it('returns the fallback for null amounts', () => {
    expect(formatCurrency(null, 'EUR', 'en')).toBe('—')
    expect(formatCurrency(null, 'EUR', 'de')).toBe('—')
  })

  it('accepts a custom fallback string', () => {
    expect(formatCurrency(null, 'EUR', 'en', 'N/A')).toBe('N/A')
  })

  it('formats with Intl.NumberFormat style:currency for a valid code', () => {
    const expected = new Intl.NumberFormat('en', { style: 'currency', currency: 'EUR' }).format(
      12.34,
    )
    expect(formatCurrency(1234, 'EUR', 'en')).toBe(expected)
  })

  it('produces locale-native output — de places the symbol differently', () => {
    const en = formatCurrency(1234, 'EUR', 'en')
    const de = formatCurrency(1234, 'EUR', 'de')
    // The formatted strings differ between locales.
    expect(en).not.toBe(de)
    // Both contain the numeric value.
    expect(en).toMatch(/12/)
    expect(de).toMatch(/12/)
  })

  it('falls back to plain number formatting when currency is null', () => {
    const result = formatCurrency(1234, null, 'en')
    expect(result).toContain('12.34')
    expect(result).not.toMatch(/[€$£]/)
  })

  it('falls back to plain number formatting when currency is undefined', () => {
    const result = formatCurrency(1234, undefined, 'en')
    expect(result).toContain('12.34')
  })

  it('handles zero correctly', () => {
    const result = formatCurrency(0, 'EUR', 'en')
    expect(result).toContain('0')
    expect(result).toContain('0.00')
  })

  it('handles large amounts', () => {
    // 100 000 EUR = 10_000_000 minor units
    const result = formatCurrency(10_000_000, 'EUR', 'en')
    expect(result).toContain('100')
    expect(result).toContain('000')
  })

  it('degrades gracefully for an invalid currency code (no throw)', () => {
    expect(() => formatCurrency(1234, 'XYZ_BAD', 'en')).not.toThrow()
  })
})

// ---------------------------------------------------------------------------
// formatDate
// ---------------------------------------------------------------------------

describe('formatDate', () => {
  it('returns a non-empty string for a valid ISO date string', () => {
    const result = formatDate('2024-03-15', 'en')
    expect(result).toBeTruthy()
    expect(result.length).toBeGreaterThan(0)
  })

  it('matches the Intl.DateTimeFormat ground truth for en', () => {
    const expected = new Intl.DateTimeFormat('en', { dateStyle: 'medium' }).format(
      new Date('2024-03-15'),
    )
    expect(formatDate('2024-03-15', 'en')).toBe(expected)
  })

  it('matches the Intl.DateTimeFormat ground truth for de', () => {
    const expected = new Intl.DateTimeFormat('de', { dateStyle: 'medium' }).format(
      new Date('2024-03-15'),
    )
    expect(formatDate('2024-03-15', 'de')).toBe(expected)
  })

  it('produces locale-native output (en vs de differ)', () => {
    const en = formatDate('2024-03-15', 'en')
    const de = formatDate('2024-03-15', 'de')
    expect(en).not.toBe(de)
  })

  it('accepts a Date object directly', () => {
    const d = new Date('2024-06-01T00:00:00Z')
    const fromDate = formatDate(d, 'en')
    const fromString = formatDate('2024-06-01T00:00:00Z', 'en')
    expect(fromDate).toBe(fromString)
  })

  it('respects custom Intl.DateTimeFormatOptions', () => {
    const result = formatDate('2024-03-15', 'en', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    })
    // "Friday, March 15, 2024" — contains the weekday and year
    expect(result).toMatch(/2024/)
    expect(result.length).toBeGreaterThan(10)
  })
})

// ---------------------------------------------------------------------------
// formatDateTime
// ---------------------------------------------------------------------------

describe('formatDateTime', () => {
  it('returns the fallback for null', () => {
    expect(formatDateTime(null, 'en')).toBe('—')
    expect(formatDateTime(null, 'de')).toBe('—')
  })

  it('accepts a custom fallback string', () => {
    expect(formatDateTime(null, 'en', undefined, 'n/a')).toBe('n/a')
  })

  it('matches the Intl.DateTimeFormat ground truth for a non-null input', () => {
    const iso = '2024-03-15T10:30:00.000Z'
    const expected = new Intl.DateTimeFormat('en', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(iso))
    expect(formatDateTime(iso, 'en')).toBe(expected)
  })

  it('produces locale-native output (en vs fr differ)', () => {
    const iso = '2024-03-15T10:30:00.000Z'
    const en = formatDateTime(iso, 'en')
    const fr = formatDateTime(iso, 'fr')
    expect(en).not.toBe(fr)
  })

  it('accepts a Date object directly', () => {
    const d = new Date('2024-06-01T12:00:00Z')
    const fromDate = formatDateTime(d, 'en')
    const fromString = formatDateTime('2024-06-01T12:00:00Z', 'en')
    expect(fromDate).toBe(fromString)
  })

  it('respects custom Intl.DateTimeFormatOptions', () => {
    const iso = '2024-03-15T10:30:00.000Z'
    const result = formatDateTime(iso, 'en', { dateStyle: 'short' })
    const expected = new Intl.DateTimeFormat('en', { dateStyle: 'short' }).format(new Date(iso))
    expect(result).toBe(expected)
  })
})
