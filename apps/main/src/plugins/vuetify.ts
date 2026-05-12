/**
 * Vuetify plugin instance for the main SPA.
 *
 * Theme registration (chunk 8.1):
 *   Both `light` and `dark` palettes are registered. The main SPA defaults
 *   to `light`. Theme switching is owned by the `useTheme` composable
 *   (`@/composables/useTheme`) — components MUST NOT mutate
 *   `theme.global.name.value` directly. Enforcement lives in
 *   `tests/unit/architecture/use-theme-is-sot.spec.ts`.
 *
 *   The theme keys (`light`, `dark`) and definitions (`lightTheme`,
 *   `darkTheme`) come from `@catalyst/design-tokens/vuetify`. The exports
 *   were renamed from `catalystLightTheme` / `catalystDarkTheme` in
 *   chunk 8.1 to honor Vuetify's standard naming.
 */

import 'vuetify/styles'
import { createVuetify, type ThemeDefinition } from 'vuetify'
import { lightTheme, darkTheme } from '@catalyst/design-tokens/vuetify'

const light: ThemeDefinition = lightTheme
const dark: ThemeDefinition = darkTheme

export const vuetify = createVuetify({
  theme: {
    defaultTheme: 'light',
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
