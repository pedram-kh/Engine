/**
 * AH-114 (S0.3) — the agency app's two Vuetify themes, `agency-dark` and
 * `agency-light`.
 *
 * WHY runtime registration (option C2, plan §1): the agency restyle must not
 * change a single byte the creator app renders. `buildVuetifyOptions` is pinned
 * (`tests/unit/plugins/vuetify.spec.ts`) and `@catalyst/design-tokens` is frozen
 * (`shared-tokens-frozen.spec.ts`), so the agency palette is registered at
 * runtime, from this agency-only module, when `AgencyLayout` sets up — never at
 * module scope, so a creator-only session never sees these themes in its
 * Vuetify stylesheet.
 *
 * Shape rules (each learned in the S0 spike, each pinned by the shape spec):
 *   - `variables` must be present. A runtime-registered theme without it makes
 *     `app.use(vuetify)` throw and the app never mounts. It also carries the
 *     Vuetify emphasis/state opacities (copied from the live base theme, never
 *     hand-typed) and the overlay shadow.
 *   - Both themes define the SAME key set, so Vuetify's `.bg-*` / `.text-*`
 *     utilities and `--v-theme-*` variables resolve identically in either.
 *   - Brand and status FILLS are identical in both themes. Only neutrals, and
 *     the status colours used as TEXT (`*-text`), differ.
 *
 * Palette source: dark is the Figma agency palette (`nativePalette`) — the same
 * file the creator native palette comes from — with the four deviations the
 * accepted inventory records (§2.2, ruling 11): the Figma values for tertiary
 * text and the info/danger/ai pill text fail AA-normal on their surfaces, so
 * each is lifted one step. Light is re-derived from the same tokens (inventory
 * §2.2). Every pairing is asserted in `agency-color-system-parity.spec.ts`.
 */

import { unref, type Ref } from 'vue'
import type { ThemeDefinition, ThemeInstance } from 'vuetify'
import { lightTheme } from '@catalyst/design-tokens/vuetify'
import { nativeCssVariables, nativeDarkTheme, nativePalette } from '@catalyst/design-tokens/native'

import { vuetify as appVuetify } from '@/plugins/vuetify'

export const AGENCY_DARK_THEME = 'agency-dark'
export const AGENCY_LIGHT_THEME = 'agency-light'

export type AgencyThemeName = typeof AGENCY_DARK_THEME | typeof AGENCY_LIGHT_THEME

/** The Vuetify theme an agency session renders for a given base theme. */
export function agencyThemeFor(base: 'light' | 'dark'): AgencyThemeName {
  return base === 'light' ? AGENCY_LIGHT_THEME : AGENCY_DARK_THEME
}

/**
 * Colours that exist only in the agency themes (on top of the Vuetify slots).
 * `*-text` are the status/brand colours AS TEXT on a neutral or on their own
 * 15% tint; they equal the fill only where the fill already passes AA-normal.
 */
const AGENCY_DARK_EXTRAS = {
  sidebar: nativePalette['bg-sidebar'],
  topbar: nativePalette['bg-topbar'],
  // Figma #6e6e76 is 3.9:1 on raised — lifted to #8a8a92 (4.58 on raised).
  'text-tertiary': '#8a8a92',
  'text-inverse': nativePalette['text-inverse'],
  'status-neutral': nativePalette['text-secondary'],
  // Figma #8b6cff fill is kept; only its use as text is lifted.
  'status-ai': '#8b6cff',
  'primary-text': nativePalette['accent-primary'],
  'success-text': nativePalette['status-success'],
  // Figma #4c8dff / #f0506e / #8b6cff as pill text fail AA — lifted.
  'info-text': '#70a4ff',
  'warning-text': nativePalette['status-warning'],
  'error-text': '#f3738b',
  'neutral-text': nativePalette['text-secondary'],
  'ai-text': '#a289ff',
} as const

