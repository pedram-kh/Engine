/**
 * Pure helper extracted from {@link AuthLayout.vue} so the v8
 * function-coverage gate can see it.
 *
 * Mirror of `apps/main/src/modules/auth/layouts/localeOptions.ts`
 * (chunk 6.6). The locale switcher needs `[{ value, title }]` items
 * where each `title` is the locale's localised display name
 * (`English`, `Português`, `Italiano`). The list is computed once per
 * render pass; vue-i18n's `availableLocales` is stable across the
 * lifetime of the i18n instance, so a non-reactive plain function is
 * sufficient.
 *
 * The exclusion+guard pattern from chunk 6.4 plan applies: this file
 * exists because `AuthLayout.vue` is excluded from the runtime
 * coverage gate (v8 cannot anchor function coverage on a `<script setup>`
 * SFC with no user-defined functions). Anything substantive is
 * extracted here so it CAN be unit-tested. The architecture test in
 * `apps/admin/tests/unit/architecture/auth-layout-shape.spec.ts`
 * enforces the carve-out's invariants (size + no multi-statement arrows).
 */

export interface LocaleOption {
  value: string
  title: string
}

export function buildLocaleOptions(
  availableLocales: ReadonlyArray<string>,
  t: (key: string) => string,
): LocaleOption[] {
  return availableLocales.map((code) => ({
    value: code,
    title: t(`app.locale.${code}`),
  }))
}
