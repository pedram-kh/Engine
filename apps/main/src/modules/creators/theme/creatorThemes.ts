/**
 * AH-115 (L0.4) — the creator app's two Vuetify themes, `creator-dark` and `creator-light`.
 *
 * WHY runtime registration (the AH-114 agency pattern, mirrored): the creator web adopts the
 * native look, and the AGENCY shell must stay byte-identical. `buildVuetifyOptions` and
 * `@catalyst/design-tokens` are hash-pinned, and swapping `nativeDarkTheme` into the base
 * `dark` slot on web would change the agency's inert base theme sheet. So the creator palette
 * is registered at runtime, from the creator, onboarding and auth layouts' `setup()`, and
 * removed again when the last of them unmounts. A session that never mounts one of those
 * layouts (every agency session) never sees these themes in its Vuetify stylesheet.
 *
 * Creator code must not import `modules/agency/` (`agency-theme-isolation`), so the light
 * values below are a copy of the agency light palette, held together by
 * `creator-light-parity.spec.ts` (the specs may import both).
 *
 * Shape rules (the same ones the agency module learned in its S0 spike):
 *   - `variables` must be present. A runtime-registered theme without it makes
 *     `app.use(vuetify)` throw and the app never mounts.
 *   - Both themes define the SAME key set where the agency does, so Vuetify's `.bg-*` /
 *     `.text-*` utilities and `--v-theme-*` variables resolve identically in either.
 *   - `creator-dark` IS `nativeDarkTheme`: the colours the phone app renders, key for key.
 */

import { unref, type Ref } from 'vue'
import type { ThemeDefinition, ThemeInstance } from 'vuetify'
import { lightTheme } from '@catalyst/design-tokens/vuetify'
import { nativeDarkTheme, nativePalette } from '@catalyst/design-tokens/native'

import { vuetify as appVuetify } from '@/plugins/vuetify'

export const CREATOR_DARK_THEME = 'creator-dark'
export const CREATOR_LIGHT_THEME = 'creator-light'

export type CreatorThemeName = typeof CREATOR_DARK_THEME | typeof CREATOR_LIGHT_THEME

/** The Vuetify theme a creator session renders for a given base theme. */
export function creatorThemeFor(base: 'light' | 'dark'): CreatorThemeName {
  return base === 'light' ? CREATOR_LIGHT_THEME : CREATOR_DARK_THEME
}

/**
 * The light palette (inventory §3: derived, one remap). Brand and status FILLS are the dark
 * values (one brand, two canvases); only neutrals and the status colours used as TEXT differ.
 * A copy of the agency light palette; `creator-light-parity.spec.ts` keeps the two equal.
 */
const CREATOR_LIGHT_COLORS = {
  ...lightTheme.colors,
  background: '#f4f4f5',
  'on-background': '#18181b',
  surface: '#ffffff',
  'surface-bright': '#f4f4f5',
  'surface-light': '#ffffff',
  'surface-variant': '#fafafa',
  'on-surface': '#18181b',
  'on-surface-variant': '#52525b',
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
  'primary-text': '#0b7a5a',
  'success-text': '#157648',
  'info-text': '#2457c5',
  'warning-text': '#975900',
  'error-text': '#ba2946',
  'error-strong': '#d6304f',
  'neutral-text': '#52525b',
  'ai-text': '#6240d8',
} as const

export type ThemeRegistry = ThemeInstance['themes']['value']

/**
 * Anything that carries a Vuetify theme registry: the plugin instance (a ref) or the
 * component-level `$vuetify` (reactive, so the ref arrives already unwrapped).
 */
export interface ThemeHost {
  readonly theme: { readonly themes: Ref<ThemeRegistry> | ThemeRegistry }
}

export interface CreatorThemeBase {
  readonly light?: ThemeDefinition
  readonly dark?: ThemeDefinition
}

type Variables = Record<string, string | number>

function variablesOf(definition: ThemeDefinition | undefined): Variables {
  return { ...((definition?.variables as Variables | undefined) ?? {}) }
}

/**
 * Pure builder. `base` is the live `light` / `dark` pair of the Vuetify instance, used only
 * for its `variables` (emphasis and state opacities).
 */
export function buildCreatorThemes(
  base: CreatorThemeBase,
): Record<CreatorThemeName, ThemeDefinition> {
  return {
    [CREATOR_DARK_THEME]: {
      dark: true,
      colors: { ...nativeDarkTheme.colors },
      variables: variablesOf(base.dark),
    },
    [CREATOR_LIGHT_THEME]: {
      dark: false,
      colors: { ...CREATOR_LIGHT_COLORS },
      variables: variablesOf(base.light),
    },
  }
}

/** The two palettes, exposed for the specs. */
export function creatorColors(): Record<CreatorThemeName, Record<string, string>> {
  const themes = buildCreatorThemes({ light: {}, dark: {} })
  return {
    [CREATOR_DARK_THEME]: { ...themes[CREATOR_DARK_THEME].colors } as Record<string, string>,
    [CREATOR_LIGHT_THEME]: { ...themes[CREATOR_LIGHT_THEME].colors } as Record<string, string>,
  }
}

/**
 * Register `creator-dark` and `creator-light` on a Vuetify instance. Idempotent. Call from the
 * creator-side layouts' `setup()` only (through `useCreatorTheme`).
 */
export function registerCreatorThemes(instance: ThemeHost = appVuetify): void {
  const themes = unref(instance.theme.themes)
  if (themes[CREATOR_DARK_THEME] && themes[CREATOR_LIGHT_THEME]) return

  const built = buildCreatorThemes({ light: themes.light, dark: themes.dark })
  for (const name of [CREATOR_DARK_THEME, CREATOR_LIGHT_THEME] as const) {
    themes[name] = built[name] as (typeof themes)[string]
  }
}

/**
 * Remove both creator themes from the instance (no-op if absent), so a session that moves
 * from the creator app to the agency app (sign out, an agency user signs in, no reload) is
 * left with exactly the theme stylesheet it started with.
 */
export function unregisterCreatorThemes(instance: ThemeHost = appVuetify): void {
  const themes = unref(instance.theme.themes)
  delete themes[CREATOR_DARK_THEME]
  delete themes[CREATOR_LIGHT_THEME]
}
