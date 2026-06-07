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
import itAgencies from './locales/it/agencies.json'
import itAlerts from './locales/it/alerts.json'
import itApp from './locales/it/app.json'
import itAudit from './locales/it/audit.json'
import itAuth from './locales/it/auth.json'
import itCompliance from './locales/it/compliance.json'
import itCreators from './locales/it/creators.json'
import itDashboard from './locales/it/dashboard.json'
import itFeatureFlags from './locales/it/feature-flags.json'
import itOperations from './locales/it/operations.json'
import itSupport from './locales/it/support.json'
import ptAgencies from './locales/pt/agencies.json'
import ptAlerts from './locales/pt/alerts.json'
import ptApp from './locales/pt/app.json'
import ptAudit from './locales/pt/audit.json'
import ptAuth from './locales/pt/auth.json'
import ptCompliance from './locales/pt/compliance.json'
import ptCreators from './locales/pt/creators.json'
import ptDashboard from './locales/pt/dashboard.json'
import ptFeatureFlags from './locales/pt/feature-flags.json'
import ptOperations from './locales/pt/operations.json'
import ptSupport from './locales/pt/support.json'

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
const messages: Record<'en' | 'pt' | 'it', MessageSchema> = {
  en: deepMergeLocale(
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
  ) as unknown as MessageSchema,
  pt: deepMergeLocale(
    ptApp,
    ptAuth,
    ptCreators,
    ptAgencies,
    ptAudit,
    ptFeatureFlags,
    ptDashboard,
    ptOperations,
    ptSupport,
    ptCompliance,
    ptAlerts,
  ) as unknown as MessageSchema,
  it: deepMergeLocale(
    itApp,
    itAuth,
    itCreators,
    itAgencies,
    itAudit,
    itFeatureFlags,
    itDashboard,
    itOperations,
    itSupport,
    itCompliance,
    itAlerts,
  ) as unknown as MessageSchema,
}

export const i18n = createI18n<[MessageSchema], 'en' | 'pt' | 'it'>({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  availableLocales: ['en', 'pt', 'it'],
  messages,
})
