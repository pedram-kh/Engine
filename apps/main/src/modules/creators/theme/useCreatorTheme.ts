/**
 * AH-115 (L0.4) — the creator-side layouts' theme hook (mirror of `useAgencyTheme`).
 *
 * Called from the `setup()` of the creator, onboarding and auth layouts and nowhere else.
 * It registers the two creator Vuetify themes on the Vuetify instance this app was installed
 * with, then returns the theme `<v-app :theme>` should use: `creator-dark` or `creator-light`,
 * derived from the app's single theme source of truth (`useTheme().currentTheme`).
 *
 * Registration is deliberately inside `setup()` and not at module scope: an agency session
 * never mounts these layouts, so its Vuetify theme stylesheet stays byte-for-byte what it is
 * today. The themes are removed again when the LAST creator-side layout unmounts (a
 * reference count per theme registry, because the auth and onboarding layouts can overlap
 * a creator layout during a route change).
 *
 * The instance is the one the app installed (injected), not the `@/plugins/vuetify`
 * singleton, so a test app with its own Vuetify gets its own registration. `useTheme` stays
 * the single import boundary for Vuetify's theme composable (`use-theme-is-sot.spec.ts`).
 */

import { computed, inject, onBeforeUnmount, unref, type ComputedRef } from 'vue'

import { useTheme } from '@/composables/useTheme'

import {
  creatorThemeFor,
  registerCreatorThemes,
  unregisterCreatorThemes,
  type CreatorThemeName,
  type ThemeHost,
} from './creatorThemes'

/** Vuetify's theme instance key (a global-registry symbol; see `useAgencyTheme`). */
const VUETIFY_THEME_KEY = Symbol.for('vuetify:theme')

/** Mounted creator-side layouts per theme registry; the themes go when the last one does. */
const liveLayouts = new WeakMap<object, number>()

export function useCreatorTheme(): ComputedRef<CreatorThemeName> {
  const theme = inject<ThemeHost['theme'] | null>(VUETIFY_THEME_KEY, null)
  if (theme === null) {
    throw new Error(
      'useCreatorTheme() must run inside a component of an app that installed Vuetify.',
    )
  }
  registerCreatorThemes({ theme })

  const registry = unref(theme.themes)
  liveLayouts.set(registry, (liveLayouts.get(registry) ?? 0) + 1)
  onBeforeUnmount(() => {
    const remaining = (liveLayouts.get(registry) ?? 1) - 1
    liveLayouts.set(registry, remaining)
    if (remaining <= 0) unregisterCreatorThemes({ theme })
  })

  const { currentTheme } = useTheme()
  return computed(() => creatorThemeFor(currentTheme.value))
}
