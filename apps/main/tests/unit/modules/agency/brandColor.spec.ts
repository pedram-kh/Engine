/**
 * AH-119 — `effectiveBrandColor`: a brand's own palette key wins; a brand with none (every
 * existing brand, A4) gets one derived from its id, the same one every time, and the derivation
 * spreads across the palette rather than collapsing onto one key.
 */

import { describe, expect, it } from 'vitest'

import {
  BRAND_COLOR_KEYS,
  brandColorVar,
  effectiveBrandColor,
} from '@/modules/agency/composables/brandColor'

describe('effectiveBrandColor', () => {
  it('returns the brand own key when it is a palette key', () => {
    expect(effectiveBrandColor('amber', 'any-id')).toBe('amber')
  })

  it('derives a palette key from the id when the brand has none, or has an unknown one', () => {
    for (const own of [null, undefined, '', 'chartreuse']) {
      expect(BRAND_COLOR_KEYS).toContain(effectiveBrandColor(own, '01HBRANDX'))
    }
  })

  it('is deterministic for an id', () => {
    expect(effectiveBrandColor(null, '01HBRANDSTABLE')).toBe(
      effectiveBrandColor(null, '01HBRANDSTABLE'),
    )
  })

  it('spreads across the palette', () => {
    const seen = new Set(
      Array.from({ length: 200 }, (_, i) => effectiveBrandColor(null, `01HBRAND${i}`)),
    )
    expect(seen.size).toBeGreaterThanOrEqual(6)
  })
})

describe('brandColorVar', () => {
  it('maps a key to its agency custom property and an unknown key to slate', () => {
    expect(brandColorVar('violet')).toBe('var(--agency-brand-violet)')
    expect(brandColorVar(null)).toBe('var(--agency-brand-slate)')
    expect(brandColorVar('nope')).toBe('var(--agency-brand-slate)')
  })
})
