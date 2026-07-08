/**
 * Locale registry — the single runtime source of truth for the language
 * sets shared by both SPAs and mirrored on the backend.
 *
 * Three distinct concepts (see `docs/00-MASTER-ARCHITECTURE.md` §13):
 *
 *   - {@link WORLD_LANGUAGES} — every living ISO 639-1 language. This is
 *     the set used for a CREATOR's spoken-language metadata
 *     (`primary_language`, `secondary_languages`): creators come from
 *     anywhere, so speaker metadata is legitimately the world set.
 *
 *   - {@link EU_LANGUAGES} — all 24 official EU languages. Used for an
 *     agency/brand default CONTENT language (the language campaigns are
 *     produced in, which follows the platform's operating markets).
 *
 *   - {@link UI_LOCALES} — the subset we actually RENDER in the UI. This
 *     drives the language switcher and validates `preferred_language`.
 *     Selecting a UI language we cannot render would silently fall back
 *     to `en`, making the stored value a lie — so the rendered set is
 *     validated separately. Today it is the full {@link EU_LANGUAGES}
 *     set (flipped as the final action of the locale generation pass, S8).
 *
 * The parallel backend `App\Core\Enums\Locale` enum mirrors
 * {@link EU_LANGUAGES} (its cases), {@link UI_LOCALES} (its `UI_LOCALES`
 * constant), and {@link WORLD_LANGUAGES} (its `WORLD_LANGUAGES`
 * constant), held in lockstep by the TS<->PHP parity architecture test
 * (standing standard 5.25).
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
export const UI_LOCALES = EU_LANGUAGES

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

/**
 * Every living natural language with an ISO 639-1 code (each fits
 * `char(2)`). Superset of {@link EU_LANGUAGES}. Excluded on purpose:
 * historical / liturgical codes (ae Avestan, cu Church Slavonic,
 * la Latin, pi Pali), constructed languages (eo, ia, ie, io, vo), and
 * the deprecated collection code bh. Mirrored by the backend
 * `Locale::WORLD_LANGUAGES` constant (TS<->PHP parity test).
 */
export const WORLD_LANGUAGES = [
  'aa',
  'ab',
  'af',
  'ak',
  'am',
  'an',
  'ar',
  'as',
  'av',
  'ay',
  'az',
  'ba',
  'be',
  'bg',
  'bi',
  'bm',
  'bn',
  'bo',
  'br',
  'bs',
  'ca',
  'ce',
  'ch',
  'co',
  'cr',
  'cs',
  'cv',
  'cy',
  'da',
  'de',
  'dv',
  'dz',
  'ee',
  'el',
  'en',
  'es',
  'et',
  'eu',
  'fa',
  'ff',
  'fi',
  'fj',
  'fo',
  'fr',
  'fy',
  'ga',
  'gd',
  'gl',
  'gn',
  'gu',
  'gv',
  'ha',
  'he',
  'hi',
  'ho',
  'hr',
  'ht',
  'hu',
  'hy',
  'hz',
  'id',
  'ig',
  'ii',
  'ik',
  'is',
  'it',
  'iu',
  'ja',
  'jv',
  'ka',
  'kg',
  'ki',
  'kj',
  'kk',
  'kl',
  'km',
  'kn',
  'ko',
  'kr',
  'ks',
  'ku',
  'kv',
  'kw',
  'ky',
  'lb',
  'lg',
  'li',
  'ln',
  'lo',
  'lt',
  'lu',
  'lv',
  'mg',
  'mh',
  'mi',
  'mk',
  'ml',
  'mn',
  'mr',
  'ms',
  'mt',
  'my',
  'na',
  'nb',
  'nd',
  'ne',
  'ng',
  'nl',
  'nn',
  'no',
  'nr',
  'nv',
  'ny',
  'oc',
  'oj',
  'om',
  'or',
  'os',
  'pa',
  'pl',
  'ps',
  'pt',
  'qu',
  'rm',
  'rn',
  'ro',
  'ru',
  'rw',
  'sa',
  'sc',
  'sd',
  'se',
  'sg',
  'si',
  'sk',
  'sl',
  'sm',
  'sn',
  'so',
  'sq',
  'sr',
  'ss',
  'st',
  'su',
  'sv',
  'sw',
  'ta',
  'te',
  'tg',
  'th',
  'ti',
  'tk',
  'tl',
  'tn',
  'to',
  'tr',
  'ts',
  'tt',
  'tw',
  'ty',
  'ug',
  'uk',
  'ur',
  'uz',
  've',
  'vi',
  'wa',
  'wo',
  'xh',
  'yi',
  'yo',
  'za',
  'zh',
  'zu',
] as const

/** One of the world (ISO 639-1) language codes. */
export type WorldLanguage = (typeof WORLD_LANGUAGES)[number]

