/**
 * Locale-switch composable for the language dropdowns.
 *
 * Switching to a not-yet-loaded locale must load its messages BEFORE the
 * active locale flips, otherwise the UI renders missing keys for the
 * duration of the dynamic import. This composable does that load-then-set
 * on the i18n instance returned by `useI18n()` — the SAME instance the
 * component renders against (the bootstrap singleton in production, the
 * per-test instance under Vitest), so it works in both.
 *
 * For an already-loaded locale (the common case: `en`, or any locale the
 * user already selected this session) there is no `await` before the
 * locale flips, so the switch stays synchronous and the existing v-model
 * semantics are preserved.
 *
 * Persistence (S5): every switch is written to `localStorage` so the choice
 * survives a reload. When the user is signed in, the choice is ALSO mirrored
 * to the server (`PATCH /me`) so it follows them across devices/sessions.
 * The server write is best-effort — localStorage already holds the value, so
 * a failed PATCH degrades gracefully (server-wins reconciles on next load).
 */

import type { PreferredLanguage } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import { writeStoredLocale } from '@/composables/useLocalePreference'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { loadLocaleMessages } from '.'

export function useLocaleSwitch() {
  const { locale, getLocaleMessage, setLocaleMessage } = useI18n()
  const auth = useAuthStore()

  async function selectLocale(next: string): Promise<void> {
    if (next === locale.value) return

    const needsLoad =
      next !== 'en' && Object.keys(getLocaleMessage(next) as Record<string, unknown>).length === 0
    if (needsLoad) {
      setLocaleMessage(next, await loadLocaleMessages(next))
    }

    locale.value = next
    writeStoredLocale(next)

    if (auth.isAuthenticated) {
      void auth.setPreferredLanguage(next as PreferredLanguage)
    }
  }

  return { selectLocale }
}
