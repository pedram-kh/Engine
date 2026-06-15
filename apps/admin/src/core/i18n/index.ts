import { UI_LOCALES, buildPluralRules } from '@catalyst/api-client'
import { createI18n } from 'vue-i18n'

import { deepMergeLocale } from './deepMerge'
import enAgencies from './locales/en/agencies.json'
import enAlerts from './locales/en/alerts.json'
import enApp from './locales/en/app.json'
import enAudit from './locales/en/audit.json'
import enAuth from './locales/en/auth.json'
import enCompliance from './locales/en/compliance.json'
import enCreators from './locales/en/creators.json'
import enDashboard from './locales/en/dashboard.json'
import enFeatureFlags from './locales/en/feature-flags.json'
import enOperations from './locales/en/operations.json'
import enSupport from './locales/en/support.json'

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

type MessageSchema = typeof enApp &
  typeof enAuth &
  typeof enCreators &
  typeof enAgencies &
  typeof enAudit &
  typeof enFeatureFlags &
  typeof enDashboard &
  typeof enOperations &
  typeof enSupport &
  typeof enCompliance &
  typeof enAlerts

// Deep-merge (NOT shallow spread): every admin module locale file
// (`creators.json`, `agencies.json`, `audit.json`, `feature-flags.json`,
// `dashboard.json`, `operations.json`) contributes an `admin.*` subtree,
// which a spread would clobber.
//
// `en` is statically bundled (always-needed fallback, present at boot, no
// missing-key flash). Every other locale loads on demand.
const enMessages = deepMergeLocale(
  enApp,
  enAuth,
  enCreators,
  enAgencies,
  enAudit,
  enFeatureFlags,
  enDashboard,
  enOperations,
  enSupport,
  enCompliance,
  enAlerts,
) as unknown as MessageSchema

/**
 * Lazy per-locale namespace loaders (one async chunk per JSON file). Only
 * the active locale's files are fetched; `en` is matched but never loaded
 * through here (it is eager above).
 */
const localeFileLoaders = import.meta.glob<{ default: Record<string, unknown> }>(
  './locales/*/*.json',
)

/**
 * Load every namespace JSON for one locale and deep-merge them (the same
 * `admin.*`-subtree merge the eager `en` bundle uses). Returns `{}` for a
 * locale with no files. Does not touch any i18n instance.
 */
export async function loadLocaleMessages(locale: string): Promise<Record<string, unknown>> {
  const prefix = `./locales/${locale}/`
  const loaders = Object.entries(localeFileLoaders).filter(([path]) => path.startsWith(prefix))
  const modules = await Promise.all(loaders.map(([, load]) => load()))
  return deepMergeLocale(...modules.map((m) => m.default))
}

export const i18n = createI18n<[MessageSchema], 'en' | 'pt' | 'it', false>({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  // Rendered set derives from the shared UI_LOCALES registry (see main
  // app i18n bootstrap). Today UI_LOCALES === ['en', 'pt', 'it'].
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
 * Switch the active locale, loading its messages first if needed (no
 * English/missing-key flash). Used at boot and by S5's persistence.
 */
export async function setLocale(locale: string): Promise<void> {
  if (!isLoaded(locale)) {
    i18n.global.setLocaleMessage(locale, (await loadLocaleMessages(locale)) as MessageSchema)
  }
  i18n.global.locale.value = locale as 'en' | 'pt' | 'it'
}
