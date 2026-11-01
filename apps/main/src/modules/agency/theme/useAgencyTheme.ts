/**
 * AH-114 (S1.1) — the agency layout's theme hook.
 *
 * Called from `AgencyLayout`'s `setup()` and nowhere else. It registers the two
 * agency Vuetify themes on the Vuetify instance this app was installed with, then
 * returns the theme `<v-app :theme>` should use: `agency-dark` or `agency-light`,
 * derived from the app's single theme source of truth (`useTheme().currentTheme`,
 * which `useThemePreference()` keeps in step with the stored preference).
 *
 * Registration is deliberately inside `setup()` and not at module scope: a session
 * that never mounts the agency layout (every creator and onboarding session) never
 * registers the themes, so its Vuetify theme stylesheet is byte-for-byte what it
 * was before this chunk (measured at S1.1; pinned by the shape spec). The themes
 * are removed again when the last agency layout unmounts, so a session that goes
 * from the agency app to the creator app without a reload is left with the same
 * stylesheet too.
 *
 * The instance is the one the app installed (injected), not the
 * `@/plugins/vuetify` singleton, so a test app with its own Vuetify gets its own
 * registration. `useTheme` stays the single import boundary for Vuetify's theme
 * composable (`use-theme-is-sot.spec.ts`).
 */

import { computed, inject, onBeforeUnmount, unref, type ComputedRef } from 'vue'

import { useTheme } from '@/composables/useTheme'

import {
  agencyThemeFor,
  registerAgencyThemes,
  unregisterAgencyThemes,
  type AgencyThemeName,
  type ThemeHost,
} from './agencyThemes'

/**
 * Vuetify provides its theme instance under this registry symbol
 * (`vuetify/lib/composables/theme.js`, `ThemeSymbol = Symbol.for('vuetify:theme')`).
 * It is a global-registry symbol, so it can be injected without importing Vuetify's
 * `useTheme` (which `use-theme-is-sot.spec.ts` reserves to `@/composables/useTheme`).
 */
const VUETIFY_THEME_KEY = Symbol.for('vuetify:theme')

/** Mounted agency layouts per theme registry; the themes go when the last one does. */
const liveLayouts = new WeakMap<object, number>()

export function useAgencyTheme(): ComputedRef<AgencyThemeName> {
  const theme = inject<ThemeHost['theme'] | null>(VUETIFY_THEME_KEY, null)
  if (theme === null) {
    throw new Error(
      'useAgencyTheme() must run inside a component of an app that installed Vuetify.',
    )
  }
  registerAgencyThemes({ theme })

  const registry = unref(theme.themes)
  liveLayouts.set(registry, (liveLayouts.get(registry) ?? 0) + 1)
  onBeforeUnmount(() => {
    const remaining = (liveLayouts.get(registry) ?? 1) - 1
    liveLayouts.set(registry, remaining)
    if (remaining <= 0) unregisterAgencyThemes({ theme })
  })

  const { currentTheme } = useTheme()
  return computed(() => agencyThemeFor(currentTheme.value))
}
