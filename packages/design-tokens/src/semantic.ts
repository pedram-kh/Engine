/**
 * Semantic tokens — components reference these via CSS variables or the Vuetify theme.
 * Source of truth: docs/01-UI-UX.md §2 (Catalyst v1) + Sprint 3.5 Chunk 1 (Catalyst Engine v2).
 *
 * Use these names. Do not reach into ./tokens.ts directly from components.
 *
 * Sprint 3.5 Chunk 1 — Catalyst Engine v2 brand layer (co-brand path):
 *   The neutral surface/border/text tokens migrated from the warm-grey
 *   `neutral` scale to the true-neutral `zinc` scale per Decisions D4
 *   (dark) + D5 (light). The action.primary tokens INTENTIONALLY stay on
 *   `brand.teal` — Sprint 3.5 is a co-brand refresh, not a primary-colour
 *   pivot (the kickoff's Decision D3 sky-600 primary was reinterpreted to
 *   "preserve the teal primary" at plan-pause-time). The semantic feedback
 *   palette (success/warning/danger/info) is unchanged single-value per
 *   both modes (D1/D2 reinterpreted — see vuetify.ts + the
 *   color-system-parity architecture test).
 *
 *   `brand.cream` / `brand.ink` are no longer referenced here (zinc-50 /
 *   zinc-950 take their place) but remain importable primitives for the
 *   logo + brand surfaces (Sprint 3.5 Chunk 5).
 */

import { brand, zinc, semantic as palette } from './tokens'

export const semanticLight = {
  bg: {
    // zinc has no pure-white stop; the lightest surface is literal white
    // per Decision D5 (`--v-theme-surface-light` = #FFFFFF).
    app: zinc[50],
    surface: '#FFFFFF',
    surfaceRaised: '#FFFFFF',
    surfaceSunken: zinc[100],
    overlay: 'rgba(9, 9, 11, 0.4)',
  },
  border: {
    subtle: zinc[200],
    default: zinc[300],
    strong: zinc[300],
  },
  text: {
    primary: zinc[800],
    secondary: zinc[500],
    tertiary: zinc[500],
    disabled: zinc[400],
    inverse: '#FFFFFF',
  },
  action: {
    primary: brand.teal[500],
    primaryHover: brand.teal[600],
    primaryActive: brand.teal[700],
    primaryFg: '#FFFFFF',
    secondary: zinc[100],
    secondaryHover: zinc[200],
    secondaryFg: zinc[800],
    danger: palette.danger[500],
    dangerHover: '#B91C1C',
    dangerFg: '#FFFFFF',
  },
  focusRing: brand.teal[500],
} as const

export const semanticDark = {
  bg: {
    app: zinc[950],
    surface: zinc[900],
    surfaceRaised: zinc[800],
    surfaceSunken: zinc[950],
    overlay: 'rgba(0, 0, 0, 0.6)',
  },
  border: {
    subtle: zinc[800],
    default: zinc[700],
    strong: zinc[700],
  },
  text: {
    primary: zinc[300],
    secondary: zinc[400],
    tertiary: zinc[400],
    disabled: zinc[600],
    inverse: zinc[950],
  },
  action: {
    primary: brand.teal[400],
    primaryHover: brand.teal[300],
    primaryActive: brand.teal[500],
    primaryFg: zinc[950],
    secondary: zinc[800],
    secondaryHover: zinc[700],
    secondaryFg: zinc[50],
    // Aligned with the light palette (palette.danger[500] = #DC2626)
    // by chunk 8.1. The previous lighter shade (#EF4444) measured
    // 3.69:1 against white text, below WCAG AA-normal (4.5:1).
    // #DC2626 measures 4.83:1 against white. The hover state moves
    // darker for tactile feedback on a dark backdrop.
    danger: palette.danger[500],
    dangerHover: '#B91C1C',
    dangerFg: '#FFFFFF',
  },
  focusRing: brand.teal[400],
} as const

export type SemanticTheme = typeof semanticLight
