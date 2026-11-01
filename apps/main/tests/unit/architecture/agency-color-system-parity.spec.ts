/**
 * AH-114 spec 4 — the agency palettes (`agency-dark`, `agency-light`).
 * Sibling of `color-system-parity.spec.ts`, which guards the shared themes and
 * is left untouched.
 *
 *   1. Same key set in both themes (Vuetify's `.bg-*` / `.text-*` utilities and
 *      `--v-theme-*` variables must resolve identically in either).
 *   2. Single-value semantics: brand, status and accent FILLS (and the labels
 *      on them) are identical in both themes.
 *   3. Neutrals, and the status colours used as TEXT, differ between themes.
 *   4. Every `on-*` label meets AA-normal (4.5:1) on its fill.
 *   5. Every text colour meets AA-normal on every surface it can sit on, and
 *      every status/brand text colour meets it on its own 15% tint over every
 *      surface a pill can sit on. Ratios are WCAG 2.1 relative luminance.
 *   6. The agency dark palette is the Figma palette (`nativeDarkTheme`) with
 *      EXACTLY four recorded deviations (accepted inventory ruling 11: the Figma
 *      values fail AA-normal, each is lifted one step).
 *   7. The light primary is not the shared teal-500 (white-on-teal is 2.49:1).
 */

import { describe, expect, it } from 'vitest'

import { lightTheme } from '@catalyst/design-tokens/vuetify'
import { nativeDarkTheme, nativePalette } from '@catalyst/design-tokens/native'

import {
  AGENCY_DARK_THEME,
  AGENCY_LIGHT_THEME,
  agencyColors,
  type AgencyThemeName,
} from '@/modules/agency/theme/agencyThemes'

const AA_NORMAL = 4.5
const TINT_ALPHA = 0.15

type Rgb = readonly [number, number, number]

function rgb(hex: string): Rgb {
  expect(hex, `"${hex}" must be a 6-digit hex`).toMatch(/^#[0-9a-f]{6}$/i)
  return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)) as unknown as Rgb
}

function luminance([r, g, b]: Rgb): number {
  const lin = (c: number) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
}