/**
 * Endonyms for the non-EU world languages. Generated from CLDR
 * (`Intl.DisplayNames` in each language's own locale) with hand-checked
 * fills where CLDR lacks a self-name and falls back to the English
 * exonym (e.g. Fijian, Navajo). Merged with {@link LANGUAGE_ENDONYMS}
 * in {@link WORLD_LANGUAGE_ENDONYMS}.
 */
const WORLD_LANGUAGE_EXTRA_ENDONYMS: Record<Exclude<WorldLanguage, EuLanguage>, string> = {
  aa: 'Qafar', // Afar
  ab: 'Аԥсшәа', // Abkhazian
  af: 'Afrikaans', // Afrikaans
  ak: 'Akan', // Akan
  am: 'አማርኛ', // Amharic
  an: 'Aragonés', // Aragonese
  ar: 'العربية', // Arabic
  as: 'অসমীয়া', // Assamese
  av: 'Авар мацӀ', // Avaric
  ay: 'Aymar aru', // Aymara
  az: 'Azərbaycan', // Azerbaijani
  ba: 'Башҡортса', // Bashkir
  be: 'Беларуская', // Belarusian
  bi: 'Bislama', // Bislama
  bm: 'Bamanakan', // Bambara
  bn: 'বাংলা', // Bangla
  bo: 'བོད་སྐད་', // Tibetan
  br: 'Brezhoneg', // Breton
  bs: 'Bosanski', // Bosnian
  ca: 'Català', // Catalan
  ce: 'Нохчийн', // Chechen
  ch: 'Chamoru', // Chamorro
  co: 'Corsu', // Corsican
  cr: 'Nēhiyawēwin', // Cree
  cv: 'Чӑваш', // Chuvash
  cy: 'Cymraeg', // Welsh
  dv: 'ދިވެހި', // Divehi
  dz: 'རྫོང་ཁ', // Dzongkha
  ee: 'Eʋegbe', // Ewe
  eu: 'Euskara', // Basque
  fa: 'فارسی', // Persian
  ff: 'Pulaar', // Fula
  fj: 'Na Vosa Vakaviti', // Fijian
  fo: 'Føroyskt', // Faroese
  fy: 'Frysk', // Western Frisian
  gd: 'Gàidhlig', // Scottish Gaelic
  gl: 'Galego', // Galician
  gn: 'Avañeʼẽ', // Guarani
  gu: 'ગુજરાતી', // Gujarati
  gv: 'Gaelg', // Manx
  ha: 'Hausa', // Hausa
  he: 'עברית', // Hebrew
  hi: 'हिन्दी', // Hindi
  ho: 'Hiri Motu', // Hiri Motu
  ht: 'Kreyòl ayisyen', // Haitian Creole
  hy: 'Հայերեն', // Armenian
  hz: 'Otjiherero', // Herero
  id: 'Bahasa Indonesia', // Indonesian
  ig: 'Igbo', // Igbo
  ii: 'ꆈꌠꉙ', // Sichuan Yi
  ik: 'Iñupiaq', // Inupiaq
  is: 'Íslenska', // Icelandic
  iu: 'ᐃᓄᒃᑎᑐᑦ', // Inuktitut
  ja: '日本語', // Japanese
  jv: 'Basa Jawa', // Javanese
  ka: 'ქართული', // Georgian
  kg: 'Kikongo', // Kongo
  ki: 'Gĩkũyũ', // Kikuyu
  kj: 'Oshikwanyama', // Kuanyama
  kk: 'Қазақ тілі', // Kazakh
  kl: 'Kalaallisut', // Kalaallisut
  km: 'ខ្មែរ', // Khmer
  kn: 'ಕನ್ನಡ', // Kannada
  ko: '한국어', // Korean
  kr: 'Kanuri', // Kanuri
  ks: 'کٲشُر', // Kashmiri
  ku: 'Kurdî', // Kurdish
  kv: 'Коми кыв', // Komi
  kw: 'Kernewek', // Cornish
  ky: 'Кыргызча', // Kyrgyz
  lb: 'Lëtzebuergesch', // Luxembourgish
  lg: 'Luganda', // Ganda
  li: 'Limburgs', // Limburgish
  ln: 'Lingála', // Lingala
  lo: 'ລາວ', // Lao
  lu: 'Tshiluba', // Luba-Katanga
  mg: 'Malagasy', // Malagasy
  mh: 'Kajin M̧ajeļ', // Marshallese
  mi: 'Māori', // Māori
  mk: 'Македонски', // Macedonian
  ml: 'മലയാളം', // Malayalam
  mn: 'Монгол', // Mongolian
  mr: 'मराठी', // Marathi
  ms: 'Bahasa Melayu', // Malay
  my: 'မြန်မာ', // Burmese
  na: 'Dorerin Naoero', // Nauru
  nb: 'Norsk bokmål', // Norwegian Bokmål
  nd: 'IsiNdebele', // North Ndebele
  ne: 'नेपाली', // Nepali
  ng: 'Oshindonga', // Ndonga
  nn: 'Norsk nynorsk', // Norwegian Nynorsk
  no: 'Norsk', // Norwegian
  nr: 'IsiNdebele seSewula', // South Ndebele
  nv: 'Diné bizaad', // Navajo
  ny: 'Chichewa', // Nyanja
  oc: 'Occitan', // Occitan
  oj: 'Anishinaabemowin', // Ojibwa
  om: 'Afaan Oromoo', // Oromo
  or: 'ଓଡ଼ିଆ', // Odia
  os: 'Ирон', // Ossetic
  pa: 'ਪੰਜਾਬੀ', // Punjabi
  ps: 'پښتو', // Pashto
  qu: 'Runasimi', // Quechua
  rm: 'Rumantsch', // Romansh
  rn: 'Ikirundi', // Rundi
  ru: 'Русский', // Russian
  rw: 'Ikinyarwanda', // Kinyarwanda
  sa: 'संस्कृतम्', // Sanskrit
  sc: 'Sardu', // Sardinian
  sd: 'سنڌي', // Sindhi
  se: 'Davvisámegiella', // Northern Sami
  sg: 'Sängö', // Sango
  si: 'සිංහල', // Sinhala
  sm: 'Gagana Sāmoa', // Samoan
  sn: 'ChiShona', // Shona
  so: 'Soomaali', // Somali
  sq: 'Shqip', // Albanian
  sr: 'Српски', // Serbian
  ss: 'SiSwati', // Swati
  st: 'Sesotho', // Southern Sotho
  su: 'Basa Sunda', // Sundanese
  sw: 'Kiswahili', // Swahili
  ta: 'தமிழ்', // Tamil
  te: 'తెలుగు', // Telugu
  tg: 'Тоҷикӣ', // Tajik
  th: 'ไทย', // Thai
  ti: 'ትግርኛ', // Tigrinya
  tk: 'Türkmen dili', // Turkmen
  tl: 'Filipino', // Filipino (Tagalog)
  tn: 'Setswana', // Tswana
  to: 'Lea fakatonga', // Tongan
  tr: 'Türkçe', // Turkish
  ts: 'Xitsonga', // Tsonga
  tt: 'Татар', // Tatar
  tw: 'Twi', // Twi
  ty: 'Reo Tahiti', // Tahitian
  ug: 'ئۇيغۇرچە', // Uyghur
  uk: 'Українська', // Ukrainian
  ur: 'اردو', // Urdu
  uz: 'Oʻzbek', // Uzbek
  ve: 'Tshivenḓa', // Venda
  vi: 'Tiếng Việt', // Vietnamese
  wa: 'Walon', // Walloon
  wo: 'Wolof', // Wolof
  xh: 'IsiXhosa', // Xhosa
  yi: 'ייִדיש', // Yiddish
  yo: 'Èdè Yorùbá', // Yoruba
  za: 'Vahcuengh', // Zhuang
  zh: '中文', // Chinese
  zu: 'IsiZulu', // Zulu
}