const AGENCY_LIGHT_COLORS = {
  ...lightTheme.colors,
  background: '#f4f4f5',
  'on-background': '#18181b',
  surface: '#ffffff',
  'surface-bright': '#f4f4f5',
  'surface-light': '#ffffff',
  'surface-variant': '#fafafa',
  'on-surface': '#18181b',
  'on-surface-variant': '#52525b',
  // Brand and status FILLS are the dark values — one brand, two canvases.
  primary: nativePalette['accent-primary'],
  'primary-darken-1': '#2fb487',
  'on-primary': nativePalette['text-on-accent'],
  accent: nativeDarkTheme.colors.accent,
  secondary: '#f4f4f5',
  'on-secondary': '#18181b',
  error: nativePalette['status-danger'],
  'on-error': nativePalette['text-on-accent'],
  info: nativePalette['status-info'],
  'on-info': nativePalette['text-on-accent'],
  success: nativePalette['status-success'],
  'on-success': nativePalette['text-on-accent'],
  warning: nativePalette['status-warning'],
  'on-warning': nativePalette['text-on-accent'],
  'border-color': '#e4e4e7',
  outline: '#d4d4d8',
  'outline-variant': '#e4e4e7',
  'primary-container': '#e4f8f1',
  'error-container': '#fee2e2',
  sidebar: '#fafafa',
  topbar: '#ffffff',
  'text-tertiary': '#6b6b74',
  'text-inverse': nativePalette['text-inverse'],
  'status-neutral': nativePalette['text-secondary'],
  'status-ai': '#8b6cff',
  // The brand mint fails AA as text on white; the deep-mint text value is the
  // only light-specific brand colour.
  'primary-text': '#0b7a5a',
  // Inventory §2.2 measured these on a white tint only. A pill on the light
  // canvas (#f4f4f5) is darker, and the §2.2 values for success, warning and
  // danger landed at 4.43 / 4.49 / 4.42 there. Each is darkened one step to
  // clear 4.5 on the canvas (4.61 / 4.64 / 4.60); on white they are 5.0.
  'success-text': '#157648',
  'info-text': '#2457c5',
  'warning-text': '#975900',
  'error-text': '#ba2946',
  'neutral-text': '#52525b',
  'ai-text': '#6240d8',
} as const

/** Overlay (menu / dialog / sheet) shadow — `--v-shadow-overlay` in scope. */
const OVERLAY_SHADOW = {
  dark: nativeCssVariables['shadow-overlay'],
  light: '0 12px 32px rgba(24, 24, 27, 0.14)',
} as const

export type ThemeRegistry = ThemeInstance['themes']['value']

/**
 * Anything that carries a Vuetify theme registry: the plugin instance (a ref) or
 * the component-level `$vuetify` (reactive, so the ref arrives already unwrapped).
 */
export interface ThemeHost {
  readonly theme: { readonly themes: Ref<ThemeRegistry> | ThemeRegistry }
}

export interface AgencyThemeBase {
  readonly light?: ThemeDefinition
  readonly dark?: ThemeDefinition
}

type Variables = Record<string, string | number>

function variablesOf(definition: ThemeDefinition | undefined): Variables {
  return { ...((definition?.variables as Variables | undefined) ?? {}) }
}

/**
 * Pure builder. `base` is the live `light` / `dark` pair of the Vuetify
 * instance, used only for its `variables` (emphasis and state opacities).
 */
export function buildAgencyThemes(base: AgencyThemeBase): Record<AgencyThemeName, ThemeDefinition> {
  return {
    [AGENCY_DARK_THEME]: {
      dark: true,
      colors: { ...nativeDarkTheme.colors, ...AGENCY_DARK_EXTRAS },
      variables: { ...variablesOf(base.dark), 'shadow-overlay': OVERLAY_SHADOW.dark },
    },
    [AGENCY_LIGHT_THEME]: {
      dark: false,
      colors: { ...AGENCY_LIGHT_COLORS },
      variables: { ...variablesOf(base.light), 'shadow-overlay': OVERLAY_SHADOW.light },
    },
  }
}

/** The two palettes, exposed for the specs. */
export function agencyColors(): Record<AgencyThemeName, Record<string, string>> {
  const themes = buildAgencyThemes({ light: {}, dark: {} })
  return {
    [AGENCY_DARK_THEME]: { ...themes[AGENCY_DARK_THEME].colors } as Record<string, string>,
    [AGENCY_LIGHT_THEME]: { ...themes[AGENCY_LIGHT_THEME].colors } as Record<string, string>,
  }
}

/**
 * Register `agency-dark` and `agency-light` on a Vuetify instance. Idempotent.
 * Call from `AgencyLayout`'s `setup()` only (see the header).
 */
export function registerAgencyThemes(instance: ThemeHost = appVuetify): void {
  const themes = unref(instance.theme.themes)
  if (themes[AGENCY_DARK_THEME] && themes[AGENCY_LIGHT_THEME]) return

  const built = buildAgencyThemes({ light: themes.light, dark: themes.dark })
  for (const name of [AGENCY_DARK_THEME, AGENCY_LIGHT_THEME] as const) {
    themes[name] = built[name] as (typeof themes)[string]
  }
}

/**
 * Remove both agency themes from the instance (no-op if absent). The layout calls
 * this when it unmounts, so a session that moves from the agency app to the creator
 * app (sign out, then a creator signs in, no reload) is left with exactly the
 * creator theme stylesheet it started with.
 */
export function unregisterAgencyThemes(instance: ThemeHost = appVuetify): void {
  const themes = unref(instance.theme.themes)
  delete themes[AGENCY_DARK_THEME]
  delete themes[AGENCY_LIGHT_THEME]
}
