/**
 * `useThemePreference` — main SPA composable that owns the user's
 * theme preference (the "what should the theme be?" question) on top
 * of the `useTheme` composable (which owns the "what is the theme
 * right now?" question).
 *
 * The preference layer is a tri-state value:
 *
 *   - `'light'`   → user explicitly picked the light palette.
 *   - `'dark'`    → user explicitly picked the dark palette.
 *   - `'system'`  → user opted into "follow my OS" — `prefers-color-scheme`
 *                   is consulted reactively for the effective theme.
 *
 * When the user has never set a preference (storage empty), the
 * effective theme falls back to the SPA default (main = `light`).
 * `prefers-color-scheme` is **not** consulted in that case — Group 2
 * of chunk 8 picked Option C (asymmetric defaults with layered
 * fallback) per Q1's design answer:
 *
 *     User preference > SPA default > prefers-color-scheme
 *
 * The OS preference is consulted **only** when the user explicitly
 * picks `'system'`. This honours Sprint-0's deliberate `defaultTheme:
 * 'dark'` choice for admin (operators-in-low-light-contexts) AND
 * gives users full control via the toggle UI.
 *
 * Storage:
 *   `localStorage` keyed by `STORAGE_KEY` (`catalyst.main.theme`).
 *   Per-origin storage means the main and admin SPAs (different
 *   subdomains in production, different ports in dev) get independent
 *   preferences naturally — see chunk-8 kickoff for the rejected
 *   alternatives (cookies bring session-cookie boundary concerns;
 *   server-stored preference is out of Sprint 1 scope).
 *
 * Module-scoped singleton state:
 *   The preference, system-detection ref, listener, and cached
 *   `useTheme` manager are all module-scoped so multiple consumers
 *   (App.vue, the toggle, future settings page) share the same
 *   reactive state. The first call performs idempotent
 *   initialisation; subsequent calls are no-ops modulo the
 *   "current === target" theme-sync guard.
 *
 * Bootstrapping:
 *   `useThemePreference()` runs from `App.vue`'s setup() so the
 *   persisted preference is applied to Vuetify SYNCHRONOUSLY before
 *   the first render — no flash-of-default-theme. The composable
 *   call boundary is the bootstrap; there is no separate
 *   `bootstrapThemePreference()` helper.
 *
 * Architecture enforcement (chunk 8.2 extension):
 *   `tests/unit/architecture/use-theme-is-sot.spec.ts` is extended
 *   to also forbid (a) any `localStorage.{get,set,remove}Item(...)`
 *   call referencing a `catalyst.*.theme` key outside this file,
 *   and (b) any `window.matchMedia('(prefers-color-scheme: ...)')`
 *   call outside this file. The composable file itself is the SOT
 *   and legitimately needs both.
 *
 * Per-SPA mirror (chunk 7.2 D2 standing standard):
 *   The admin SPA mirrors this composable verbatim at
 *   `apps/admin/src/composables/useThemePreference.ts`. Differences
 *   are limited to (a) `STORAGE_KEY` (`catalyst.admin.theme`),
 *   (b) `SPA_DEFAULT` (`'dark'`), and (c) module-comment SPA-name
 *   swaps. Both files MUST stay in structural lockstep.
 */

import { computed, ref, type ComputedRef } from 'vue'

import { useTheme, type ThemeManager, type ThemeName } from './useTheme'

export const STORAGE_KEY = 'catalyst.main.theme'

export const SPA_DEFAULT: ThemeName = 'light'

export const themePreferences = ['light', 'dark', 'system'] as const

export type ThemePreference = (typeof themePreferences)[number]

export interface ThemePreferenceManager {
  /**
   * The user's resolved preference. Returns the SPA default when no
   * preference has ever been set; returns the stored value otherwise.
   * Use `isExplicit` to distinguish "no preference" from "explicit
   * SPA default".
   */
  preference: ComputedRef<ThemePreference>
  /**
   * The actually-applied theme — `'light'` or `'dark'`. For
   * `preference === 'system'` this resolves through
   * `prefers-color-scheme` reactively.
   */
  effectiveTheme: ComputedRef<ThemeName>
  /**
   * True iff the user has explicitly set a preference (storage is
   * populated). False when running on the SPA default fallback.
   */
  isExplicit: ComputedRef<boolean>
  /**
   * Persist a new preference and apply it to Vuetify. Selecting
   * `'system'` also mounts the `prefers-color-scheme` listener;
   * selecting `'light'` or `'dark'` tears it down.
   */
  setPreference: (next: ThemePreference) => void
  /**
   * Clear the stored preference, returning to the SPA-default
   * fallback. Tears down the `prefers-color-scheme` listener.
   */
  clearPreference: () => void
  /**
   * The full list of preference values; convenient for toggle UIs.
   */
  availablePreferences: typeof themePreferences
}

