/**
 * Pure helper extracted from {@link AuthLayout.vue} so the v8
 * function-coverage gate can see it.
 *
 * The locale switcher needs `[{ value, title }]` items where each
 * `title` is the language's display name. The option list is sourced
 * from the {@link UI_LOCALES} registry — the single source of truth for
 * the locales we render — NOT from vue-i18n's `availableLocales`, which
 * only reports locales whose message bundles are already LOADED. Because
 * the SPA lazy-loads every locale except `en` on demand, `availableLocales`
 * is `['en']` at boot, which would collapse the switcher to a single
 * entry. Labels use each language's endonym (locale-independent) so we do
 * not need a 24x24 translated-label matrix.
 *
 * The exclusion+guard pattern from chunk 6.4 plan applies: this file
 * exists because `AuthLayout.vue` is excluded from the runtime coverage
 * gate (v8 cannot anchor function coverage on a `<script setup>` SFC with
 * no user-defined functions). Anything substantive is extracted here so
 * it CAN be unit-tested. The architecture test in
 * `apps/main/tests/unit/architecture/auth-layout-shape.spec.ts`
 * enforces the carve-out's invariants (size + no multi-statement arrows).
 */

import { UI_LOCALES, languageEndonym } from '@catalyst/api-client'

export interface LocaleOption {
  value: string
  title: string
}

export function buildLocaleOptions(): LocaleOption[] {
  return UI_LOCALES.map((code) => ({
    value: code,
    title: languageEndonym(code),
  }))
}
