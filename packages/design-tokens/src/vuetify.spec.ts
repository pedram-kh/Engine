/**
 * Source-inspection regression tests for the Vuetify theme definitions
 * exported from `./vuetify.ts`. Three property families (chunk 8.1):
 *
 *   1. Token coverage: both `lightTheme` and `darkTheme` expose the
 *      Vuetify-standard semantic token set the kickoff requires —
 *      background, surface, primary, secondary, error, success, warning,
 *      info, plus the corresponding `on-*` foreground tokens.
 *
 *   2. Token validity: every value parses as a valid CSS color string
 *      via `colord`.
 *
 *   3. WCAG AA contrast on critical pairs: text-on-background and
 *      foreground-on-action pairs meet WCAG 2.1 AA (4.5:1 normal text)
 *      where the kickoff demands it. Pairs where the existing palette
 *      pre-dates the chunk-8.1 audit AND the kickoff explicitly forbids
 *      redesign are recorded as `it.todo` instead of asserted (light
 *      `primary`/`on-primary` measures 2.49:1 — see vuetify.ts header
 *      docblock and docs/tech-debt.md).
 *
 * Why colord (chunk-8 kickoff Q-and-A finding F): the kickoff
 * recommended `colord` or `culori`. `colord` was selected (decision F1
 * in the chunk-8 plan): ~7 KB, has `colord/plugins/a11y` for WCAG-2.1
 * contrast computation, and is a dev-only dependency — not pulled into
 * the runtime bundle of either SPA.
 */

import { describe, expect, it } from 'vitest'
import { colord, extend } from 'colord'
import a11yPlugin from 'colord/plugins/a11y'

import { lightTheme, darkTheme, type CatalystThemeDefinition } from './vuetify'

extend([a11yPlugin])

/**
 * Vuetify-standard semantic token set the kickoff requires. Both
 * themes MUST expose every entry — Vuetify can auto-derive `on-*`
 * values from luminance, but explicit values give the contrast spec a
 * concrete target and lock the visual palette.
 */
const REQUIRED_TOKEN_SET: ReadonlyArray<string> = [
  'background',
  'on-background',
  'surface',
  'on-surface',
  'primary',
  'on-primary',
  'secondary',
  'on-secondary',
  'error',
  'on-error',
  'success',
  'on-success',
  'warning',
  'on-warning',
  'info',
  'on-info',
]

/**
 * Critical pairs the kickoff names explicitly: text-on-background,
 * surface-on-text, error-state contrast. Buttons (`primary` /
 * `on-primary`) are listed because the dark palette IS in audit scope
 * per the kickoff. The light primary/on-primary pair is grandfathered
 * (see `it.todo` below).
 */
const CRITICAL_PAIRS: ReadonlyArray<{ bg: string; fg: string; label: string }> = [
  { bg: 'background', fg: 'on-background', label: 'background / on-background' },
  { bg: 'surface', fg: 'on-surface', label: 'surface / on-surface' },
  { bg: 'primary', fg: 'on-primary', label: 'primary / on-primary' },
  { bg: 'error', fg: 'on-error', label: 'error / on-error' },
]

/**
 * Non-critical accent pairs the kickoff does not require. Asserted at
 * AA-Large (3.0:1) for documentation only — these are typically used as
 * chip/alert backgrounds with bold or large text.
 */
const ACCENT_PAIRS: ReadonlyArray<{ bg: string; fg: string; label: string }> = [
  { bg: 'success', fg: 'on-success', label: 'success / on-success' },
  { bg: 'warning', fg: 'on-warning', label: 'warning / on-warning' },
  { bg: 'info', fg: 'on-info', label: 'info / on-info' },
]

const AA_NORMAL = 4.5
const AA_LARGE = 3.0

function contrast(theme: CatalystThemeDefinition, bgKey: string, fgKey: string): number {
  const bg = theme.colors[bgKey]
  const fg = theme.colors[fgKey]
  if (bg === undefined || fg === undefined) {
    throw new Error(
      `contrast(): missing token "${bgKey}" or "${fgKey}" on theme. ` +
        'Token coverage spec should have caught this earlier.',
    )
  }
  return colord(fg).contrast(bg)
}

describe('Vuetify theme definitions — token coverage', () => {
  for (const themeName of ['lightTheme', 'darkTheme'] as const) {
    const theme = themeName === 'lightTheme' ? lightTheme : darkTheme
    it(`${themeName} exposes every required Vuetify-standard token`, () => {
      const missing = REQUIRED_TOKEN_SET.filter((token) => theme.colors[token] === undefined)
      if (missing.length > 0) {
        throw new Error(
          [
            `${themeName} is missing ${missing.length} required token(s):`,
            ...missing.map((m) => `  - ${m}`),
            '',
            'Add the missing token(s) to packages/design-tokens/src/vuetify.ts ',
            'with a value sourced from semanticLight / semanticDark, then ',
            'rerun the contrast spec to verify WCAG AA where applicable.',
          ].join('\n'),
        )
      }
      expect(missing).toEqual([])
    })
  }
})

