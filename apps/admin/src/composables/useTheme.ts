/**
 * `useTheme` — admin SPA composable that exposes the active Vuetify theme
 * and a typed `setTheme` action. This module is the SINGLE source of
 * truth for theme management in the admin SPA: components MUST consume
 * the theme through this wrapper and MUST NOT reach into Vuetify's
 * `theme.global.name.value` directly.
 *
 * Enforcement (chunk 8.1):
 *   `tests/unit/architecture/use-theme-is-sot.spec.ts` walks every
 *   `*.{ts,vue}` under `src/` and refuses any direct mutation of
 *   `theme.global.name` or any direct import of `useTheme` from
 *   `vuetify` outside this file.
 *
 * Composable shape (per chunk-8 kickoff):
 *   - `currentTheme: ComputedRef<ThemeName>` — reactive read of the
 *     active theme key.
 *   - `setTheme(name: ThemeName): void` — switch to the named theme.
 *   - `availableThemes: readonly ['light', 'dark']` — enumerable list.
 *
 * Persistence:
 *   None. Group 1 explicitly defers persistence (sessionStorage / cookie
 *   / server-stored preference) and toggle UI to Group 2. The composable
 *   reflects whatever Vuetify's `defaultTheme` was set to in
 *   `src/plugins/vuetify.ts` until `setTheme` is called. The admin SPA
 *   defaults to `dark` (operator-preferred default).
 *
 * Per-SPA mirror (chunk 7.2 D2 standing standard, "Path-(b) mirror is
 * the default for shared-shape components across SPAs; @catalyst/ui
 * extraction waits for a third consumer"):
 *   The main SPA mirrors this composable verbatim at
 *   `apps/main/src/composables/useTheme.ts`. Surface parity is asserted
 *   by mirrored architecture and unit tests; both files MUST stay in
 *   structural lockstep.
 */

import { computed, type ComputedRef } from 'vue'
import { useTheme as useVuetifyTheme } from 'vuetify'

export const availableThemes = ['light', 'dark'] as const

export type ThemeName = (typeof availableThemes)[number]

export interface ThemeManager {
  currentTheme: ComputedRef<ThemeName>
  setTheme: (name: ThemeName) => void
  availableThemes: typeof availableThemes
}

export function useTheme(): ThemeManager {
  const vuetifyTheme = useVuetifyTheme()

  const currentTheme = computed<ThemeName>(() => vuetifyTheme.global.name.value as ThemeName)

  function setTheme(name: ThemeName): void {
    // `theme.change(name)` is Vuetify 3.7+'s preferred mutation API;
    // direct assignment to `theme.global.name.value` is deprecated and
    // logs a console warning at runtime. The architecture test enforces
    // the SOT (no direct mutations or imports outside this file).
    vuetifyTheme.change(name)
  }

  return {
    currentTheme,
    setTheme,
    availableThemes,
  }
}
