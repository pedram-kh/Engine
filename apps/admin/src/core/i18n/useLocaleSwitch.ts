/**
 * Locale-switch composable for the admin language dropdowns.
 *
 * Mirrors `apps/main/src/core/i18n/useLocaleSwitch.ts`: it loads a
 * not-yet-loaded locale's messages BEFORE the active locale flips (no
 * missing-key flash), operating on the i18n instance from `useI18n()` —
 * the bootstrap singleton in production, the per-test instance under
 * Vitest. Already-loaded locales flip synchronously, preserving the
 * existing v-model semantics.
 *
 * Persistence (S5): every switch is written to `localStorage` so the choice
 * survives a reload. When the admin is signed in, the choice is ALSO
 * mirrored to the server (`PATCH /admin/me`) so it follows them across
 * sessions. The server write is best-effort — localStorage already holds
 * the value, so a failed PATCH degrades gracefully (server-wins reconciles
 * on next load).
 */

import type { PreferredLanguage } from '@catalyst/api-client'
import { useI18n } from 'vue-i18n'

import { writeStoredLocale } from '@/composables/useLocalePreference'
import { useAdminAuthStore } from '@/modules/auth/stores/useAdminAuthStore'
import { loadLocaleMessages } from '.'

export function useLocaleSwitch() {
  const { locale, getLocaleMessage, setLocaleMessage } = useI18n()
  const auth = useAdminAuthStore()

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