describe('Vuetify theme definitions — token validity', () => {
  for (const themeName of ['lightTheme', 'darkTheme'] as const) {
    const theme = themeName === 'lightTheme' ? lightTheme : darkTheme
    it(`${themeName} exposes only parseable CSS color values`, () => {
      const invalid: Array<{ token: string; value: string }> = []
      for (const [token, value] of Object.entries(theme.colors)) {
        if (!colord(value).isValid()) {
          invalid.push({ token, value })
        }
      }
      if (invalid.length > 0) {
        throw new Error(
          [
            `${themeName} has ${invalid.length} unparseable color value(s):`,
            ...invalid.map((entry) => `  - ${entry.token} = ${entry.value}`),
            '',
            'Every Vuetify color slot must hold a hex / rgb / hsl / named ',
            'color string that colord can parse. CSS-variable references ',
            'belong in tokens.css, not in the Vuetify ThemeDefinition.',
          ].join('\n'),
        )
      }
      expect(invalid).toEqual([])
    })
  }
})

describe('Vuetify theme definitions — WCAG AA contrast on critical pairs (dark theme)', () => {
  for (const pair of CRITICAL_PAIRS) {
    it(`darkTheme: ${pair.label} meets WCAG AA-normal (>= ${AA_NORMAL}:1)`, () => {
      const ratio = contrast(darkTheme, pair.bg, pair.fg)
      // Round to 2 decimals for the failure message; the assertion
      // below uses the unrounded value.
      const rounded = Math.round(ratio * 100) / 100
      if (ratio < AA_NORMAL) {
        throw new Error(
          [
            `darkTheme ${pair.label} contrast ${rounded}:1 is below WCAG AA-normal (${AA_NORMAL}:1).`,
            `Tokens: bg "${pair.bg}" = ${darkTheme.colors[pair.bg]}, fg "${pair.fg}" = ${darkTheme.colors[pair.fg]}.`,
            'Refine the dark palette in packages/design-tokens/src/semantic.ts ',
            'until the pair passes, then update the contrast measurements ',
            'recorded inline in packages/design-tokens/src/vuetify.ts.',
          ].join(' '),
        )
      }
      expect(ratio).toBeGreaterThanOrEqual(AA_NORMAL)
    })
  }
})

describe('Vuetify theme definitions — WCAG AA contrast on critical pairs (light theme)', () => {
  // Light theme background and surface pairs are asserted strictly. The
  // primary/on-primary pair is a known pre-existing AA failure (2.49:1)
  // — see vuetify.ts header docblock and docs/tech-debt.md. The kickoff
  // forbids redesigning the light palette in chunk 8.1, so the pair is
  // recorded as `it.todo` until the future palette-revision chunk
  // addresses it. Removing the `.todo` is the tracking signal.
  for (const pair of CRITICAL_PAIRS) {
    if (pair.bg === 'primary') {
      it.todo(
        `lightTheme: ${pair.label} meets WCAG AA-normal — currently 2.49:1, deferred per chunk-8 kickoff (don't redesign light palette)`,
      )
      continue
    }
    it(`lightTheme: ${pair.label} meets WCAG AA-normal (>= ${AA_NORMAL}:1)`, () => {
      const ratio = contrast(lightTheme, pair.bg, pair.fg)
      const rounded = Math.round(ratio * 100) / 100
      if (ratio < AA_NORMAL) {
        throw new Error(
          [
            `lightTheme ${pair.label} contrast ${rounded}:1 is below WCAG AA-normal (${AA_NORMAL}:1).`,
            `Tokens: bg "${pair.bg}" = ${lightTheme.colors[pair.bg]}, fg "${pair.fg}" = ${lightTheme.colors[pair.fg]}.`,
            'The kickoff forbids redesigning the light palette in chunk 8.1; ',
            'this pair was previously passing — investigate the regression.',
          ].join(' '),
        )
      }
      expect(ratio).toBeGreaterThanOrEqual(AA_NORMAL)
    })
  }
})

describe('Vuetify theme definitions — WCAG AA-Large contrast on accent pairs (both themes)', () => {
  for (const themeName of ['lightTheme', 'darkTheme'] as const) {
    const theme = themeName === 'lightTheme' ? lightTheme : darkTheme
    for (const pair of ACCENT_PAIRS) {
      it(`${themeName}: ${pair.label} meets WCAG AA-Large (>= ${AA_LARGE}:1)`, () => {
        const ratio = contrast(theme, pair.bg, pair.fg)
        const rounded = Math.round(ratio * 100) / 100
        if (ratio < AA_LARGE) {
          throw new Error(
            [
              `${themeName} ${pair.label} contrast ${rounded}:1 is below WCAG AA-Large (${AA_LARGE}:1).`,
              `Tokens: bg "${pair.bg}" = ${theme.colors[pair.bg]}, fg "${pair.fg}" = ${theme.colors[pair.fg]}.`,
              'Accent pairs should still meet AA-Large (typical use is chip / alert / badge).',
              'Refine the value in packages/design-tokens/src/vuetify.ts.',
            ].join(' '),
          )
        }
        expect(ratio).toBeGreaterThanOrEqual(AA_LARGE)
      })
    }
  }
})
