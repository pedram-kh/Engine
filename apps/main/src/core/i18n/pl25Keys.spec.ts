/**
 * PL-2.5 — i18n keys of the stepper states, "Mark as complete", the internal notes, the tracker's "set by hand" and the
 * market-removal notes question: presence, placeholder integrity, plural shape and the "not an English copy" gate across
 * all 24 UI locales. Same shape as `matrixKeys.spec.ts`.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, 'locales')
const SOT = 'en'
const TARGETS = UI_LOCALES.filter((locale) => locale !== SOT)

const KEYS = [
  'app.strategy.stepper.marketsOf',
  'app.strategy.stepper.state.not_started',
  'app.strategy.stepper.state.in_progress',
  'app.strategy.stepper.state.done',
  'app.strategy.completion.complete',
  'app.strategy.completion.auto',
  'app.strategy.completion.markedBy',
  'app.strategy.completion.markedByFormer',
  'app.strategy.completion.mark',
  'app.strategy.completion.reopen',
  'app.strategy.completion.confirmTitle',
  'app.strategy.completion.confirmBody',
  'app.strategy.completion.anyway',
  'app.strategy.completion.nothingTitle',
  'app.strategy.completion.nothing.usps',
  'app.strategy.completion.nothing.personas',
  'app.strategy.completion.nothing.matrix',
  'app.strategy.completion.warnUsps',
  'app.strategy.completion.warnPersonas',
  'app.strategy.completion.warnCells',
  'app.strategy.notes.title',
  'app.strategy.notes.internal',
  'app.strategy.notes.empty',
  'app.strategy.notes.placeholder',
  'app.strategy.notes.add',
  'app.strategy.notes.edit',
  'app.strategy.notes.delete',
  'app.strategy.notes.editLabel',
  'app.strategy.notes.edited',
  'app.strategy.notes.former',
  'app.strategy.notes.deleteTitle',
  'app.strategy.notes.deleteBody',
  'app.strategy.notes.loadFailed',
  'app.strategy.notes.retry',
  'app.strategy.notes.tooLong',
  'app.strategy.notes.open',
  'app.strategy.notes.sheetTitle',
  'app.strategy.errors.nothingToComplete',
  'app.strategy.errors.noteThreadFull',
  'app.strategy.errors.noteForbidden',
  'app.strategy.errors.noteBodyInvalid',
  'app.waves.tracker.incomplete.title',
  'app.waves.tracker.incomplete.body',
  'app.waves.tracker.incomplete.bodyMarkets',
  'app.waves.tracker.incomplete.confirm',
  'app.waves.tracker.handSet.plain',
  'app.waves.tracker.handSet.markets',
  'app.waves.dialog.notesWarning',
  'app.waves.dialog.notesConfirm',
]

/** Keys whose value is a plural pair ("one | other"). */
const PLURAL_KEYS = [
  'app.strategy.stepper.marketsOf',
  'app.strategy.completion.warnUsps',
  'app.strategy.completion.warnPersonas',
  'app.waves.dialog.notesWarning',
]

/** `locale:key` pairs that are the same word as English on purpose, checked by hand. */
const SAME_AS_ENGLISH_BY_DESIGN = new Set<string>([
  // French "Notes" is the English word (the heading and the two lines built on it).
  'fr:app.strategy.notes.title',
  'fr:app.strategy.notes.open',
  'fr:app.strategy.notes.sheetTitle',
])

type Bundle = Record<string, unknown>

function load(locale: string): Bundle {
  return JSON.parse(readFileSync(path.join(LOCALE_ROOT, locale, 'app.json'), 'utf8')) as Bundle
}

function valueAt(bundle: Bundle, dotted: string): unknown {
  return dotted.split('.').reduce<unknown>((node, part) => {
    if (typeof node === 'object' && node !== null) {
      return (node as Record<string, unknown>)[part]
    }
    return undefined
  }, bundle)
}

function tokens(text: string): string[] {
  return [...text.matchAll(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g)].map((m) => m[1] ?? '').sort()
}

describe('PL-2.5 completion, notes and tracker strings', () => {
  it('checks 49 keys across 24 locales', () => {
    expect(KEYS).toHaveLength(49)
    expect(UI_LOCALES).toHaveLength(24)
  })

  it('every locale has every key as a non-empty string', () => {
    const missing: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = load(locale)
      for (const key of KEYS) {
        const value = valueAt(bundle, key)
        if (typeof value !== 'string' || value.trim() === '') missing.push(`${locale}:${key}`)
      }
    }
    expect(missing).toEqual([])
  })

  it('placeholder sets match en for every key (a plural pair: for each form)', () => {
    const en = load(SOT)
    const offenders: string[] = []
    for (const locale of TARGETS) {
      const bundle = load(locale)
      for (const key of KEYS) {
        const expected = tokens(valueAt(en, key) as string)
        const actual = tokens(valueAt(bundle, key) as string)
        if (JSON.stringify([...new Set(expected)]) !== JSON.stringify([...new Set(actual)])) {
          offenders.push(`${locale}:${key}`)
        }
      }
    }
    expect(offenders).toEqual([])
  })

  it('plural keys are two forms in every locale; the count ones carry {count} in each form', () => {
    const offenders: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = load(locale)
      for (const key of PLURAL_KEYS) {
        const forms = (valueAt(bundle, key) as string).split('|')
        const needsCount =
          key.endsWith('notesWarning') || key.endsWith('warnUsps') || key.endsWith('warnPersonas')
        if (forms.length !== 2 || (needsCount && forms.some((f) => !f.includes('{count}')))) {
          offenders.push(`${locale}:${key}`)
        }
      }
    }
    expect(offenders).toEqual([])
  })

  it('no key outside the plural list is a plural pair', () => {
    const offenders: string[] = []
    for (const key of KEYS) {
      const text = valueAt(load(SOT), key) as string
      if (text.includes('|') && !PLURAL_KEYS.includes(key)) offenders.push(key)
    }
    expect(offenders).toEqual([])
  })

  it('no non-English locale ships an English copy', () => {
    const en = load(SOT)
    const offenders: string[] = []
    for (const locale of TARGETS) {
      const bundle = load(locale)
      for (const key of KEYS) {
        if (SAME_AS_ENGLISH_BY_DESIGN.has(`${locale}:${key}`)) continue
        if (valueAt(bundle, key) === valueAt(en, key)) offenders.push(`${locale}:${key}`)
      }
    }
    expect(offenders).toEqual([])
  })
})