function ratio(a: Rgb, b: Rgb): number {
  const la = luminance(a)
  const lb = luminance(b)
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

function over(fill: Rgb, base: Rgb, alpha: number): Rgb {
  return [
    fill[0] * alpha + base[0] * (1 - alpha),
    fill[1] * alpha + base[1] * (1 - alpha),
    fill[2] * alpha + base[2] * (1 - alpha),
  ]
}

const PALETTES = agencyColors()

/** Index a palette; a missing key is a test failure, not `undefined`. */
function at(palette: Record<string, string | undefined>, key: string): string {
  const value = palette[key]
  if (value === undefined) throw new Error(`colour "${key}" is not registered`)
  return value
}
const THEMES: readonly AgencyThemeName[] = [AGENCY_DARK_THEME, AGENCY_LIGHT_THEME]

/** Fills, and the labels on them, that must not change between themes. */
const FILL_KEYS = [
  'primary',
  'primary-darken-1',
  'on-primary',
  'accent',
  'error',
  'on-error',
  'info',
  'on-info',
  'success',
  'on-success',
  'warning',
  'on-warning',
  'status-neutral',
  'status-ai',
  'text-inverse',
  'error-strong',
] as const

/** Neutrals and status-as-text colours: must differ between the themes. */
const THEME_SPECIFIC_KEYS = [
  'background',
  'on-background',
  'surface',
  'surface-bright',
  'surface-light',
  'surface-variant',
  'on-surface',
  'on-surface-variant',
  'secondary',
  'on-secondary',
  'border-color',
  'outline',
  'outline-variant',
  'primary-container',
  'error-container',
  'sidebar',
  'topbar',
  'text-tertiary',
  'primary-text',
  'success-text',
  'info-text',
  'warning-text',
  'error-text',
  'neutral-text',
  'ai-text',
] as const

const ON_FILL_PAIRS = [
  ['on-primary', 'primary'],
  ['on-secondary', 'secondary'],
  ['on-error', 'error'],
  ['on-info', 'info'],
  ['on-success', 'success'],
  ['on-warning', 'warning'],
] as const

/** Every neutral a body-text colour can sit on. */
const SURFACES = ['background', 'surface', 'surface-bright', 'sidebar', 'topbar'] as const

const TEXT_ON_SURFACES = [
  'on-background',
  'on-surface',
  'on-surface-variant',
  'text-tertiary',
  'primary-text',
] as const

/** Pill tones: [fill, text]. The tint is the fill at 15% over the surface. */
const TONES = [
  ['success', 'success-text'],
  ['info', 'info-text'],
  ['warning', 'warning-text'],
  ['error', 'error-text'],
  ['status-neutral', 'neutral-text'],
  ['status-ai', 'ai-text'],
] as const

/** The pill can sit on the canvas, a card, or a raised element. */
const PILL_SURFACES = ['background', 'surface', 'surface-bright'] as const

/**
 * The only dark values that differ from the Figma palette (inventory §2.1,
 * accepted ruling 11). Figma value on the left, shipped value on the right.
 */
const LIFTED_DARK = {
  'text-tertiary': { figma: nativePalette['text-tertiary'], shipped: '#8a8a92' },
  'info-text': { figma: nativePalette['status-info'], shipped: '#70a4ff' },
  'error-text': { figma: nativePalette['status-danger'], shipped: '#f3738b' },
  'ai-text': { figma: '#8b6cff', shipped: '#a289ff' },
} as const

describe('agency colour system parity', () => {
  it('both themes define the same colour keys', () => {
    const dark = Object.keys(PALETTES[AGENCY_DARK_THEME]).sort()
    const light = Object.keys(PALETTES[AGENCY_LIGHT_THEME]).sort()
    expect(light).toStrictEqual(dark)
  })

  it('every colour is a 6-digit hex', () => {
    for (const name of THEMES) {
      for (const [key, value] of Object.entries(PALETTES[name])) {
        expect(value, `${name} ${key}`).toMatch(/^#[0-9a-f]{6}$/i)
      }
    }
  })

  it.each(FILL_KEYS)('fill %s is the same value in both themes', (key) => {
    const dark = at(PALETTES[AGENCY_DARK_THEME], key)
    const light = at(PALETTES[AGENCY_LIGHT_THEME], key)
    expect(light.toLowerCase(), `${key}: light must equal dark`).toBe(dark.toLowerCase())
  })

  it.each(THEME_SPECIFIC_KEYS)('%s differs between the themes', (key) => {
    const dark = at(PALETTES[AGENCY_DARK_THEME], key)
    const light = at(PALETTES[AGENCY_LIGHT_THEME], key)
    expect(light.toLowerCase(), `${key}: light must differ from dark`).not.toBe(dark.toLowerCase())
  })

  describe.each(THEMES)('%s contrast (WCAG 2.1, AA-normal 4.5:1)', (name) => {
    const colors = PALETTES[name]

    it('text-inverse (white) on error-strong, the notification pill fill', () => {
      expect(
        ratio(rgb(at(colors, 'text-inverse')), rgb(at(colors, 'error-strong'))),
        `${name} text-inverse on error-strong`,
      ).toBeGreaterThanOrEqual(AA_NORMAL)
    })

    it.each(ON_FILL_PAIRS)('%s on %s', (on, fill) => {
      expect(
        ratio(rgb(at(colors, on)), rgb(at(colors, fill))),
        `${name} ${on} on ${fill}`,
      ).toBeGreaterThanOrEqual(AA_NORMAL)
    })

    for (const text of TEXT_ON_SURFACES) {
      it.each(SURFACES)(`${text} on %s`, (surface) => {
        expect(
          ratio(rgb(at(colors, text)), rgb(at(colors, surface))),
          `${name} ${text} on ${surface}`,
        ).toBeGreaterThanOrEqual(AA_NORMAL)
      })
    }

    it('primary-text on primary-container', () => {
      expect(
        ratio(rgb(at(colors, 'primary-text')), rgb(at(colors, 'primary-container'))),
        `${name} primary-text on primary-container`,
      ).toBeGreaterThanOrEqual(AA_NORMAL)
    })

    for (const [fill, text] of TONES) {
      it.each(PILL_SURFACES)(`${text} on its ${fill} tint over %s`, (surface) => {
        const tint = over(rgb(at(colors, fill)), rgb(at(colors, surface)), TINT_ALPHA)
        expect(
          ratio(rgb(at(colors, text)), tint),
          `${name} ${text} on ${fill} tint over ${surface}`,
        ).toBeGreaterThanOrEqual(AA_NORMAL)
      })
    }
  })

  describe('dark palette is the Figma palette plus four recorded lifts', () => {
    const dark = PALETTES[AGENCY_DARK_THEME]

    it('every colour the creator native dark theme defines is carried over unchanged', () => {
      for (const [key, value] of Object.entries(nativeDarkTheme.colors)) {
        expect(at(dark, key), key).toBe(value)
      }
    })

    it.each(Object.entries(LIFTED_DARK))(
      '%s ships the lifted value, not the Figma one',
      (key, v) => {
        expect(dark[key]).toBe(v.shipped)
        expect(dark[key]).not.toBe(v.figma)
      },
    )

    it('the lifted values are the ONLY text colours that differ from their Figma source', () => {
      const figmaSource: Record<string, string> = {
        'text-tertiary': nativePalette['text-tertiary'],
        'primary-text': nativePalette['accent-primary'],
        'success-text': nativePalette['status-success'],
        'info-text': nativePalette['status-info'],
        'warning-text': nativePalette['status-warning'],
        'error-text': nativePalette['status-danger'],
        'neutral-text': nativePalette['text-secondary'],
        'ai-text': '#8b6cff',
      }
      const deviating = Object.keys(figmaSource)
        .filter((key) => at(dark, key).toLowerCase() !== at(figmaSource, key).toLowerCase())
        .sort()
      expect(deviating).toStrictEqual(Object.keys(LIFTED_DARK).sort())
    })
  })

  describe('light palette', () => {
    const light = PALETTES[AGENCY_LIGHT_THEME]

    it('primary is not the shared teal-500 (white on it is 2.49:1)', () => {
      expect(at(light, 'primary').toLowerCase()).not.toBe(
        at(lightTheme.colors, 'primary').toLowerCase(),
      )
    })

    it('the light primary label clears AA on the mint fill', () => {
      expect(ratio(rgb(at(light, 'on-primary')), rgb(at(light, 'primary')))).toBeGreaterThanOrEqual(
        AA_NORMAL,
      )
    })
  })
})
