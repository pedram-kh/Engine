/**
 * Brand and primitive design tokens for Catalyst Engine.
 * Source of truth: docs/01-UI-UX.md §2.
 *
 * Do not import these raw values from components. Components consume
 * semantic tokens (see ./semantic.ts) which map to these values per theme.
 */

export const brand = {
  teal: {
    50: '#E6F8F5',
    100: '#B8EDE3',
    200: '#8AE3D1',
    300: '#5CD8C0',
    400: '#2ECDAE',
    500: '#14B8A6',
    600: '#0F9488',
    700: '#0B6F66',
    800: '#074A44',
    900: '#042522',
  },
  violet: {
    50: '#F2EBFE',
    100: '#DDC9FC',
    200: '#C8A7FA',
    300: '#B385F8',
    400: '#9E63F6',
    500: '#8B5CF6',
    600: '#7039D6',
    700: '#5828A8',
    800: '#401C7A',
    900: '#28104C',
  },
  cream: '#F5F1EA',
  ink: '#0A0A0B',
  gradient: 'linear-gradient(135deg, #14B8A6 0%, #8B5CF6 100%)',
  /**
   * Aurora accent (Sprint 3.5 Chunk 1 — Catalyst Engine v2 brand layer).
   *
   * The aurora gradient is the partner-agency-aligned brand accent. Per
   * Sprint 3.5 Decision D7 it is a UTILITY value, NOT a Vuetify theme
   * color: it is consumed directly via `var(--brand-aurora-gradient)` as
   * thin chrome accents on the surfaces it shipped to in Chunk 4 — the
   * auth card (both SPAs), the onboarding app-bar, and the creator
   * dashboard header — and is intentionally never registered in any
   * Vuetify `theme.colors` slot. The `color-system-parity` architecture
   * test pins the not-in-theme-colors invariant.
   *
   * The midpoint stop sits at 50% — the natural reading of three stops.
   */
  aurora: {
    start: '#CD69FF',
    mid: '#7FC3FF',
    end: '#00FFF2',
    gradient: 'linear-gradient(135deg, #CD69FF 0%, #7FC3FF 50%, #00FFF2 100%)',
  },
} as const

/**
 * Zinc neutral scale (Sprint 3.5 Chunk 1 — Catalyst Engine v2 brand layer).
 *
 * Per Sprint 3.5 Decisions D4 (dark) + D5 (light), the v2 neutral surface
 * uses Tailwind's zinc family — a true-neutral grey. It is the canonical
 * surface/border/text palette for the Vuetify themes. The v1 warm-grey
 * `neutral` scale that zinc replaced was the last dead-code holdover from
 * the original palette; its runtime consumers were severed in Chunk 4 and
 * the primitive was deleted in Chunk 5. `brand.cream` (#F5F1EA) and
 * `brand.ink` (#0A0A0B) are standalone logo-derived hex literals — they do
 * NOT reference the old `neutral` scale.
 */
export const zinc = {
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
} as const

export const semantic = {
  success: { 100: '#DCFCE7', 500: '#16A34A' },
  warning: { 100: '#FEF3C7', 500: '#F59E0B' },
  danger: { 100: '#FEE2E2', 500: '#DC2626' },
  info: { 100: '#E0F2FE', 500: '#0284C7' },
} as const

/**
 * Board status palette — see docs/01-UI-UX.md §2 and docs/10-BOARD-AUTOMATION.md.
 */
export const boardStatus = {
  todefine: '#A8A8A2',
  progress: '#8B5CF6',
  review: '#F59E0B',
  aligned: '#14B8A6',
  posted: '#06B6D4',
  paid: '#16A34A',
  blocked: '#DC2626',
} as const

/**
 * Spacing scale, 4px base. docs/01-UI-UX.md §4.
 */
export const space = {
  0: '0px',
  1: '4px',
  2: '8px',
  3: '12px',
  4: '16px',
  5: '20px',
  6: '24px',
  8: '32px',
  10: '40px',
  12: '48px',
  16: '64px',
  20: '80px',
  24: '96px',
} as const

/**
 * Border radius scale. docs/01-UI-UX.md §5.
 */
export const radius = {
  none: '0px',
  sm: '4px',
  md: '6px',
  lg: '8px',
  xl: '12px',
  full: '9999px',
} as const

/**
 * Type scale — Inter. docs/01-UI-UX.md §3.
 */
export const typography = {
  fontFamily: {
    sans: '"Inter", system-ui, -apple-system, sans-serif',
    mono: '"JetBrains Mono", ui-monospace, monospace',
  },
  scale: {
    'display-xl': { size: '48px', lineHeight: '56px', weight: 700, tracking: '-0.02em' },
    display: { size: '36px', lineHeight: '44px', weight: 700, tracking: '-0.02em' },
    'heading-1': { size: '28px', lineHeight: '36px', weight: 600, tracking: '-0.01em' },
    'heading-2': { size: '22px', lineHeight: '30px', weight: 600, tracking: '-0.005em' },
    'heading-3': { size: '18px', lineHeight: '26px', weight: 600, tracking: '0' },
    'heading-4': { size: '16px', lineHeight: '24px', weight: 600, tracking: '0' },
    'body-lg': { size: '16px', lineHeight: '24px', weight: 400, tracking: '0' },
    body: { size: '14px', lineHeight: '22px', weight: 400, tracking: '0' },
    'body-sm': { size: '13px', lineHeight: '20px', weight: 400, tracking: '0' },
    caption: { size: '12px', lineHeight: '18px', weight: 500, tracking: '0.01em' },
    overline: { size: '11px', lineHeight: '16px', weight: 600, tracking: '0.08em' },
    mono: { size: '13px', lineHeight: '20px', weight: 500, tracking: '0' },
  },
} as const

export type BrandTokens = typeof brand
export type ZincTokens = typeof zinc
export type SemanticTokens = typeof semantic
export type BoardStatus = typeof boardStatus
export type SpaceTokens = typeof space
export type RadiusTokens = typeof radius
export type TypographyTokens = typeof typography
