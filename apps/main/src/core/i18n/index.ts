import { UI_LOCALES, buildPluralRules } from '@catalyst/api-client'
import { createI18n } from 'vue-i18n'

import enApp from './locales/en/app.json'
import enAuth from './locales/en/auth.json'
import enAvailability from './locales/en/availability.json'
import enCreator from './locales/en/creator.json'
import enDashboard from './locales/en/dashboard.json'
import enImpersonation from './locales/en/impersonation.json'
import enNotifications from './locales/en/notifications.json'

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
 *   - `dashboard.json` — agency workspace-home strings (welcome bar, KPI
 *                       labels, activity feed). Sprint 4 Chunk 1. UI-only;
 *                       no backend error codes map under `dashboard.*`, so
 *                       (unlike auth/creator) it has no i18n-codes
 *                       architecture test.
 *   - `availability.json` — creator availability calendar strings (month
 *                       view, create/edit dialog, weekly recurrence,
 *                       block-type/kind labels). Sprint 5 Chunk B. UI-only
 *                       (the API sends raw enum values, no localized
 *                       labels); no backend error codes map under
 *                       `availability.*` (codes stay `validation.`/
 *                       `creator.`), so — like dashboard — no i18n-codes
 *                       architecture test.
 *   - `notifications.json` — notification-center chrome + the per-type body
 *                       templates rendered client-side from
 *                       `notification_type` + `data` (S11.0 Ch3a). UI-only
 *                       (no backend error codes map under `notifications.*`);
 *                       the en/pt/it key-set PARITY is pinned by
 *                       `tests/unit/architecture/i18n-notifications-parity.spec.ts`
 *                       and the "only the 8 live types are templated" rule is
 *                       a structural fact of `notificationTemplateKey`.
 *
 * The architecture tests walk the backend source at Vitest time and
 * fail CI if a backend error code lands without a matching translation
 * in all three locales. See the chunk-7.1 docblock at
 * `useErrorMessage.ts::isLikelyBundledCode` for the standing contract:
 * a new top-level prefix requires extending the resolver AND adding a
 * parallel architecture test AND shipping bundle entries in all three
 * locales in the same commit.
 *
 * Loading strategy: `en` is statically bundled (the always-needed
 * fallback, present at boot). Every other locale is fetched on demand via
 * `loadLocaleMessages` (a Vite glob → one async chunk per namespace JSON)
 * and merged in by `setLocale` BEFORE the active locale flips, so the UI
 * never renders a half-populated bundle. This keeps the initial payload
 * to one locale's worth of strings as the rendered set grows toward 24.
 */

type MessageSchema = typeof enApp &
  typeof enAuth &
  typeof enCreator &
  typeof enDashboard &
  typeof enAvailability &
  typeof enNotifications &
  typeof enImpersonation

// `en` is statically bundled — it is the always-needed fallback, so it
// must be present synchronously at boot (no missing-key flash). Every
// other locale loads on demand (see `loadLocaleMessages` / `setLocale`).
const enMessages: MessageSchema = {
  ...enApp,
  ...enAuth,
  ...enCreator,
  ...enDashboard,
  ...enAvailability,
  ...enNotifications,
  ...enImpersonation,
}

/**
 * Lazy per-locale namespace loaders. Vite turns this glob into one async
 * chunk per JSON file; only the active locale's files are ever fetched.
 * `en` is matched too but is never loaded through here (it is eager).
 */
const localeFileLoaders = import.meta.glob<{ default: Record<string, unknown> }>(
  './locales/*/*.json',
)

/**
 * Load + merge every namespace JSON for one locale into a single message
 * object. Returns `{}` for a locale with no files on disk (the supported
 * set is gated by `availableLocales`, so this is only a safety net). Does
 * not touch any i18n instance — callers apply it via `setLocaleMessage`.
 */
export async function loadLocaleMessages(locale: string): Promise<Record<string, unknown>> {
  const prefix = `./locales/${locale}/`
  const loaders = Object.entries(localeFileLoaders).filter(([path]) => path.startsWith(prefix))
  const modules = await Promise.all(loaders.map(([, load]) => load()))
  return Object.assign({}, ...modules.map((m) => m.default)) as Record<string, unknown>
}

export const i18n = createI18n<[MessageSchema], 'en' | 'pt' | 'it', false>({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  // The rendered set derives from the shared UI_LOCALES registry, so the
  // switcher and validation stay in lockstep and the S8 flip to 24 is a
  // single registry edit. Today UI_LOCALES === ['en', 'pt', 'it'].
  availableLocales: [...UI_LOCALES],
  // Only `en` is populated at construction; pt/it (and the future 24) are
  // merged in lazily by `setLocale`. The cast satisfies the all-locales
  // message-schema generic without eagerly bundling the other locales.
  messages: { en: enMessages } as Record<'en' | 'pt' | 'it', MessageSchema>,
  // CLDR cardinal pluralisation for all 24 EU languages (keys for the
  // not-yet-rendered locales are inert until the S8 flip). Replaces
  // vue-i18n's English-shaped default, which is wrong for the Slavic/
  // Baltic/Celtic members of the set.
  pluralRules: buildPluralRules(),
})

/** True once a non-`en` locale's messages have been merged into `i18n`. */
function isLoaded(locale: string): boolean {
  return locale === 'en' || Object.keys(i18n.global.getLocaleMessage(locale)).length > 0
}

/**
 * Switch the active locale, loading its messages first if needed so the
 * UI never renders against a half-populated bundle (no English/missing-key
 * flash). Used at boot (target-locale resolution) and by S5's persistence.
 */
export async function setLocale(locale: string): Promise<void> {
  if (!isLoaded(locale)) {
    i18n.global.setLocaleMessage(locale, (await loadLocaleMessages(locale)) as MessageSchema)
  }
  i18n.global.locale.value = locale as 'en' | 'pt' | 'it'
}
