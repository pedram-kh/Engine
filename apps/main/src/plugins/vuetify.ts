/**
 * Vuetify plugin instance for the main SPA.
 *
 * Theme registration (chunk 8.1; dark default since Sprint 3.5 Chunk 1):
 *   Both `light` and `dark` palettes are registered. The main SPA defaults
 *   to `dark` as of Sprint 3.5 Chunk 1 — the Catalyst Engine v2 brand is
 *   dark-first, matching admin. Theme switching is owned by the `useTheme`
 *   composable (`@/composables/useTheme`) — components MUST NOT mutate
 *   `theme.global.name.value` directly. Enforcement lives in
 *   `tests/unit/architecture/use-theme-is-sot.spec.ts`.
 *
 *   The theme keys (`light`, `dark`) and definitions (`lightTheme`,
 *   `darkTheme`) come from `@catalyst/design-tokens/vuetify`. Sprint 3.5
 *   Chunk 1 Refinement R1 preserved these Vuetify-standard keys (the
 *   kickoff's proposed `engineCLight` / `engineCDark` rename was
 *   reinterpreted back to `light` / `dark` — the brand identity lives in
 *   the theme values, not the key names).
 *
 * AH-100 D4 — inside the Capacitor creator app the `dark` theme's VALUES
 * are swapped for the Figma palette (`nativeDarkTheme`) and buttons take
 * Figma's 8px radius. The theme keys, `useTheme`, `useThemePreference`
 * and their admin mirrors are unchanged; the web build gets exactly the
 * object it always had (pinned by `tests/unit/plugins/vuetify.spec.ts`).
 * Figma's button heights need no override: Md 36px is Vuetify's default
 * size and Lg 44px is `size="large"`.
 */

import 'vuetify/styles'
// Material Design Icons webfont. Vuetify 3 defaults to the `mdi` icon set
// but ships NO glyphs of its own — without this stylesheet every
// `<v-icon icon="mdi-…">` (and Vuetify's own `$`-aliased control icons)
// renders as an empty box. Required app-wide; do not remove.
import '@mdi/font/css/materialdesignicons.css'
import { createVuetify, type ThemeDefinition, type VuetifyOptions } from 'vuetify'
import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'
import { nativeDarkTheme } from '@catalyst/design-tokens/native'

import { isNativePlatform } from '@/core/platform/isNativePlatform'

const light: ThemeDefinition = lightTheme
const dark: ThemeDefinition = darkTheme
const nativeDark: ThemeDefinition = nativeDarkTheme

export function buildVuetifyOptions(isNative: boolean): VuetifyOptions {
  return {
    theme: {
      defaultTheme: 'dark',
      themes: {
        light,
        dark: isNative ? nativeDark : dark,
      },
    },
    defaults: {
      VBtn: {
        variant: 'flat',
        rounded: 0,
        // Sprint 3.5 Chunk 2 (D-fork-a + R1): the button radius is the single
        // styling SOT here — CButton no longer re-applies it inline. The
        // literal `6px` was tokenised to `var(--radius-md)` (the existing
        // dormant radius scale in @catalyst/design-tokens/tokens.css, now
        // consumed rather than re-authored).
        style: isNative
          ? 'border-radius: var(--native-radius-lg); text-transform: none;'
          : 'border-radius: var(--radius-md); text-transform: none;',
      },
      VTextField: {
        variant: 'outlined',
        density: 'compact',
      },
      VSelect: {
        variant: 'outlined',
        density: 'compact',
      },
      VAutocomplete: {
        variant: 'outlined',
        density: 'compact',
      },
    },
  }
}

export const vuetify = createVuetify(buildVuetifyOptions(isNativePlatform()))
