import { createI18n } from 'vue-i18n'

import enApp from './locales/en/app.json'
import enAuth from './locales/en/auth.json'
import enCreator from './locales/en/creator.json'
import itApp from './locales/it/app.json'
import itAuth from './locales/it/auth.json'
import itCreator from './locales/it/creator.json'
import ptApp from './locales/pt/app.json'
import ptAuth from './locales/pt/auth.json'
import ptCreator from './locales/pt/creator.json'

/**
 * Vue-i18n bundle. Each locale folder owns one JSON file per top-level
 * namespace; the folder is the boundary across which translations stay
 * consistent.
 *
 * Per-namespace coverage:
 *   - `auth.json`     — Identity-module errors (auth.*) + rate_limit.*
 *                       error codes + auth UI strings. Source-inspection
 *                       regression at `tests/unit/architecture/i18n-auth-codes.spec.ts`.
 *   - `creator.json`  — Creators-module errors (creator.*) +
 *                       creator-side UI strings (Sprint 3 Chunk 3).
 *                       Source-inspection regression at
 *                       `tests/unit/architecture/i18n-creator-codes.spec.ts`.
 *   - `app.json`      — shared app chrome strings (header, footer,
 *                       common form labels).
 *
 * The architecture tests walk the backend source at Vitest time and
 * fail CI if a backend error code lands without a matching translation
 * in all three locales. See the chunk-7.1 docblock at
 * `useErrorMessage.ts::isLikelyBundledCode` for the standing contract:
 * a new top-level prefix requires extending the resolver AND adding a
 * parallel architecture test AND shipping bundle entries in all three
 * locales in the same commit.
 */

type MessageSchema = typeof enApp & typeof enAuth & typeof enCreator

const messages: Record<'en' | 'pt' | 'it', MessageSchema> = {
  en: { ...enApp, ...enAuth, ...enCreator },
  pt: { ...ptApp, ...ptAuth, ...ptCreator },
  it: { ...itApp, ...itAuth, ...itCreator },
}

export const i18n = createI18n<[MessageSchema], 'en' | 'pt' | 'it'>({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  availableLocales: ['en', 'pt', 'it'],
  messages,
})
