/**
 * Locale-switch composable for the admin language dropdowns.
 *
 * Mirrors `apps/main/src/core/i18n/useLocaleSwitch.ts`: it loads a
 * not-yet-loaded locale's messages BEFORE the active locale flips (no
 * missing-key flash), operating on the i18n instance from `useI18n()` —
 * the bootstrap singleton in production, the per-test instance under
 * Vitest. Already-loaded locales flip synchronously, preserving the
 * existing v-model semantics.
 */

import { useI18n } from 'vue-i18n'

import { loadLocaleMessages } from '.'

export function useLocaleSwitch() {
  const { locale, getLocaleMessage, setLocaleMessage } = useI18n()

  async function selectLocale(next: string): Promise<void> {
    if (next === locale.value) return

    const needsLoad =
      next !== 'en' && Object.keys(getLocaleMessage(next) as Record<string, unknown>).length === 0
    if (needsLoad) {
      setLocaleMessage(next, await loadLocaleMessages(next))
    }

    locale.value = next
  }

  return { selectLocale }
}
