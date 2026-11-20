/**
 * PL-2.2 — i18n keys of the messaging matrix (step 3), the synced-angle wording on the persona card, the delete and copy
 * dialog lines that count cells, and the stepper's cell count: presence, placeholder integrity, plural shape and the
 * "not an English copy" gate across all 24 UI locales. Same shape as `strategyKeys.spec.ts`.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, 'locales')
const SOT = 'en'
const TARGETS = UI_LOCALES.filter((locale) => locale !== SOT)

const KEYS = [
  'app.strategy.stepper.matrixStatus',
  'app.strategy.stepper.matrixNone',
  'app.strategy.counts.angles',
  'app.strategy.counts.cells',
  'app.strategy.matrix.title',
  'app.strategy.matrix.subtitle',
  'app.strategy.matrix.level.priority',
  'app.strategy.matrix.level.support',
  'app.strategy.matrix.level.low',
  'app.strategy.matrix.legend.priorityHint',
  'app.strategy.matrix.legend.supportHint',
  'app.strategy.matrix.legend.lowHint',
  'app.strategy.matrix.corner',
  'app.strategy.matrix.leadWith',
  'app.strategy.matrix.notSet',
  'app.strategy.matrix.tabProgress',
  'app.strategy.matrix.handNote',
  'app.strategy.matrix.footer',
  'app.strategy.matrix.noUsps',
  'app.strategy.matrix.noPersonas',
  'app.strategy.matrix.goToPersonas',
  'app.strategy.matrix.retry',
  'app.strategy.matrix.editor.level',
  'app.strategy.matrix.editor.rationale',
  'app.strategy.matrix.editor.messageLine',
  'app.strategy.matrix.editor.counter',
  'app.strategy.matrix.editor.lineRequired',
  'app.strategy.matrix.editor.saveNext',
  'app.strategy.matrix.editor.clear',
  'app.strategy.persona.syncedFromMatrix',
  'app.strategy.persona.changeInMatrix',
  'app.strategy.persona.removeAngleBodySyncs',
  'app.strategy.persona.deleteBodyCells',
  'app.strategy.usp.usedByBoth',
  'app.strategy.usp.deleteDialog.bodyInUseCells',
  'app.strategy.usp.deleteDialog.confirmInUseCells',
  'app.strategy.usp.deleteDialog.itemCell',
  'app.strategy.copy.synced',
  'app.strategy.errors.angleSyncedReadOnly',
]

/** Keys whose value is a plural pair ("one | other"). */
const PLURAL_KEYS = [
  'app.strategy.stepper.matrixStatus',
  'app.strategy.counts.angles',
  'app.strategy.counts.cells',
  'app.strategy.usp.deleteDialog.bodyInUseCells',
  'app.strategy.usp.deleteDialog.confirmInUseCells',
  'app.strategy.copy.synced',
]

/** `locale:key` pairs that are the same word as English on purpose (the product terms "USP" and "persona" in the column corner; "angle" in French), checked by hand. */
const SAME_AS_ENGLISH_BY_DESIGN = new Set<string>([
  'cs:app.strategy.matrix.corner',
  'da:app.strategy.matrix.corner',
  'fi:app.strategy.matrix.corner',
  'fr:app.strategy.counts.angles',
  'fr:app.strategy.matrix.corner',
  'hr:app.strategy.matrix.corner',
  'it:app.strategy.matrix.corner',
  'lt:app.strategy.matrix.corner',
  'lv:app.strategy.matrix.corner',
  'nl:app.strategy.matrix.corner',
  'pl:app.strategy.matrix.corner',
  'pt:app.strategy.matrix.corner',
  'ro:app.strategy.matrix.corner',
  'sl:app.strategy.matrix.corner',
  'sv:app.strategy.matrix.corner',
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

describe('PL-2.2 matrix strings', () => {
  it('checks 39 keys across 24 locales', () => {
    expect(KEYS).toHaveLength(39)
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
        const needsCount = !key.endsWith('matrixStatus') && !key.endsWith('synced')
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
