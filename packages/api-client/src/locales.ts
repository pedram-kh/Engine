/**
 * Locale registry — the single runtime source of truth for the language
 * sets shared by both SPAs and mirrored on the backend.
 *
 * Two distinct concepts (see `docs/00-MASTER-ARCHITECTURE.md` §13):
 *
 *   - {@link EU_LANGUAGES} — all 24 official EU languages. This is the
 *     set used for CONTENT-language metadata (a creator's spoken
 *     languages, an agency/brand default content language). Speaker
 *     metadata is legitimately the full 24.
 *
 *   - {@link UI_LOCALES} — the subset we actually RENDER in the UI. This
 *     drives the language switcher and validates `preferred_language`.
 *     Selecting a UI language we cannot render would silently fall back
 *     to `en`, making the stored value a lie — so the rendered set is
 *     validated separately. Today it is `en`/`pt`/`it`; it flips to the
 *     full {@link EU_LANGUAGES} set as the final action of the locale
 *     generation pass (S8), at which point the only boot-time effect is
 *     the dropdown length.
 *
 * The parallel backend `App\Core\Enums\Locale` enum mirrors
 * {@link EU_LANGUAGES} (its cases) and {@link UI_LOCALES} (its
 * `UI_LOCALES` constant), held in lockstep by the TS<->PHP parity
 * architecture test (standing standard 5.25).
 */

/**
 * All 24 official EU languages, ISO 639-1 (each fits `char(2)`), ordered
 * alphabetically by English name. Order is cosmetic — the parity test
 * sorts before comparing — but mirrors the backend enum case order.
 */
export const EU_LANGUAGES = [
  'bg', // Bulgarian
  'hr', // Croatian
  'cs', // Czech
  'da', // Danish
  'nl', // Dutch
  'en', // English
  'et', // Estonian
  'fi', // Finnish
  'fr', // French
  'de', // German
  'el', // Greek
  'hu', // Hungarian
  'ga', // Irish
  'it', // Italian
  'lv', // Latvian
  'lt', // Lithuanian
  'mt', // Maltese
  'pl', // Polish
  'pt', // Portuguese
  'ro', // Romanian
  'sk', // Slovak
  'sl', // Slovenian
  'es', // Spanish
  'sv', // Swedish
] as const

/** One of the 24 official EU language codes. */
export type EuLanguage = (typeof EU_LANGUAGES)[number]

/**
 * The UI locales we actually render today. Flipped to the full
 * {@link EU_LANGUAGES} set as the last action of the generation pass.
 * Kept in lockstep with the backend `Locale::UI_LOCALES` constant.
 */
export const UI_LOCALES = ['en', 'pt', 'it'] as const

/** A UI locale code we currently render. */
export type UiLocale = (typeof UI_LOCALES)[number]

/**
 * Autonyms (endonyms): each language written in its own name. Locale-
 * independent, so content-language pickers and read-only displays render
 * a language consistently regardless of the active UI locale, without
 * needing a 24x24 translated-label matrix.
 */
export const LANGUAGE_ENDONYMS: Record<EuLanguage, string> = {
  bg: 'Български',
  hr: 'Hrvatski',
  cs: 'Čeština',
  da: 'Dansk',
  nl: 'Nederlands',
  en: 'English',
  et: 'Eesti',
  fi: 'Suomi',
  fr: 'Français',
  de: 'Deutsch',
  el: 'Ελληνικά',
  hu: 'Magyar',
  ga: 'Gaeilge',
  it: 'Italiano',
  lv: 'Latviešu',
  lt: 'Lietuvių',
  mt: 'Malti',
  pl: 'Polski',
  pt: 'Português',
  ro: 'Română',
  sk: 'Slovenčina',
  sl: 'Slovenščina',
  es: 'Español',
  sv: 'Svenska',
}

/** An option for a content-language picker / display. */
export interface LanguageOption {
  value: EuLanguage
  label: string
}

/**
 * Content-language options for all 24 EU languages, labelled by endonym
 * and ordered English-first, then alphabetically by endonym (a stable,
 * locale-neutral collation). Use for `primary_language`,
 * `secondary_languages`, and agency/brand `default_language` pickers.
 */
export function euLanguageOptions(): LanguageOption[] {
  const collator = new Intl.Collator('en', { sensitivity: 'base' })
  const rest = EU_LANGUAGES.filter((code) => code !== 'en').sort((a, b) =>
    collator.compare(LANGUAGE_ENDONYMS[a], LANGUAGE_ENDONYMS[b]),
  )
  return [
    { value: 'en', label: LANGUAGE_ENDONYMS.en },
    ...rest.map((code) => ({ value: code, label: LANGUAGE_ENDONYMS[code] })),
  ]
}

/** The endonym for a code, or the raw code if it is not an EU language. */
export function languageEndonym(code: string): string {
  return (LANGUAGE_ENDONYMS as Record<string, string>)[code] ?? code
}
