/**
 * Vuetify plugin instance for the admin SPA.
 *
 * Theme registration (chunk 8.1):
 *   Both `light` and `dark` palettes are registered. The admin SPA defaults
 *   to `dark` — admin operators predominantly work in low-light contexts
 *   and the dark palette is the operator-preferred default. Theme
 *   switching is owned by the `useTheme` composable
 *   (`@/composables/useTheme`) — components MUST NOT mutate
 *   `theme.global.name.value` directly. Enforcement lives in
 *   `tests/unit/architecture/use-theme-is-sot.spec.ts`.
 *
 *   The theme keys (`light`, `dark`) and definitions (`lightTheme`,
 *   `darkTheme`) come from `@catalyst/design-tokens/vuetify`. Sprint 3.5
 *   Chunk 1 Refinement R1 preserved these Vuetify-standard keys (the
 *   kickoff's proposed `engineCLight` / `engineCDark` rename was
 *   reinterpreted back to `light` / `dark` — the brand identity lives in
 *   the theme values, not the key names).
 */

import 'vuetify/styles'
// Material Design Icons webfont. Vuetify 3 defaults to the `mdi` icon set
// but ships NO glyphs of its own — without this stylesheet every
// `<v-icon icon="mdi-…">` (and Vuetify's own `$`-aliased control icons)
// renders as an empty box. Required app-wide; do not remove.
import '@mdi/font/css/materialdesignicons.css'
import { createVuetify, type ThemeDefinition } from 'vuetify'
import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'

const light: ThemeDefinition = lightTheme
const dark: ThemeDefinition = darkTheme

export const vuetify = createVuetify({
  theme: {
    defaultTheme: 'dark',
    themes: {
      light,
      dark,
    },
  },
  defaults: {
    VBtn: {
      variant: 'flat',
      rounded: 0,
      style: 'border-radius: 6px; text-transform: none;',
    },
    VTextField: {
      variant: 'outlined',
      density: 'compact',
    },
    VSelect: {
      variant: 'outlined',
      density: 'compact',
    },
  },
})
