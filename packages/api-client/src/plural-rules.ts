/**
 * Plural-rule registry — the single runtime source of truth for how each
 * of the 24 EU languages pluralises, shared by both SPAs' vue-i18n
 * bootstraps (see `docs/00-MASTER-ARCHITECTURE.md` §13).
 *
 * Two pieces:
 *
 *   - {@link PLURAL_CATEGORIES} — the per-locale list of CLDR cardinal
 *     plural categories, in canonical order with `other` last. This is the
 *     SOT: it defines (a) how many plural forms a locale's messages must
 *     provide and (b) the ORDER message authors write the pipe-separated
 *     forms in. Hand-authored, but pinned to the runtime ICU/CLDR data by
 *     `plural-rules.spec.ts`, which probes {@link Intl.PluralRules} and
 *     fails if this table ever drifts from the engine.
 *
 *   - {@link buildPluralRules} — the vue-i18n `pluralRules` map. Each rule
 *     maps a count to the INDEX of the matching form, using
 *     {@link Intl.PluralRules} (the vetted CLDR engine) to choose the
 *     category and {@link PLURAL_CATEGORIES} to map that category to an
 *     index.
 *
 * Why not lean on vue-i18n's built-in pluralisation? Its default rule is
 * English-shaped (`n === 1 ? 0 : 1`, with a 3-form zero shortcut) and is
 * simply wrong for the Slavic/Baltic/Celtic languages in the EU set
 * (Polish/Czech/Slovak/Lithuanian want one/few/many/other; Irish/Maltese
 * want five forms; Latvian has a `zero` category). Delegating category
 * selection to `Intl.PluralRules` makes all 24 correct from one code path.
 */

import { EU_LANGUAGES, type EuLanguage } from './locales'

/** A CLDR cardinal plural category. */
export type PluralCategory = 'zero' | 'one' | 'two' | 'few' | 'many' | 'other'

/**
 * Canonical CLDR category order. Per-locale lists in
 * {@link PLURAL_CATEGORIES} are subsequences of this, so a category's index
 * is stable and `other` (the always-present catch-all) is always last.
 */
export const CATEGORY_ORDER: readonly PluralCategory[] = [
  'zero',
  'one',
  'two',
  'few',
  'many',
  'other',
]

/**
 * Per-locale CLDR cardinal categories (canonical order, `other` last).
 * Grounded against `Intl.PluralRules` (ICU/CLDR) by `plural-rules.spec.ts`
 * — keep this table and the engine in lockstep; a mismatch fails CI.
 *
 * Note the Romance `many` (fr/it/pt/es) — a real CLDR category for large
 * cardinals (e.g. 1,000,000) — and Latvian's `zero`. These are not the
 * English two-form shape, which is exactly why the table exists.
 */
export const PLURAL_CATEGORIES: Record<EuLanguage, readonly PluralCategory[]> = {
  bg: ['one', 'other'],
  hr: ['one', 'few', 'other'],
  cs: ['one', 'few', 'many', 'other'],
  da: ['one', 'other'],
  nl: ['one', 'other'],
  en: ['one', 'other'],
  et: ['one', 'other'],
  fi: ['one', 'other'],
  fr: ['one', 'many', 'other'],
  de: ['one', 'other'],
  el: ['one', 'other'],
  hu: ['one', 'other'],
  ga: ['one', 'two', 'few', 'many', 'other'],
  it: ['one', 'many', 'other'],
  lv: ['zero', 'one', 'other'],
  lt: ['one', 'few', 'many', 'other'],
  mt: ['one', 'two', 'few', 'many', 'other'],
  pl: ['one', 'few', 'many', 'other'],
  pt: ['one', 'many', 'other'],
  ro: ['one', 'few', 'other'],
  sk: ['one', 'few', 'many', 'other'],
  sl: ['one', 'two', 'few', 'other'],
  es: ['one', 'many', 'other'],
  sv: ['one', 'other'],
}

/**
 * The number of plural forms a locale's pipe-separated messages must
 * provide. Unknown locales fall back to the English two-form shape.
 */
export function pluralFormCount(locale: string): number {
  return PLURAL_CATEGORIES[locale as EuLanguage]?.length ?? 2
}

/**
 * A vue-i18n pluralisation rule: maps a `choice` (the count) and the number
 * of forms the message actually provides to the zero-based index of the
 * form to render.
 */
export type PluralRule = (choice: number, choicesLength: number) => number

/**
 * Build the per-locale `pluralRules` map for vue-i18n's `createI18n`.
 *
 * Each rule picks the CLDR category for `|choice|` via `Intl.PluralRules`,
 * maps it to its index in {@link PLURAL_CATEGORIES}, then CLAMPS to the
 * forms the message actually authored — so an under-provided message
 * degrades to its last form (`other`) instead of rendering `undefined`.
 * The `Intl.PluralRules` instances are constructed once per locale here.
 */
export function buildPluralRules(): Record<string, PluralRule> {
  const rules: Record<string, PluralRule> = {}

  for (const locale of EU_LANGUAGES) {
    const order = PLURAL_CATEGORIES[locale]
    const selector = new Intl.PluralRules(locale)

    rules[locale] = (choice: number, choicesLength: number): number => {
      if (choicesLength <= 1) return 0
      const category = selector.select(Math.abs(choice))
      const index = order.indexOf(category)
      const resolved = index === -1 ? order.length - 1 : index
      return Math.min(resolved, choicesLength - 1)
    }
  }

  return rules
}
