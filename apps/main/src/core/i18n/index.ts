import { createI18n } from 'vue-i18n'

import enApp from './locales/en/app.json'
import enAuth from './locales/en/auth.json'
import itApp from './locales/it/app.json'
import itAuth from './locales/it/auth.json'
import ptApp from './locales/pt/app.json'
import ptAuth from './locales/pt/auth.json'

/**
 * Vue-i18n bundle. Each locale folder owns one JSON file per top-level
 * namespace; the folder is the boundary across which translations stay
 * consistent. The auth bundle covers every `auth.*` error code emitted
 * by the backend Identity module — the source-inspection regression
 * test in `apps/main/tests/unit/architecture/i18n-auth-codes.spec.ts`
 * walks the backend source at Vitest time and fails CI if a backend
 * code lands without a matching translation here.
 */

type MessageSchema = typeof enApp & typeof enAuth

const messages: Record<'en' | 'pt' | 'it', MessageSchema> = {
  en: { ...enApp, ...enAuth },
  pt: { ...ptApp, ...ptAuth },
  it: { ...itApp, ...itAuth },
}

export const i18n = createI18n<[MessageSchema], 'en' | 'pt' | 'it'>({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  availableLocales: ['en', 'pt', 'it'],
  messages,
})
