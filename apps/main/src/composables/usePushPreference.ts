import type { PushStorage } from '@/core/platform/push/pushRegistration'

/**
 * AH-102 — the persistence layer for the creator app's LOCAL push state: when the
 * soft-ask was last answered (its 30-day cool-down), and which token was last
 * registered and when (the once-a-day upkeep). Mirrors `useLocalePreference` /
 * `useThemePreference`: this file IS the one place that touches `localStorage`
 * for these keys, and it is allowlisted in the architecture test for exactly that
 * reason (with a tech-debt note — see `docs/tech-debt.md`).
 *
 * WebView `localStorage` on purpose: nothing here is a secret (the bearer lives
 * in secure storage; the FCM token is not a credential), it is cleared on
 * uninstall, and a lost value only costs one repeated soft-ask or one extra
 * registration. Unavailable or full storage reads as empty and writes silently
 * fail — push state must never break the app.
 */
export const pushPreferenceStorage: PushStorage = {
  get(key) {
    try {
      return window.localStorage.getItem(key)
    } catch {
      return null
    }
  },
  set(key, value) {
    try {
      window.localStorage.setItem(key, value)
    } catch {
      // A full or blocked store only costs us a repeated soft-ask.
    }
  },
  remove(key) {
    try {
      window.localStorage.removeItem(key)
    } catch {
      // As above.
    }
  },
}
