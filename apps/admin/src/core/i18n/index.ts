import { createI18n } from 'vue-i18n'

import enApp from './locales/en/app.json'
import enAuth from './locales/en/auth.json'
import itApp from './locales/it/app.json'
import itAuth from './locales/it/auth.json'
import ptApp from './locales/pt/app.json'
import ptAuth from './locales/pt/auth.json'

/**
 * Vue-i18n bundle for the admin SPA. Each locale folder owns one JSON
 * file per top-level namespace; the folder is the boundary across
 * which translations stay consistent.
 *
 * The `auth` namespace mirrors `apps/main/src/core/i18n/locales/*\/auth.json`
 * (chunk 6.3) — same dotted-path coverage so a single backend code set
 * resolves in both SPAs without divergence — with admin-specific copy
 * overlaid on the user-facing UI strings (e.g. "Sign in to Catalyst
 * Admin", mandatory-MFA messaging). Translation values for backend
 * `auth.*` and `rate_limit.*` codes remain functionally identical to
 * main's so the chunk-6.5 `useErrorMessage` resolver renders the same
 * surface text in either SPA.
 *
 * The source-inspection regression test in
 * `apps/admin/tests/unit/architecture/i18n-auth-codes.spec.ts` walks
 * the backend Identity module at Vitest time and fails CI if a backend
 * code lands without a matching translation here (sub-chunk 7.3
 * architecture test, mirror of main's path).
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
