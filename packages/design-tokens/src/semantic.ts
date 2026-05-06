/**
 * Semantic tokens — components reference these via CSS variables or the Vuetify theme.
 * Source of truth: docs/01-UI-UX.md §2.
 *
 * Use these names. Do not reach into ./tokens.ts directly from components.
 */

import { brand, neutral, semantic as palette } from './tokens'

export const semanticLight = {
  bg: {
    app: neutral[50],
    surface: neutral[0],
    surfaceRaised: neutral[0],
    surfaceSunken: neutral[100],
    overlay: 'rgba(10, 10, 11, 0.4)',
  },
  border: {
    subtle: neutral[200],
    default: neutral[300],
    strong: neutral[400],
  },
  text: {
    primary: neutral[900],
    secondary: neutral[600],
    tertiary: neutral[500],
    disabled: neutral[400],
    inverse: neutral[0],
  },
  action: {
    primary: brand.teal[500],
    primaryHover: brand.teal[600],
    primaryActive: brand.teal[700],
    primaryFg: neutral[0],
    secondary: neutral[100],
    secondaryHover: neutral[200],
    secondaryFg: neutral[900],
    danger: palette.danger[500],
    dangerHover: '#B91C1C',
    dangerFg: neutral[0],
  },
  focusRing: brand.teal[500],
} as const

export const semanticDark = {
  bg: {
    app: brand.ink,
    surface: neutral[900],
    surfaceRaised: neutral[800],
    surfaceSunken: brand.ink,
    overlay: 'rgba(0, 0, 0, 0.6)',
  },
  border: {
    subtle: neutral[800],
    default: neutral[700],
    strong: neutral[600],
  },
  text: {
    primary: brand.cream,
    secondary: neutral[300],
    tertiary: neutral[400],
    disabled: neutral[600],
    inverse: neutral[900],
  },
  action: {
    primary: brand.teal[400],
    primaryHover: brand.teal[300],
    primaryActive: brand.teal[500],
    primaryFg: neutral[950],
    secondary: neutral[800],
    secondaryHover: neutral[700],
    secondaryFg: brand.cream,
    danger: '#EF4444',
    dangerHover: palette.danger[500],
    dangerFg: neutral[0],
  },
  focusRing: brand.teal[400],
} as const

export type SemanticTheme = typeof semanticLight
