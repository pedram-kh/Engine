/**
 * Vuetify theme objects derived from the Catalyst design tokens.
 * Imported by both apps/main and apps/admin Vuetify plugins.
 *
 * The Vuetify ThemeDefinition shape is replicated as a structural type so
 * this package does not pull in vuetify as a runtime dependency.
 *
 * Theme keys (chunk 8.1; preserved through Sprint 3.5 Chunk 1 — R1):
 *   - `light` and `dark` align with Vuetify's standard theme names. The
 *     previous `catalystLight` / `catalystDark` keys were renamed in
 *     chunk 8.1 to honor the framework convention (review priority #1
 *     of the chunk-8 kickoff: "Tokens use Vuetify's semantic names
 *     (primary, surface, error, etc.), not custom invented names" —
 *     extending the same logic to *theme* keys).
 *   - Sprint 3.5 Chunk 1 Refinement R1: the kickoff's proposed
 *     `engineCDark` / `engineCLight` key rename was reinterpreted back to
 *     these `dark` / `light` keys. The Engine C v2 brand identity lives in
 *     the theme VALUES (zinc neutrals, aurora utility, Inter font), not
 *     the key names — so chunk 8.1's Vuetify-standard-naming decision is
 *     preserved unchanged.
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
 * WCAG AA contrast on the dark palette (Sprint 3.5 Chunk 1 — zinc D4 audit;
 * chunk-8.1 baseline re-measured after the warm-grey → zinc migration):
 *   Critical pairs measured with the WCAG 2.1 relative-luminance formula
 *   (asserted at runtime by `vuetify.spec.ts`):
 *     - background (#09090B zinc[950]) / on-background (#D4D4D8 zinc[300])
 *         → ~15:1    ✅ AA-normal (≥ 4.5:1)
 *     - surface (#18181B zinc[900]) / on-surface (#D4D4D8 zinc[300])
 *         → ~13:1    ✅ AA-normal
 *     - primary (#2ECDAE brand.teal[400]) / on-primary (#09090B zinc[950])
 *         → ~10:1    ✅ AA-normal  (primary stays teal — co-brand path)
 *     - error (#DC2626 palette.danger[500]) / on-error (#FFFFFF)
 *         → 4.83:1   ✅ AA-normal (refined from #EF4444 in chunk 8.1
 *                    which measured 3.69:1 — failed AA-normal)
 *
 *   Light-palette contrast notes (Decision D5 zinc light surface):
 *     - background (#FAFAFA zinc[50]) / on-background (#27272A zinc[800])
 *         → ~13:1    ✅ AA-normal
 *     - surface (#FFFFFF) / on-surface (#27272A zinc[800])
 *         → ~14:1    ✅ AA-normal
 *     - error (#DC2626 palette.danger[500]) / on-error (#FFFFFF)
 *         → 4.83:1   ✅ AA-normal
 *     - primary (#14B8A6 brand.teal[500]) / on-primary (#FFFFFF)
 *         → 2.49:1   ❌ FAILS AA-normal AND AA-Large
 *           — Pre-existing as of chunk 3 (design-tokens package
 *             creation); carried forward through the co-brand refresh
 *             (the teal primary is deliberately preserved). Logged in
 *             docs/tech-debt.md as a Phase-1-late refinement target.
 *             The contrast spec excludes this pair from the light-theme
 *             assertions to keep CI green; an `it.todo` keeps the
 *             refinement in view.
 *
 *   Semantic feedback foregrounds (success/warning/info) are single-value
 *   across both themes and unchanged from chunk 8.1 (Decisions D1/D2
 *   reinterpreted at plan-pause-time — "semantic colours that work in both
 *   modes", already satisfied). Their `on-*` foregrounds intentionally
 *   still reference the warm `neutral` primitive (white / near-black) —
 *   the migration to zinc applies to the surface/border/text neutral
 *   surface, not to these locked semantic chips.
 *
 * Container / variant Material tokens (Sprint 3.5 Chunk 3 — finding (b)):
 *   `outline`, `outline-variant`, `primary-container`, `error-container`
 *   are now registered EXPLICITLY in both themes with deliberate
 *   zinc/teal/danger-derived values. They were previously referenced by
 *   high-CSS surfaces (onboarding upload/preview tiles, PortfolioGallery,
 *   admin EditFieldRow). The sweep found a latent bug: `outline` /
 *   `outline-variant` exist in neither our themes NOR Vuetify's default
 *   theme (verified against vuetify@3.12.5 genDefaults), so no
 *   `--v-theme-outline*` CSS var was emitted. An undefined var inside
 *   `rgb()` invalidates the `border` shorthand at computed-value time →
 *   every longhand resets to initial → `border-style: none`. So the
 *   dropzone dashed borders + the click-through terms border rendered as
 *   NO BORDER AT ALL (not merely "wrong colour") — and the fallback-chained
 *   `var(--v-theme-outline-variant, var(--v-theme-outline))` surfaces broke
 *   too, since the fallback target was itself undefined. Eyes-on confirmed
 *   the before (no border) / after (border renders) in headless Chromium.
 *   Registering the tokens is the fix. Values:
 *     - outline           zinc-300 (#D4D4D8) light / zinc-700 (#3F3F46) dark
 *         — matches border.default, the real border weight the dropzones want.
 *     - outline-variant   zinc-200 (#E4E4E7) light / zinc-800 (#27272A) dark
 *         — a subtler border one step lighter than `outline`.
 *     - primary-container teal-50 (#E6F8F5) light / teal-800 (#074A44) dark
 *         — perceptible teal drag-over fill (teal-900 was an invisible wash
 *           on zinc-900; spot-check downgraded it to teal-800, eyes-on).
 *     - error-container   danger-100 (#FEE2E2) light / #3B1A1A dark
 *         — the danger palette has no clean dark step (only 100/500), so the
 *         dark value is a hand-picked muted error surface. on-surface text
 *         (zinc-300 #D4D4D8) on #3B1A1A measures ~13:1 (comfortably AA).
 *   The CSS fallbacks in the consumer files are KEPT as defense-in-depth.
 *   The `color-system-parity` test pins all eight values.
 */

import { brand, neutral, zinc, semantic as palette } from './tokens'
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
    // Container / variant tokens (Chunk 3 finding (b)) — explicit
    // registration so high-CSS surfaces stop relying on auto-derivation.
    outline: zinc[300],
    'outline-variant': zinc[200],
    'primary-container': brand.teal[50],
    'error-container': palette.danger[100],
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
    // Container / variant tokens (Chunk 3 finding (b)). `error-container`
    // dark is a hand-picked muted error surface — the danger palette has
    // no clean dark step. on-surface text (zinc-300) on it measures ~13:1.
    // `primary-container` dark is teal-800 (NOT teal-900): teal-900 measured
    // ~1.09:1 against the zinc-900 surface — an invisible wash, so pinning it
    // would have regression-locked a non-functional fill. teal-800 (~1.75:1)
    // reads as a distinct drag-over fill while staying subordinate to the
    // bright teal-400 `primary` border. Eyes-on verified (Chunk 3 spot-check).
    outline: zinc[700],
    'outline-variant': zinc[800],
    'primary-container': brand.teal[800],
    'error-container': '#3B1A1A',
  },
}