const preference = ref<ThemePreference | null>(null)
const systemPrefersDark = ref<boolean>(false)
let mediaQuery: MediaQueryList | null = null
let mediaListener: ((event: MediaQueryListEvent) => void) | null = null
let cachedThemeManager: ThemeManager | null = null
let initialized = false

function readStoredPreference(): ThemePreference | null {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') {
    return null
  }
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (raw === 'light' || raw === 'dark' || raw === 'system') {
      return raw
    }
    return null
  } catch {
    // Storage unavailable (private mode, security policy). Treat as
    // unset — the in-memory state takes over for the session.
    return null
  }
}

function writeStoredPreference(value: ThemePreference | null): void {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') {
    return
  }
  try {
    if (value === null) {
      window.localStorage.removeItem(STORAGE_KEY)
    } else {
      window.localStorage.setItem(STORAGE_KEY, value)
    }
  } catch {
    // Storage write rejected (quota, security policy). The in-memory
    // preference still flips correctly; the persistence is what we
    // lose. Acceptable degradation per the chunk-8 kickoff.
  }
}

function ensureSystemListener(): void {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
    systemPrefersDark.value = false
    return
  }
  if (mediaQuery !== null) {
    return
  }
  mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
  systemPrefersDark.value = mediaQuery.matches
  mediaListener = (event: MediaQueryListEvent): void => {
    systemPrefersDark.value = event.matches
    if (preference.value === 'system') {
      applyToVuetify(event.matches ? 'dark' : 'light')
    }
  }
  mediaQuery.addEventListener('change', mediaListener)
}

function teardownSystemListener(): void {
  if (mediaQuery !== null && mediaListener !== null) {
    mediaQuery.removeEventListener('change', mediaListener)
  }
  mediaQuery = null
  mediaListener = null
}

function applyToVuetify(name: ThemeName): void {
  if (cachedThemeManager === null) {
    return
  }
  if (cachedThemeManager.currentTheme.value === name) {
    return
  }
  cachedThemeManager.setTheme(name)
}

function ensureInitialized(): void {
  if (initialized) {
    return
  }
  initialized = true
  preference.value = readStoredPreference()
  if (preference.value === 'system') {
    ensureSystemListener()
  }
}

export function useThemePreference(): ThemePreferenceManager {
  if (cachedThemeManager === null) {
    cachedThemeManager = useTheme()
  }
  ensureInitialized()

  const preferenceComputed = computed<ThemePreference>(() => preference.value ?? SPA_DEFAULT)
  const isExplicit = computed<boolean>(() => preference.value !== null)
  const effectiveTheme = computed<ThemeName>(() => {
    if (preference.value === 'light') {
      return 'light'
    }
    if (preference.value === 'dark') {
      return 'dark'
    }
    if (preference.value === 'system') {
      return systemPrefersDark.value ? 'dark' : 'light'
    }
    return SPA_DEFAULT
  })

  applyToVuetify(effectiveTheme.value)

  function setPreference(next: ThemePreference): void {
    if (next === 'system') {
      ensureSystemListener()
    } else {
      teardownSystemListener()
    }
    preference.value = next
    writeStoredPreference(next)
    applyToVuetify(effectiveTheme.value)
  }

  function clearPreference(): void {
    teardownSystemListener()
    preference.value = null
    writeStoredPreference(null)
    applyToVuetify(effectiveTheme.value)
  }

  return {
    preference: preferenceComputed,
    effectiveTheme,
    isExplicit,
    setPreference,
    clearPreference,
    availablePreferences: themePreferences,
  }
}

/**
 * Test-only: reset every piece of module-scoped state so each test
 * starts fresh. Production code MUST NOT call this — module-scoped
 * state is singleton-by-design (the persistence layer reads the
 * same `localStorage` regardless of how many times the composable
 * is consumed).
 */
export function __resetThemePreferenceForTests(): void {
  teardownSystemListener()
  preference.value = null
  systemPrefersDark.value = false
  cachedThemeManager = null
  initialized = false
}
