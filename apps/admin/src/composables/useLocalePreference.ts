/**
 * `useLocalePreference` — admin SPA SOT for the CLIENT-side persistence of
 * the user's chosen UI language.
 *
 * vue-i18n owns the reactive "what is the locale right now?" state; this
 * module owns "what locale should we boot into before the server answers?"
 * It is a thin `localStorage` wrapper, mirroring `useThemePreference`'s
 * persistence layer — the single file allowed to touch `localStorage` for
 * the locale key (enforced by `tests/unit/architecture/use-theme-is-sot.spec.ts`).
 *
 * Resolution order (see docs/00-MASTER-ARCHITECTURE.md §13):
 *   boot         → {@link readStoredLocale} ?? SPA default (`en`)
 *   authenticated → the server `preferred_language` WINS and is written
 *                   back here by the auth store's `setUser` hydration, so
 *                   localStorage and the server never drift.
 *
 * Only rendered UI locales (`UI_LOCALES`) are ever read or written: a
 * stored locale we cannot render would boot into a half-missing bundle, so
 * an unrecognised / stale value is treated as unset (fall back to default).
 *
 * Per-SPA mirror: the main SPA mirrors this verbatim at
 * `apps/main/src/composables/useLocalePreference.ts`; the only difference
 * is `LOCALE_STORAGE_KEY` (`catalyst.main.locale`). Per-origin storage
 * keeps the two SPAs' choices independent.
 */

import { UI_LOCALES, type UiLocale } from '@catalyst/api-client'

export const LOCALE_STORAGE_KEY = 'catalyst.admin.locale'

function isUiLocale(value: string | null): value is UiLocale {
  return value !== null && (UI_LOCALES as readonly string[]).includes(value)
}

/**
 * The persisted UI locale, or `null` when unset / unrenderable / storage
 * is unavailable. Migration is passive-on-read: a stale value (e.g. a
 * locale dropped from `UI_LOCALES`) reads as unset, never rewritten here.
 */
export function readStoredLocale(): UiLocale | null {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') {
    return null
  }
  try {
    const raw = window.localStorage.getItem(LOCALE_STORAGE_KEY)
    return isUiLocale(raw) ? raw : null
  } catch {
    // Storage unavailable (private mode, security policy). Treat as unset.
    return null
  }
}

/**
 * Persist a chosen UI locale. A non-rendered locale is ignored (never
 * stored) so a bad value can't poison the next boot.
 */
export function writeStoredLocale(locale: string): void {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') {
    return
  }
  if (!isUiLocale(locale)) {
    return
  }
  try {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // Storage write rejected (quota, security policy). The in-memory
    // locale still flips; only the persistence is lost. Acceptable.
  }
}

/** Clear the stored locale, returning to the SPA-default fallback. */
export function clearStoredLocale(): void {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') {
    return
  }
  try {
    window.localStorage.removeItem(LOCALE_STORAGE_KEY)
  } catch {
    // Storage unavailable; nothing to clear in this session.
  }
}

/**
 * The locale to boot into: the persisted choice if present, else the given
 * fallback (the i18n default). Used by `main.ts` before mount so the first
 * paint is already in the saved language (no English flash).
 */
export function resolveBootLocale(fallback: string): string {
  return readStoredLocale() ?? fallback
}
