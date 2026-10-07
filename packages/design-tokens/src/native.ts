/**
 * AH-100 D4 — the creator app's token set, extracted from the Figma file
 * `1Dd0NdZ55EXh58uNyvf9Bi` (variables + frames 01, 07, 08, 12).
 *
 * This is a SEPARATE token set, not an edit of the web one: the web
 * `darkTheme` / `lightTheme` and `tokens.css` are untouched. Where Figma
 * and the web system disagree (mint `accent/primary` vs the brand teal,
 * the status hues, `radius/xl` 10 vs 12), the native value wins inside
 * the app only. The full conflict table is in the AH-100 review.
 *
 * Two consumers:
 *   - `nativeDarkTheme` — swapped in for the `dark` Vuetify theme when
 *     the SPA boots inside Capacitor (`buildVuetifyOptions(true)`), so
 *     every `color="primary"` / `rgb(var(--v-theme-surface))` already in
 *     the components picks up the Figma palette with no component edit.
 *   - `native.css` — the tokens Vuetify has no slot for, as `--native-*`
 *     variables scoped under `.catalyst-native`. `nativeCssVariables`
 *     below is its TS mirror; `native.spec.ts` keeps the two in lockstep.
 */

import type { CatalystThemeDefinition } from './vuetify'
import { darkTheme } from './vuetify'

export const nativePalette = {
  'bg-canvas': '#0b0b0c',
  'bg-sidebar': '#151516',
  'bg-topbar': '#18181a',
  'bg-surface': '#1b1b1d',
  'bg-surface-raised': '#232326',
  'border-default': '#2e2e33',
  'border-strong': '#3a3a40',
  'text-primary': '#ededed',
  'text-secondary': '#a0a0a8',
  'text-tertiary': '#6e6e76',
  'text-on-accent': '#0b0b0c',
  'text-inverse': '#ffffff',
  'accent-primary': '#3ecf9e',
  'accent-primary-subtle': '#163a30',
  'status-success': '#34c77b',
  'status-danger': '#f0506e',
  'status-warning': '#f5a524',
  'status-info': '#4c8dff',
  'usp-5': '#3ecf9e',
  'usp-6': '#f472b6',
} as const

/**
 * The `dark` theme inside the app. Starts from the web dark theme so
 * every slot Figma does not define (accent, error-container, …) keeps a
 * registered value, then overrides each slot Figma does define.
 *
 * Foregrounds on the status fills are the near-black `text/on-accent`
 * rather than white: white on `#f0506e` / `#34c77b` / `#f5a524` /
 * `#4c8dff` is below AA-normal, near-black clears it on all four.
 * `primary-darken-1` has no Figma source; it is a hand-darkened mint.
 */
export const nativeDarkTheme: CatalystThemeDefinition = {
  dark: true,
  colors: {
    ...darkTheme.colors,
    background: nativePalette['bg-canvas'],
    'on-background': nativePalette['text-primary'],
    surface: nativePalette['bg-surface'],
    'surface-bright': nativePalette['bg-surface-raised'],
    'surface-light': nativePalette['bg-surface'],
    'surface-variant': nativePalette['bg-sidebar'],
    'on-surface': nativePalette['text-primary'],
    'on-surface-variant': nativePalette['text-secondary'],
    primary: nativePalette['accent-primary'],
    'primary-darken-1': '#2fb487',
    'on-primary': nativePalette['text-on-accent'],
    secondary: nativePalette['bg-surface-raised'],
    'on-secondary': nativePalette['text-primary'],
    error: nativePalette['status-danger'],
    'on-error': nativePalette['text-on-accent'],
    info: nativePalette['status-info'],
    'on-info': nativePalette['text-on-accent'],
    success: nativePalette['status-success'],
    'on-success': nativePalette['text-on-accent'],
    warning: nativePalette['status-warning'],
    'on-warning': nativePalette['text-on-accent'],
    'border-color': nativePalette['border-default'],
    outline: nativePalette['border-strong'],
    'outline-variant': nativePalette['border-default'],
    'primary-container': nativePalette['accent-primary-subtle'],
  },
}

/**
 * Every `--native-*` declaration in `native.css`, keyed by the variable
 * name without the `--native-` prefix.
 */
export const nativeCssVariables: Readonly<Record<string, string>> = {
  ...nativePalette,
  'status-success-subtle': 'rgb(52 199 123 / 0.16)',
  'status-danger-subtle': 'rgb(240 80 110 / 0.16)',
  'status-warning-subtle': 'rgb(245 165 36 / 0.16)',
  'status-info-subtle': 'rgb(76 141 255 / 0.16)',
  'radius-sm': '4px',
  'radius-md': '6px',
  'radius-lg': '8px',
  'radius-xl': '10px',
  'radius-2xl': '12px',
  'radius-full': '999px',
  'shadow-overlay': '0 12px 32px #0000008c',
  'type-display': '500 28px/36px var(--font-sans)',
  'type-h3': '600 18px/24px var(--font-sans)',
  'type-h4': '600 15px/20px var(--font-sans)',
  'type-body': '400 14px/20px var(--font-sans)',
  'type-body-strong': '500 14px/20px var(--font-sans)',
  'type-body-small': '400 13px/19px var(--font-sans)',
  'type-caption': '400 12px/16px var(--font-sans)',
  'type-caption-strong': '500 12px/16px var(--font-sans)',
  'type-overline': '600 11px/14px var(--font-sans)',
  'type-overline-tracking': '0.6px',
  'type-button-primary': '600 14px/20px var(--font-sans)',
  'type-button': '500 14px/20px var(--font-sans)',
  'safe-top': 'var(--safe-area-inset-top, env(safe-area-inset-top, 0px))',
  'safe-right': 'var(--safe-area-inset-right, env(safe-area-inset-right, 0px))',
  'safe-bottom': 'var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px))',
  'safe-left': 'var(--safe-area-inset-left, env(safe-area-inset-left, 0px))',
}
