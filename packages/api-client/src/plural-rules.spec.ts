/**
 * Rules-correctness test for the plural registry (S3).
 *
 * `PLURAL_CATEGORIES` is a hand-authored SOT, so it needs an independent
 * anchor. That anchor is `Intl.PluralRules` (ICU/CLDR) — the same vetted
 * engine `buildPluralRules` delegates to. This spec:
 *
 *   1. probes `Intl.PluralRules` over a wide numeric sample per locale and
 *      asserts the categories it can produce match the SOT EXACTLY (set +
 *      canonical order). If a future ICU bump changes a locale's rules,
 *      this fails and forces a deliberate SOT review.
 *   2. pins structural invariants (24 entries, `other` present + last).
 *   3. checks `buildPluralRules` returns the right index for golden cases
 *      in the linguistically interesting locales (one/few/many/other,
 *      zero, five-form), and that clamping degrades gracefully.
 */

import { describe, expect, it } from 'vitest'

import { EU_LANGUAGES, type EuLanguage } from './locales'
import {
  CATEGORY_ORDER,
  PLURAL_CATEGORIES,
  buildPluralRules,
  pluralFormCount,
  type PluralCategory,
} from './plural-rules'

/** A representative cardinal sample: every category for every EU locale is
 * reachable within this range plus the large/decimal tail. */
function sampleNumbers(): number[] {
  const xs: number[] = []
  for (let i = 0; i <= 120; i++) xs.push(i)
  for (const x of [0.5, 1.5, 2.5, 1.1, 2.07, 1000, 10000, 100000, 1000000, 1000001, 2000000]) {
    xs.push(x)
  }
  return xs
}

/** The categories `Intl.PluralRules` actually produces for a locale, in
 * canonical order — the ground truth the SOT is pinned against. */
function probeCategories(locale: string): PluralCategory[] {
  const pr = new Intl.PluralRules(locale)
  const seen = new Set<string>()
  for (const n of sampleNumbers()) seen.add(pr.select(n))
  return CATEGORY_ORDER.filter((c) => seen.has(c))
}

describe('plural registry — SOT vs Intl.PluralRules (ICU/CLDR)', () => {
  it.each(EU_LANGUAGES)('locale %s matches the runtime CLDR categories exactly', (locale) => {
    expect(PLURAL_CATEGORIES[locale]).toEqual(probeCategories(locale))
  })

  it('covers every EU language with no extras', () => {
    expect(Object.keys(PLURAL_CATEGORIES).sort()).toEqual([...EU_LANGUAGES].sort())
  })

  it('always ends in `other`, the catch-all', () => {
    for (const locale of EU_LANGUAGES) {
      const cats = PLURAL_CATEGORIES[locale]
      expect(cats[cats.length - 1]).toBe('other')
      expect(cats).toContain('other')
    }
  })

  it('has no duplicate categories and keeps canonical order', () => {
    for (const locale of EU_LANGUAGES) {
      const cats = PLURAL_CATEGORIES[locale]
      expect(new Set(cats).size).toBe(cats.length)
      const ordered = CATEGORY_ORDER.filter((c) => cats.includes(c))
      expect(cats).toEqual(ordered)
    }
  })

  it('pluralFormCount mirrors the category list length (en fallback for unknown)', () => {
    for (const locale of EU_LANGUAGES) {
      expect(pluralFormCount(locale)).toBe(PLURAL_CATEGORIES[locale].length)
    }
    expect(pluralFormCount('xx')).toBe(2)
  })
})

describe('buildPluralRules — index selection', () => {
  const rules = buildPluralRules()

  it('exposes a rule for every EU language', () => {
    expect(Object.keys(rules).sort()).toEqual([...EU_LANGUAGES].sort())
  })

  /** Golden cases hand-derived from CLDR, independent of the SOT order:
   * `[locale, count, expectedCategory]`. The rule must return the index of
   * that category in the (full-form) message. */
  const golden: Array<[EuLanguage, number, PluralCategory]> = [
    ['en', 1, 'one'],
    ['en', 0, 'other'],
    ['en', 5, 'other'],
    ['pl', 1, 'one'],
    ['pl', 2, 'few'],
    ['pl', 5, 'many'],
    ['pl', 1.5, 'other'],
    ['cs', 1, 'one'],
    ['cs', 3, 'few'],
    ['cs', 1.5, 'many'], // Czech `many` is the fractional category, not large integers
    ['cs', 8, 'other'],
    ['ro', 1, 'one'],
    ['ro', 2, 'few'],
    ['ro', 20, 'other'],
    ['lv', 0, 'zero'],
    ['lv', 1, 'one'],
    ['lv', 5, 'other'],
    ['sl', 1, 'one'],
    ['sl', 2, 'two'],
    ['sl', 3, 'few'],
    ['sl', 5, 'other'],
    ['ga', 1, 'one'],
    ['ga', 2, 'two'],
    ['ga', 3, 'few'],
    ['ga', 7, 'many'],
    ['ga', 11, 'other'],
  ]

  it.each(golden)('rule(%s, %d) selects the %s form', (locale, count, category) => {
    const order = PLURAL_CATEGORIES[locale]
    const expectedIndex = order.indexOf(category)
    expect(expectedIndex).toBeGreaterThanOrEqual(0)
    expect(rules[locale]!(count, order.length)).toBe(expectedIndex)
  })

  it('clamps to the forms actually authored (degrades to the last form)', () => {
    // Polish has 4 categories; a 2-form message must never index past 1.
    const pl = rules.pl!
    expect(pl(1, 2)).toBe(0) // one -> form 0
    expect(pl(2, 2)).toBe(1) // few(2) clamped -> form 1
    expect(pl(5, 2)).toBe(1) // many(2) clamped -> form 1
  })

  it('returns the single form when only one is provided', () => {
    for (const locale of EU_LANGUAGES) {
      expect(rules[locale]!(5, 1)).toBe(0)
    }
  })
})