/** Endonyms for the full world set (EU map takes precedence for the 24). */
export const WORLD_LANGUAGE_ENDONYMS: Record<WorldLanguage, string> = {
  ...WORLD_LANGUAGE_EXTRA_ENDONYMS,
  ...LANGUAGE_ENDONYMS,
}

/** An option for a content-language picker / display. */
export interface LanguageOption {
  value: string
  label: string
}

function buildOptions(
  codes: readonly string[],
  endonyms: Record<string, string>,
): LanguageOption[] {
  const collator = new Intl.Collator('en', { sensitivity: 'base' })
  const rest = codes
    .filter((code) => code !== 'en')
    .sort((a, b) => collator.compare(endonyms[a] ?? a, endonyms[b] ?? b))
  return [
    { value: 'en', label: endonyms.en ?? 'English' },
    ...rest.map((code) => ({ value: code, label: endonyms[code] ?? code })),
  ]
}

/**
 * Content-language options for all 24 EU languages, labelled by endonym
 * and ordered English-first, then alphabetically by endonym (a stable,
 * locale-neutral collation). Use for agency/brand `default_language`
 * pickers (content is produced in operating-market languages).
 */
export function euLanguageOptions(): LanguageOption[] {
  return buildOptions(EU_LANGUAGES, LANGUAGE_ENDONYMS)
}

/**
 * Spoken-language options for the full world set, labelled by endonym
 * and ordered English-first, then alphabetically by endonym. Use for
 * creator `primary_language` / `secondary_languages` pickers and the
 * Discover / Roster language filters.
 */
export function worldLanguageOptions(): LanguageOption[] {
  return buildOptions(WORLD_LANGUAGES, WORLD_LANGUAGE_ENDONYMS)
}

/** The endonym for a code, or the raw code if it is not a known language. */
export function languageEndonym(code: string): string {
  return (WORLD_LANGUAGE_ENDONYMS as Record<string, string>)[code] ?? code
}
