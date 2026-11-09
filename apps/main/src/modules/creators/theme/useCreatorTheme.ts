/**
 * AH-115 (L0.4, L1.1) — the creator-side layouts' theme hook (mirror of `useAgencyTheme`).
 *
 * Called from the `setup()` of the creator, onboarding and auth layouts and nowhere else.
 * It registers the two creator Vuetify themes on the Vuetify instance this app was installed
 * with, then returns the theme `<v-app :theme>` should use: `creator-dark` or `creator-light`,
 * derived from the app's single theme source of truth (`useTheme().currentTheme`).
 *
 * Registration is deliberately inside `setup()` and not at module scope: an agency session
 * never mounts these layouts, so its Vuetify theme stylesheet stays byte-for-byte what it is
 * today. The themes are removed again when the LAST holder lets go (a reference count per
 * theme registry, because the auth and onboarding layouts can overlap a creator layout
 * during a route change).
 *
 * `enabled` makes the hold reactive, for `AuthLayout`: that one layout serves creator-reachable
 * routes (the look applies) AND screens an agency user can reach (they stay unchanged, AH-115
 * ruling 1), so it holds the themes only while the current route is a creator one and lets go
 * the moment it is not. When disabled the hook returns `undefined`, i.e. `<v-app>` keeps the
 * global theme exactly as it did before this chunk.
 *
 * Inside the Capacitor app the hook does nothing and returns `undefined`: the app already
 * renders `nativeDarkTheme` as its global `dark` theme (`buildVuetifyOptions(true)`), and
 * its render is meant to stay exactly as it was.
 *
 * The hook also provides `CREATOR_LOOK` (`useNativeLook`), so components below the layout
 * can ask "does this subtree render the native look?" without asking "is this a phone?".
 *
 * Importing the hook also loads the two stylesheets that give the creator themes their look:
 * `native.css` (the Figma token layer, scoped to the creator theme classes since L1.2) and
 * `creator.css` (the light remap and the canvas). Both are inert unless a creator theme class
 * is on the page.
 *
 * The instance is the one the app installed (injected), not the `@/plugins/vuetify`
 * singleton, so a test app with its own Vuetify gets its own registration. `useTheme` stays
 * the single import boundary for Vuetify's theme composable (`use-theme-is-sot.spec.ts`).
 */

import {
  computed,
  inject,
  onBeforeUnmount,
  provide,
  ref,
  unref,
  watch,
  type ComputedRef,
  type Ref,
} from 'vue'

import '@catalyst/design-tokens/native.css'
import '@/modules/creators/theme/creator.css'

import { CREATOR_LOOK } from '@/composables/useNativeLook'
import { useTheme } from '@/composables/useTheme'
import { useThemePreference } from '@/composables/useThemePreference'
import { isNativePlatform } from '@/core/platform/isNativePlatform'

import {
  creatorThemeFor,
  registerCreatorThemes,
  unregisterCreatorThemes,
  type CreatorThemeName,
  type ThemeHost,
} from './creatorThemes'

/** Vuetify's theme instance key (a global-registry symbol; see `useAgencyTheme`). */
const VUETIFY_THEME_KEY = Symbol.for('vuetify:theme')

/** Holders per theme registry; the themes go when the last one lets go. */
const holders = new WeakMap<object, number>()

export interface CreatorTheme {
  /** The theme for `<v-app :theme>`; `undefined` keeps the global theme. */
  theme: ComputedRef<CreatorThemeName | undefined>
  /**
   * Does this layout render the native look? `useNativeLook()` answers that for everything
   * BELOW the layout (a component cannot inject what it provides itself), so the layout
   * reads it here.
   */
  look: ComputedRef<boolean>
}

export interface CreatorThemeOptions {
  /**
   * Apply the stored light/dark preference at setup (default true). The creator and onboarding
   * layouts do, so a creator who stored `light` is not shown the dark default at load (the
   * preference was only ever applied by the agency layout and the toggle). `AuthLayout` does
   * not: it also serves screens an agency user reaches, and the global theme is not its to set.
   */
  applyPreference?: boolean
}

export function useCreatorTheme(
  enabled: Readonly<Ref<boolean>> = ref(true),
  { applyPreference = true }: CreatorThemeOptions = {},
): CreatorTheme {
  const theme = inject<ThemeHost['theme'] | null>(VUETIFY_THEME_KEY, null)
  if (theme === null) {
    throw new Error(
      'useCreatorTheme() must run inside a component of an app that installed Vuetify.',
    )
  }
  // Web only: the app is dark-only, so a stored preference must not move it.
  if (applyPreference && !isNativePlatform()) useThemePreference()
  const { currentTheme } = useTheme()
  // The creator look for this subtree (`useNativeLook`): on while the hook is enabled.
  provide(CREATOR_LOOK, enabled)
  const native = isNativePlatform()
  const registry = unref(theme.themes)

  let held = false
  const acquire = () => {
    if (held) return
    held = true
    registerCreatorThemes({ theme })
    holders.set(registry, (holders.get(registry) ?? 0) + 1)
  }
  const release = () => {
    if (!held) return
    held = false
    const remaining = (holders.get(registry) ?? 1) - 1
    holders.set(registry, remaining)
    if (remaining <= 0) unregisterCreatorThemes({ theme })
  }

  if (!native) {
    watch(enabled, (on) => (on ? acquire() : release()), { immediate: true })
  }
  onBeforeUnmount(release)

  return {
    theme: computed(() =>
      native || !enabled.value ? undefined : creatorThemeFor(currentTheme.value),
    ),
    look: computed(() => native || enabled.value),
  }
}
