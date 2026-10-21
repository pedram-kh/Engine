/**
 * AH-104b-del P6 — the typed word on the delete-account confirmation.
 *
 * It is a UX gate (a pause before an irreversible step), NOT a security control: the
 * security control is the re-authentication, and the server never sees the word (it only
 * asks for `acknowledged: true`). So the comparison is deliberately forgiving about what a
 * phone keyboard does to a word:
 *
 *   - NFC first, so a composed and a decomposed spelling are the same word;
 *   - trimmed and inner-whitespace-collapsed (autocomplete adds a trailing space);
 *   - upper-cased WITH the UI locale (Greek `διαγραφή` -> `ΔΙΑΓΡΑΦΗ`, tonos dropped, and the
 *     final sigma `ς` -> `Σ`; auto-capitalised input in any case);
 *   - then combining marks stripped, so a keyboard that omits the diacritic (`USUN` for
 *     `USUŃ`, `S` cedilla vs comma-below for Romanian) still matches.
 *
 * Both sides pass through the same function, so being lenient never makes two different
 * words equal unless they differ only by case, spacing or a diacritic.
 */
export function normalizeTypedWord(input: string, locale: string): string {
  return input
    .normalize('NFC')
    .trim()
    .replace(/\s+/gu, ' ')
    .toLocaleUpperCase(locale)
    .normalize('NFD')
    .replace(/\p{M}/gu, '')
    .normalize('NFC')
}

/** True when `typed` is the locale's confirmation word under {@link normalizeTypedWord}. */
export function typedWordMatches(typed: string, word: string, locale: string): boolean {
  const expected = normalizeTypedWord(word, locale)
  return expected !== '' && normalizeTypedWord(typed, locale) === expected
}
