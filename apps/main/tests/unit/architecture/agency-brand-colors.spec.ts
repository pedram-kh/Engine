/**
 * AH-119 — the brand colour palette is defined in exactly one place per theme and the code's list
 * of keys matches it.
 *
 * `agency.css` carries `--agency-brand-<key>` for the eight palette keys the API's `BrandColor`
 * enum allows, once for the dark theme (the base block, which the light theme inherits) and once
 * in the light override. `brandColor.ts` (`BRAND_COLOR_KEYS`, read by the swatches, the dots and
 * the marks) must list the same keys, and every key needs a label in `app.brands.color`.
 * A key added in only one of these places renders as nothing (an undefined variable) or an
 * unlabelled swatch.
 *
 * Scoping of the block itself is pinned by `agency-theme-scoping.spec.ts` (every selector in every
 * agency stylesheet must start with the agency theme classes; no allow-list entry is needed).
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import enApp from '@/core/i18n/locales/en/app.json'
import { BRAND_COLOR_KEYS } from '@/modules/agency/composables/brandColor'

const KEYS = ['violet', 'green', 'amber', 'blue', 'pink', 'teal', 'red', 'slate']

const CSS = readFileSync(
  path.resolve(__dirname, '../../../src/modules/agency/theme/agency.css'),
  'utf8',
).replace(/\/\*[\s\S]*?\*\//g, '')

const SCOPE = ':is(.v-theme--agency-dark, .v-theme--agency-light)'

function normalise(selector: string): string {
  return selector.trim().replace(/\s+/g, ' ').replace(/\(\s+/g, '(').replace(/\s+\)/g, ')')
}

/** The `--agency-brand-*` declarations of every rule whose selector is exactly `selector`. */
function brandVars(selector: string): Record<string, string> {
  const out: Record<string, string> = {}
  for (const match of CSS.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    if (normalise(match[1] as string) !== selector) continue
    for (const decl of (match[2] as string).matchAll(/(--agency-brand-[a-z]+)\s*:\s*([^;]+);/g)) {
      out[decl[1] as string] = (decl[2] as string).trim()
    }
  }
  return out
}

const DARK = brandVars(SCOPE)
const LIGHT = brandVars(`${SCOPE}.v-theme--agency-light`)
const EXPECTED_VARS = KEYS.map((k) => `--agency-brand-${k}`)

describe('agency brand colour palette', () => {
  it('defines all eight keys in the dark (base) block', () => {
    expect(Object.keys(DARK).sort()).toEqual([...EXPECTED_VARS].sort())
  })

  it('defines all eight keys in the light block', () => {
    expect(Object.keys(LIGHT).sort()).toEqual([...EXPECTED_VARS].sort())
  })

  it('gives every key a hex colour in both blocks', () => {
    for (const [block, vars] of [
      ['dark', DARK],
      ['light', LIGHT],
    ] as const) {
      for (const name of EXPECTED_VARS) {
        expect(vars[name], `${block} ${name}`).toMatch(/^#[0-9a-f]{6}$/i)
      }
    }
  })

  it('light overrides every dark value (a key copied unchanged would not hold contrast on white)', () => {
    for (const name of EXPECTED_VARS) {
      expect(LIGHT[name]?.toLowerCase(), name).not.toBe(DARK[name]?.toLowerCase())
    }
  })

  it('defines no brand variable outside the eight keys, anywhere in the stylesheet', () => {
    const all = [...CSS.matchAll(/--agency-brand-([a-z]+)\s*:/g)].map((m) => m[1] as string)
    expect(new Set(all)).toEqual(new Set(KEYS))
  })

  it('brandColor.ts BRAND_COLOR_KEYS is exactly that list, in the same order', () => {
    expect([...BRAND_COLOR_KEYS]).toEqual(KEYS)
  })

  it('every key has a label under app.brands.color (the swatch aria-label)', () => {
    expect(Object.keys(enApp.app.brands.color).sort()).toEqual([...KEYS].sort())
    for (const key of KEYS) {
      const label = (enApp.app.brands.color as Record<string, string>)[key]
      expect(label, key).toBeTruthy()
    }
  })
})
