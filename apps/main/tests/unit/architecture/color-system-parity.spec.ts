/**
 * Color-system parity architecture test (Sprint 3.5 Chunk 1 — § 1.5,
 * the load-bearing new artifact of this chunk).
 *
 * Pins the structural contracts of the Engine C v2 colour + typography
 * system so a future edit to `@catalyst/design-tokens` cannot silently
 * break the brand layer. Five invariants:
 *
 *   1. SINGLE-VALUE SEMANTICS — every Vuetify theme (`lightTheme`,
 *      `darkTheme`) registers IDENTICAL success / warning / error / info
 *      values. (Decisions D1/D2 reinterpreted: "semantic colours that
 *      work in both modes" — single-value, contrast-verified.)
 *
 *   2. SPLIT NEUTRALS — the neutral surface (background / surface /
 *      on-surface / border) DIFFERS between the two themes. (Decisions
 *      D4 dark + D5 light: zinc neutral scale, per-mode values.)
 *
 *   3. AURORA IS UTILITY-ONLY — the aurora accent (Decision D7) appears
 *      in NO Vuetify `theme.colors` slot of either theme. It is consumed
 *      via `var(--brand-aurora-gradient)` only.
 *
 *   4. AURORA IS AUTHORED — the aurora token exists both as a TS
 *      primitive (`brand.aurora`) and as the CSS variable
 *      `--brand-aurora-gradient` in `tokens.css`. (Pairs with #3:
 *      present in the authored layer, absent from the Vuetify layer.)
 *
 *   5. TYPOGRAPHY PARITY — every step of the TS `typography.scale` has
 *      matching `--catalyst-typography-{step}-{size,weight,line-height}`
 *      CSS variables, and `--brand-font-primary` references Inter.
 *      (Decision D6 reinterpreted: expose the existing 12-step scale as
 *      CSS-consumable.)
 *
 *   6. RADIUS PARITY (Sprint 3.5 Chunk 2 — Decision R1) — every key of
 *      the TS `radius` scale has a matching `--radius-{key}` CSS
 *      declaration in `tokens.css`. Same shape as #5: the kickoff's
 *      D-radii was reinterpreted to "consume the existing dormant radius
 *      scale, don't re-author / don't introduce a parallel
 *      --brand-radius-* namespace." Vuetify `defaults.VBtn` consumes
 *      `var(--radius-md)`; this invariant keeps the TS and CSS layers in
 *      lockstep so a typo (or a half-migration) fails CI.
 *
 * The WCAG-AA contrast invariant (kickoff § 1.5 item 4) is asserted
 * separately by `packages/design-tokens/src/vuetify.spec.ts` against the
 * same `lightTheme` / `darkTheme` objects — re-run during Chunk 1
 * verification.
 *
 * Theme key names (Refinement R1): this test references the actual
 * exported objects `lightTheme` / `darkTheme` — the kickoff's literal
 * `engineCLight` / `engineCDark` names were reinterpreted back to the
 * codebase's chunk-8.1 Vuetify-standard `light` / `dark` keys.
 *
 * Mirror discipline (chunk 7.2 D2 standing standard): this spec mirrors
 * `apps/admin/tests/unit/architecture/color-system-parity.spec.ts`. The
 * two are byte-identical except this header's SPA-name swap — both
 * inspect the SHARED `@catalyst/design-tokens` package, so the
 * assertions themselves do not differ per SPA.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { brand, lightTheme, darkTheme, typography, radius } from '@catalyst/design-tokens'

const TOKENS_CSS = readFileSync(
  path.resolve(__dirname, '../../../../../packages/design-tokens/tokens.css'),
  'utf8',
)

const SEMANTIC_SLOTS = ['success', 'warning', 'error', 'info'] as const
const NEUTRAL_SLOTS = ['background', 'surface', 'on-surface', 'border-color'] as const
const AURORA_HEXES = [brand.aurora.start, brand.aurora.mid, brand.aurora.end].map((h) =>
  h.toLowerCase(),
)

describe('color-system parity — single-value semantics (D1/D2)', () => {
  it.each(SEMANTIC_SLOTS)('registers an identical "%s" value in both themes', (slot) => {
    expect(lightTheme.colors[slot]).toBeDefined()
    expect(darkTheme.colors[slot]).toBe(lightTheme.colors[slot])
  })
})

describe('color-system parity — split neutrals (D4/D5)', () => {
  it.each(NEUTRAL_SLOTS)('uses a DIFFERENT "%s" value per theme', (slot) => {
    expect(lightTheme.colors[slot]).toBeDefined()
    expect(darkTheme.colors[slot]).toBeDefined()
    expect(darkTheme.colors[slot]).not.toBe(lightTheme.colors[slot])
  })
})

describe('color-system parity — aurora is utility-only (D7)', () => {
  it('does not register aurora under any Vuetify theme.colors slot', () => {
    for (const theme of [lightTheme, darkTheme]) {
      expect(Object.keys(theme.colors)).not.toContain('aurora')
      for (const value of Object.values(theme.colors)) {
        const lower = value.toLowerCase()
        // Substring containment (not array equality): catches a solid
        // aurora hex AND the aurora gradient string (which embeds the
        // hexes) leaking into a colours slot. An equality check would
        // pass the gradient-string case — a real coverage gap.
        for (const hex of AURORA_HEXES) {
          expect(lower).not.toContain(hex)
        }
      }
    }
  })

  it('exposes aurora as an authored primitive + CSS gradient variable', () => {
    expect(brand.aurora.gradient).toContain(brand.aurora.start)
    // Assert the DECLARATION (trailing colon), not the bare token name —
    // the bare name also appears inside a `var(--brand-aurora-gradient)`
    // doc comment, which would make a substring check falsely green.
    expect(TOKENS_CSS).toContain('--brand-aurora-gradient:')
    expect(TOKENS_CSS.toLowerCase()).toContain(brand.aurora.start.toLowerCase())
  })
})

describe('color-system parity — typography scale exposed as CSS vars (D6)', () => {
  const steps = Object.keys(typography.scale)

  it('covers all 12 TS scale steps', () => {
    expect(steps).toHaveLength(12)
  })

  it.each(steps)('exposes --catalyst-typography-%s-{size,weight,line-height}', (step) => {
    expect(TOKENS_CSS).toContain(`--catalyst-typography-${step}-size:`)
    expect(TOKENS_CSS).toContain(`--catalyst-typography-${step}-weight:`)
    expect(TOKENS_CSS).toContain(`--catalyst-typography-${step}-line-height:`)
  })

  it('defines --brand-font-primary referencing Inter', () => {
    const match = TOKENS_CSS.match(/--brand-font-primary:\s*([^;]+);/)
    expect(match).not.toBeNull()
    expect(match?.[1]).toContain('Inter')
  })
})

describe('color-system parity — radius scale TS↔CSS parity (R1)', () => {
  const radiusKeys = Object.keys(radius)

  it('covers the full none→full radius scale (6 keys)', () => {
    expect(radiusKeys).toEqual(['none', 'sm', 'md', 'lg', 'xl', 'full'])
  })

  it.each(radiusKeys)('exposes a --radius-%s CSS declaration', (key) => {
    // Assert the DECLARATION form (trailing colon) so a bare mention in a
    // doc comment can't falsely satisfy the check — same hardening the
    // aurora-authored invariant uses.
    expect(TOKENS_CSS).toContain(`--radius-${key}:`)
  })
})
