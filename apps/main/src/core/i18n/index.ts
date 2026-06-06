import { createI18n } from 'vue-i18n'

import enApp from './locales/en/app.json'
import enAuth from './locales/en/auth.json'
import enAvailability from './locales/en/availability.json'
import enCreator from './locales/en/creator.json'
import enDashboard from './locales/en/dashboard.json'
import enNotifications from './locales/en/notifications.json'
import itApp from './locales/it/app.json'
import itAuth from './locales/it/auth.json'
import itAvailability from './locales/it/availability.json'
import itCreator from './locales/it/creator.json'
import itDashboard from './locales/it/dashboard.json'
import itNotifications from './locales/it/notifications.json'
import ptApp from './locales/pt/app.json'
import ptAuth from './locales/pt/auth.json'
import ptAvailability from './locales/pt/availability.json'
import ptCreator from './locales/pt/creator.json'
import ptDashboard from './locales/pt/dashboard.json'
import ptNotifications from './locales/pt/notifications.json'

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
 */

type MessageSchema = typeof enApp &
  typeof enAuth &
  typeof enCreator &
  typeof enDashboard &
  typeof enAvailability &
  typeof enNotifications

const messages: Record<'en' | 'pt' | 'it', MessageSchema> = {
  en: { ...enApp, ...enAuth, ...enCreator, ...enDashboard, ...enAvailability, ...enNotifications },
  pt: { ...ptApp, ...ptAuth, ...ptCreator, ...ptDashboard, ...ptAvailability, ...ptNotifications },
  it: { ...itApp, ...itAuth, ...itCreator, ...itDashboard, ...itAvailability, ...itNotifications },
}

export const i18n = createI18n<[MessageSchema], 'en' | 'pt' | 'it'>({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  availableLocales: ['en', 'pt', 'it'],
  messages,
})
