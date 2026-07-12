/**
 * `useThemePreference` — admin SPA composable that owns the user's
 * theme preference (the "what should the theme be?" question) on top
 * of the `useTheme` composable (which owns the "what is the theme
 * right now?" question).
 *
 * The preference layer is a BINARY value (Sprint 3.5 Chunk 1):
 *
 *   - `'light'`   → user explicitly picked the light palette.
 *   - `'dark'`    → user explicitly picked the dark palette.
 *
 * When the user has never set a preference (storage empty), the
 * effective theme falls back to the SPA default (admin = `dark`, the
 * operator-preferred default since Sprint 0 and the Catalyst Engine v2
 * dark-first identity).
 *
 * Sprint 3.5 Chunk 1 — dropped `'system'`:
 *   Chunk 8.2 shipped a tri-state preference (`light` / `dark` /
 *   `system`) where `system` consulted `prefers-color-scheme`. Sprint
 *   3.5 Decision (Q `tri_state_disposition` = "drop_system") removes the
 *   `system` mode entirely: the v2 brand is dark-first and the toggle is
 *   a deliberate binary choice. The `prefers-color-scheme` machinery
 *   (matchMedia listener) is gone. The forbidden-pattern ratchet in
 *   `tests/unit/architecture/use-theme-is-sot.spec.ts` STAYS in place
 *   (no component may newly reach for `matchMedia(prefers-color-scheme)`)
 *   — a one-way design decision — but this composable no longer needs
 *   the allowlist row for it.
 *
 * Passive storage migration:
 *   A legacy persisted `'system'` value (written by chunk 8.2) is read
 *   as "unset" → the user falls back to the SPA default. The migration
 *   is passive-on-read: we do NOT rewrite storage on read (no write side
 *   effect during a getter). The stale `'system'` row is overwritten the
 *   next time the user explicitly toggles, or simply lingers harmlessly.
 *
 * Storage:
 *   `localStorage` keyed by `STORAGE_KEY` (`catalyst.admin.theme`).
 *   Per-origin storage means the main and admin SPAs (different
 *   subdomains in production, different ports in dev) get independent
 *   preferences naturally.
 *
 * Module-scoped singleton state:
 *   The preference and cached `useTheme` manager are module-scoped so
 *   multiple consumers (App.vue, the toggle, future settings page) share
 *   the same reactive state. The first call performs idempotent
 *   initialisation; subsequent calls are no-ops modulo the
 *   "current === target" theme-sync guard.
 *
 * Bootstrapping:
 *   `useThemePreference()` runs from `App.vue`'s setup() so the
 *   persisted preference is applied to Vuetify SYNCHRONOUSLY before
 *   the first render — no flash-of-default-theme.
 *
 * Per-SPA mirror (chunk 7.2 D2 standing standard):
 *   The main SPA mirrors this composable verbatim at
 *   `apps/main/src/composables/useThemePreference.ts`. Differences
 *   are limited to (a) `STORAGE_KEY` (`catalyst.main.theme`),
 *   (b) `SPA_DEFAULT` (both SPAs are `'dark'` as of Sprint 3.5 Chunk 1),
 *   and (c) module-comment SPA-name swaps. Both files MUST stay in
 *   structural lockstep.
 */

import { computed, ref, type ComputedRef } from 'vue'

import { useTheme, type ThemeManager, type ThemeName } from './useTheme'

export const STORAGE_KEY = 'catalyst.admin.theme'

export const SPA_DEFAULT: ThemeName = 'dark'

export const themePreferences = ['light', 'dark'] as const

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
   * The actually-applied theme — `'light'` or `'dark'`. In the binary
   * model this equals `preference` (or the SPA default when unset).
   */
  effectiveTheme: ComputedRef<ThemeName>
  /**
   * True iff the user has explicitly set a preference (storage is
   * populated). False when running on the SPA default fallback.
   */
  isExplicit: ComputedRef<boolean>
  /**
   * Persist a new preference and apply it to Vuetify.
   */
  setPreference: (next: ThemePreference) => void
  /**
   * Clear the stored preference, returning to the SPA-default fallback.
   */
  clearPreference: () => void
  /**
   * The full list of preference values; convenient for toggle UIs.
   */
  availablePreferences: typeof themePreferences
}

const preference = ref<ThemePreference | null>(null)
let cachedThemeManager: ThemeManager | null = null
let initialized = false

function readStoredPreference(): ThemePreference | null {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') {
    return null
  }
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    // Binary model only. A legacy `'system'` value (chunk 8.2) — or any
    // other unrecognised string — is treated as unset, falling back to
    // the SPA default. Migration is passive-on-read: no rewrite here.
    if (raw === 'light' || raw === 'dark') {
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
    // lose. Acceptable degradation.
  }
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
}

export function useThemePreference(): ThemePreferenceManager {
  if (cachedThemeManager === null) {
    cachedThemeManager = useTheme()
  }
  ensureInitialized()

  const preferenceComputed = computed<ThemePreference>(() => preference.value ?? SPA_DEFAULT)
  const isExplicit = computed<boolean>(() => preference.value !== null)
  const effectiveTheme = computed<ThemeName>(() => preference.value ?? SPA_DEFAULT)

  applyToVuetify(effectiveTheme.value)

  function setPreference(next: ThemePreference): void {
    preference.value = next
    writeStoredPreference(next)
    applyToVuetify(effectiveTheme.value)
  }

  function clearPreference(): void {
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
  preference.value = null
  cachedThemeManager = null
  initialized = false
}
