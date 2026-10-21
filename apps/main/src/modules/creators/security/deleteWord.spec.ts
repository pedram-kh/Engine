/**
 * AH-104b-del P6 — the typed confirmation word, for all 24 locales.
 *
 * For every locale: the word exists, is not the button label, survives the case and accent
 * changes a phone keyboard introduces, and does not accept a different word. Greek final
 * sigma and tonos, Polish and Maltese diacritics, Romanian cedilla vs comma-below and the
 * Cyrillic word for Bulgarian are the cases the plan names.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { UI_LOCALES } from '@catalyst/api-client'
import { describe, expect, it } from 'vitest'

import { normalizeTypedWord, typedWordMatches } from './deleteWord'

const LOCALES = path.resolve(__dirname, '../../../core/i18n/locales')

function bundle(locale: string): {
  creator: { ui: { security: { dialog: { word: string }; delete: { button: string } } } }
} {
  return JSON.parse(readFileSync(path.join(LOCALES, locale, 'creator.json'), 'utf8'))
}

describe('the confirmation word, per locale', () => {
  it('covers all 24 locales', () => {
    expect(UI_LOCALES).toHaveLength(24)
  })

  it.each([...UI_LOCALES])(
    '%s: exists, differs from the button label, tolerates the keyboard',
    (locale) => {
      const { dialog, delete: del } = bundle(locale).creator.ui.security
      const word = dialog.word

      expect(word.trim()).not.toBe('')
      // A single word, so "type the word" is one gesture.
      expect(word).not.toMatch(/\s/u)
      // Not the button: tapping the button label into the field would defeat the pause.
      expect(normalizeTypedWord(word, locale)).not.toBe(normalizeTypedWord(del.button, locale))

      // The word itself, lower-cased, Capitalised, padded, and decomposed (NFD).
      expect(typedWordMatches(word, word, locale)).toBe(true)
      expect(typedWordMatches(word.toLocaleLowerCase(locale), word, locale)).toBe(true)
      expect(
        typedWordMatches(word.charAt(0) + word.slice(1).toLocaleLowerCase(locale), word, locale),
      ).toBe(true)
      expect(typedWordMatches(`  ${word}  `, word, locale)).toBe(true)
      expect(typedWordMatches(word.normalize('NFD'), word, locale)).toBe(true)

      // A keyboard that drops every diacritic still matches.
      const stripped = word.normalize('NFD').replace(/\p{M}/gu, '')
      expect(typedWordMatches(stripped, word, locale)).toBe(true)

      // And it is not a free pass.
      expect(typedWordMatches('', word, locale)).toBe(false)
      expect(typedWordMatches(`${word}x`, word, locale)).toBe(false)
      expect(typedWordMatches(word.slice(0, -1), word, locale)).toBe(false)
    },
  )

  it('Greek: the lower-case word with tonos and a final sigma matches the upper-case word', () => {
    expect(typedWordMatches('διαγραφή', 'ΔΙΑΓΡΑΦΗ', 'el')).toBe(true)
    expect(typedWordMatches('Διαγραφη', 'ΔΙΑΓΡΑΦΗ', 'el')).toBe(true)
    // Final sigma and medial sigma are the same capital.
    expect(normalizeTypedWord('σας', 'el')).toBe(normalizeTypedWord('ΣΑΣ', 'el'))
  })

  it('Bulgarian: the Cyrillic word matches in lower case, and the Latin look-alike does not', () => {
    const word = bundle('bg').creator.ui.security.dialog.word
    expect(typedWordMatches(word.toLocaleLowerCase('bg'), word, 'bg')).toBe(true)
    // Latin "U3TPUU" is not "ИЗТРИЙ": homoglyphs do not unlock the button.
    expect(typedWordMatches('U3TPUU', word, 'bg')).toBe(false)
  })

  it('Romanian: cedilla and comma-below spellings of the same letter are one word', () => {
    expect(typedWordMatches('ŞTERGE', 'ȘTERGE', 'ro')).toBe(true)
    expect(typedWordMatches('știrge', 'ȘTERGE', 'ro')).toBe(false)
  })

  it('Polish and Maltese: a keyboard without the letters still matches', () => {
    expect(typedWordMatches('usun', 'USUŃ', 'pl')).toBe(true)
    expect(typedWordMatches('hassar', 'ĦASSAR', 'mt')).toBe(false) // Ħ has no decomposition: a different letter
    expect(typedWordMatches('ħassar', 'ĦASSAR', 'mt')).toBe(true)
  })

  it('a different word in the same language is refused', () => {
    expect(typedWordMatches('CANCEL', 'DELETE', 'en')).toBe(false)
    expect(typedWordMatches('LÖSCHE', 'LÖSCHEN', 'de')).toBe(false)
  })
})
