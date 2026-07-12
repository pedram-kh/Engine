/**
 * Value-presence tests for the Catalyst Engine v2 brand primitives added in
 * Sprint 3.5 Chunk 1: the aurora accent (Decision D7) and the zinc
 * neutral scale (Decisions D4 + D5).
 *
 * These pin the raw hex values so a typo (e.g. transposing two zinc
 * stops) fails CI rather than silently shipping a wrong surface colour.
 * The semantic wiring + Vuetify-theme consumption is covered separately
 * by `vuetify.spec.ts` (WCAG contrast) and the per-SPA
 * `color-system-parity.spec.ts` (single-value-semantic / split-neutral /
 * aurora-utility-only invariants).
 */

import { describe, expect, it } from 'vitest'

import { brand, zinc } from './tokens'

describe('design-tokens — aurora accent primitive (Decision D7)', () => {
  it('exposes the three aurora stops', () => {
    expect(brand.aurora.start).toBe('#CD69FF')
    expect(brand.aurora.mid).toBe('#7FC3FF')
    expect(brand.aurora.end).toBe('#00FFF2')
  })

  it('exposes the aurora gradient with the 135deg / 0-50-100 stop layout', () => {
    expect(brand.aurora.gradient).toBe(
      'linear-gradient(135deg, #CD69FF 0%, #7FC3FF 50%, #00FFF2 100%)',
    )
  })

  it('the aurora gradient interpolates the three stops in order', () => {
    expect(brand.aurora.gradient).toContain(brand.aurora.start)
    expect(brand.aurora.gradient).toContain(brand.aurora.mid)
    expect(brand.aurora.gradient).toContain(brand.aurora.end)
  })
})

describe('design-tokens — zinc neutral scale (Decisions D4 + D5)', () => {
  it('exposes the full 12-stop zinc scale with the canonical Tailwind hexes', () => {
    expect(zinc).toEqual({
      50: '#FAFAFA',
      100: '#F4F4F5',
      200: '#E4E4E7',
      300: '#D4D4D8',
      400: '#A1A1AA',
      500: '#71717A',
      600: '#52525B',
      700: '#3F3F46',
      800: '#27272A',
      900: '#18181B',
      950: '#09090B',
    })
  })

  it('darkens monotonically from 50 to 950', () => {
    const stops = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950] as const
    const luminance = (hex: string): number => {
      const r = parseInt(hex.slice(1, 3), 16)
      const g = parseInt(hex.slice(3, 5), 16)
      const b = parseInt(hex.slice(5, 7), 16)
      return 0.299 * r + 0.587 * g + 0.114 * b
    }
    for (let i = 1; i < stops.length; i += 1) {
      expect(luminance(zinc[stops[i]])).toBeLessThan(luminance(zinc[stops[i - 1]]))
    }
  })
})
