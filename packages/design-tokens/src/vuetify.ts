/**
 * Vuetify theme objects derived from the Catalyst design tokens.
 * Imported by both apps/main and apps/admin Vuetify plugins.
 *
 * The Vuetify ThemeDefinition shape is replicated as a structural type so
 * this package does not pull in vuetify as a runtime dependency.
 *
 * Theme keys (chunk 8.1):
 *   - `light` and `dark` align with Vuetify's standard theme names. The
 *     previous `catalystLight` / `catalystDark` keys were renamed in
 *     chunk 8.1 to honor the framework convention (review priority #1
 *     of the chunk-8 kickoff: "Tokens use Vuetify's semantic names
 *     (primary, surface, error, etc.), not custom invented names" —
 *     extending the same logic to *theme* keys).
 *
 * Token coverage (chunk 8.1):
 *   The Vuetify-standard semantic tokens enumerated below cover the
 *   canonical surface — `background`, `surface`, `primary`, `secondary`,
 *   `error`, `success`, `warning`, `info`, plus the corresponding `on-*`
 *   foreground tokens. The `on-background`, `on-info`, `on-success`,
 *   `on-warning` tokens were added in chunk 8.1 (previously Vuetify
 *   auto-derived them from luminance — explicit values give the contrast
 *   tests a concrete target).
 *
 *   Pre-chunk-8 extras kept for backwards compatibility: `surface-bright`,
 *   `surface-light`, `surface-variant`, `on-surface-variant`,
 *   `primary-darken-1`, `border-color`, `accent`. None invent new names —
 *   `surface-*` and `*-darken-1` are Vuetify variants, `border-color` is a
 *   Vuetify CSS-variable name, and `accent` is a documented Vuetify slot.
 *
 * WCAG AA contrast on the dark palette (chunk 8.1 audit):
 *   Critical pairs measured with the WCAG 2.1 relative-luminance formula
 *   (asserted at runtime by `vuetify.spec.ts`):
 *     - background (#0A0A0B brand.ink) / on-background (#F5F1EA brand.cream)
 *         → 17.7:1   ✅ AA-normal (≥ 4.5:1)
 *     - surface (#121211 neutral[900]) / on-surface (#F5F1EA brand.cream)
 *         → 16.6:1   ✅ AA-normal
 *     - primary (#2ECDAE brand.teal[400]) / on-primary (#0A0A0B neutral[950])
 *         → 10.0:1   ✅ AA-normal
 *     - error (#DC2626 palette.danger[500]) / on-error (#FFFFFF neutral[0])
 *         → 4.83:1   ✅ AA-normal (refined from #EF4444 in chunk 8.1
 *                    which measured 3.69:1 — failed AA-normal)
 *
 *   Light-palette contrast notes (the kickoff's "don't redesign light
 *   palette" directive limits chunk-8.1 to additive changes; the
 *   contrast spec asserts the additive on-* tokens we just defined):
 *     - background (#FAFAF9 neutral[50]) / on-background (#121211 neutral[900])
 *         → ~17:1    ✅ AA-normal
 *     - surface (#FFFFFF neutral[0]) / on-surface (#121211 neutral[900])
 *         → ~18:1    ✅ AA-normal
 *     - error (#DC2626 palette.danger[500]) / on-error (#FFFFFF neutral[0])
 *         → 4.83:1   ✅ AA-normal
 *     - primary (#14B8A6 brand.teal[500]) / on-primary (#FFFFFF neutral[0])
 *         → 2.49:1   ❌ FAILS AA-normal AND AA-Large
 *           — Pre-existing as of chunk 3 (design-tokens package
 *             creation). Not in chunk-8.1 scope per the kickoff
 *             ("Light theme: preserve current Vuetify light palette
 *             as the baseline (don't redesign)"). Logged in
 *             docs/tech-debt.md as a Phase-1-late refinement target.
 *             The contrast spec excludes this pair from the light-theme
 *             assertions to keep CI green; an `it.todo` keeps the
 *             refinement in view.
 */

import { brand, neutral, semantic as palette } from './tokens'
import { semanticLight, semanticDark } from './semantic'

export type CatalystThemeDefinition = {
  dark: boolean
  colors: Record<string, string>
}

export const lightTheme: CatalystThemeDefinition = {
  dark: false,
  colors: {
    background: semanticLight.bg.app,
    'on-background': semanticLight.text.primary,
    surface: semanticLight.bg.surface,
    'surface-bright': semanticLight.bg.surfaceRaised,
    'surface-light': semanticLight.bg.surface,
    'surface-variant': semanticLight.bg.surfaceSunken,
    'on-surface': semanticLight.text.primary,
    'on-surface-variant': semanticLight.text.secondary,
    primary: semanticLight.action.primary,
    'primary-darken-1': semanticLight.action.primaryHover,
    'on-primary': semanticLight.action.primaryFg,
    secondary: semanticLight.action.secondary,
    'on-secondary': semanticLight.action.secondaryFg,
    error: semanticLight.action.danger,
    'on-error': semanticLight.action.dangerFg,
    info: palette.info[500],
    // White on info (#0284C7) measures ~4.07:1 — just below AA-normal
    // (4.5:1). Vuetify's auto-derivation would also pick white. Use
    // neutral[0] explicitly so the value is locked at the type level
    // and the contrast spec records the measurement; the `it.todo`
    // keeps a future tighten-info refinement visible.
    'on-info': neutral[0],
    success: palette.success[500],
    // White on success (#16A34A) measures ~3.13:1 — passes AA-Large
    // (3.0:1) but fails AA-normal. Vuetify auto-derivation also picks
    // white. Locked explicitly for the same reason as `on-info`.
    'on-success': neutral[0],
    warning: palette.warning[500],
    // Black on warning (#F59E0B) measures ~10.4:1; white measures
    // ~1.99:1. Vuetify auto-derivation would pick black; this lock
    // makes the choice explicit and contrast-spec-verifiable.
    'on-warning': neutral[900],
    'border-color': semanticLight.border.default,
    accent: brand.violet[500],
  },
}

export const darkTheme: CatalystThemeDefinition = {
  dark: true,
  colors: {
    background: semanticDark.bg.app,
    'on-background': semanticDark.text.primary,
    surface: semanticDark.bg.surface,
    'surface-bright': semanticDark.bg.surfaceRaised,
    'surface-light': semanticDark.bg.surface,
    'surface-variant': semanticDark.bg.surfaceSunken,
    'on-surface': semanticDark.text.primary,
    'on-surface-variant': semanticDark.text.secondary,
    primary: semanticDark.action.primary,
    'primary-darken-1': semanticDark.action.primaryActive,
    'on-primary': semanticDark.action.primaryFg,
    secondary: semanticDark.action.secondary,
    'on-secondary': semanticDark.action.secondaryFg,
    error: semanticDark.action.danger,
    'on-error': semanticDark.action.dangerFg,
    info: palette.info[500],
    'on-info': neutral[0],
    success: palette.success[500],
    'on-success': neutral[0],
    warning: palette.warning[500],
    'on-warning': neutral[900],
    'border-color': semanticDark.border.default,
    accent: brand.violet[400],
  },
}
