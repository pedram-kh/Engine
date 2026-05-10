/**
 * Pure helper extracted from {@link AuthLayout.vue} so the v8
 * function-coverage gate can see it.
 *
 * The locale switcher needs `[{ value, title }]` items where each
 * `title` is the locale's localised display name (`English`,
 * `Português`, `Italiano`). The list is computed once per render
 * pass; v-i18n's `availableLocales` is stable across the lifetime of
 * the i18n instance, so a non-reactive plain function is sufficient.
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
