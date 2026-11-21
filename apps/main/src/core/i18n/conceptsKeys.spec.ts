/**
 * PL-2.3 — i18n keys of the concepts step (step 4): the stepper lines, the page, the card, the create and edit dialog, the
 * decision dialog, the references (images and links), the refusals with their counts, and the USP and wave lines that now
 * mention concepts. Presence, placeholder integrity, no key is a plural pair, and the "not an English copy" gate across all 24
 * UI locales. Same shape as `matrixKeys.spec.ts`. The old "locked until all concepts are approved" key is gone from every locale.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, 'locales')
const SOT = 'en'
const TARGETS = UI_LOCALES.filter((locale) => locale !== SOT)

const KEYS = [
  'app.strategy.stepper.conceptsNone',
  'app.strategy.stepper.conceptsStatus',
  'app.strategy.stepper.conceptsWarning',
  'app.strategy.stepper.conceptsWarningNone',
  'app.strategy.concepts.title',
  'app.strategy.concepts.subtitle',
  'app.strategy.concepts.add',
  'app.strategy.concepts.tile',
  'app.strategy.concepts.tileMax',
  'app.strategy.concepts.underMinimum',
  'app.strategy.concepts.status.awaiting',
  'app.strategy.concepts.status.approved',
  'app.strategy.concepts.status.changes_requested',
  'app.strategy.concepts.stage.tofu',
  'app.strategy.concepts.stage.mofu',
  'app.strategy.concepts.stage.bofu',
  'app.strategy.concepts.stage.none',
  'app.strategy.concepts.menu.edit',
  'app.strategy.concepts.menu.decide',
  'app.strategy.concepts.menu.duplicate',
  'app.strategy.concepts.menu.delete',
  'app.strategy.concepts.menu.deleteDecided',
  'app.strategy.concepts.banner.counts',
  'app.strategy.concepts.banner.allApproved',
  'app.strategy.concepts.banner.record',
  'app.strategy.concepts.card.number',
  'app.strategy.concepts.card.menu',
  'app.strategy.concepts.card.stage',
  'app.strategy.concepts.card.stageNone',
  'app.strategy.concepts.card.approvedBy',
  'app.strategy.concepts.field.product',
  'app.strategy.concepts.field.angle',
  'app.strategy.concepts.field.primary_message',
  'app.strategy.concepts.field.hypothesis',
  'app.strategy.concepts.field.hook',
  'app.strategy.concepts.field.format_visual',
  'app.strategy.concepts.field.key_points',
  'app.strategy.concepts.field.payoff',
  'app.strategy.concepts.field.cta',
  'app.strategy.concepts.dialog.addTitle',
  'app.strategy.concepts.dialog.editTitle',
  'app.strategy.concepts.dialog.persona',
  'app.strategy.concepts.dialog.stage',
  'app.strategy.concepts.dialog.stageNote',
  'app.strategy.concepts.dialog.title',
  'app.strategy.concepts.dialog.usps',
  'app.strategy.concepts.dialog.uspsHint',
  'app.strategy.concepts.dialog.uspMode',
  'app.strategy.concepts.dialog.uspModeRefill',
  'app.strategy.concepts.dialog.uspModeKeep',
  'app.strategy.concepts.dialog.fixFields',
  'app.strategy.concepts.decision.title',
  'app.strategy.concepts.decision.concept',
  'app.strategy.concepts.decision.clientName',
  'app.strategy.concepts.decision.note',
  'app.strategy.concepts.decision.comment',
  'app.strategy.concepts.decision.replaces',
  'app.strategy.concepts.delete.title',
  'app.strategy.concepts.delete.body',
  'app.strategy.concepts.delete.bodyDecided',
  'app.strategy.concepts.reset.title',
  'app.strategy.concepts.reset.body',
  'app.strategy.concepts.reset.confirm',
  'app.strategy.concepts.images.label',
  'app.strategy.concepts.images.count',
  'app.strategy.concepts.images.add',
  'app.strategy.concepts.images.full',
  'app.strategy.concepts.images.open',
  'app.strategy.concepts.images.remove',
  'app.strategy.concepts.images.close',
  'app.strategy.concepts.images.loadFailed',
  'app.strategy.concepts.links.label',
  'app.strategy.concepts.links.add',
  'app.strategy.concepts.links.full',
  'app.strategy.concepts.links.edit',
  'app.strategy.concepts.links.remove',
  'app.strategy.concepts.link.addTitle',
  'app.strategy.concepts.link.editTitle',
  'app.strategy.concepts.link.titleLabel',
  'app.strategy.concepts.link.urlLabel',
  'app.strategy.concepts.link.urlPlaceholder',
  'app.strategy.concepts.link.problem.empty',
  'app.strategy.concepts.link.problem.scheme',
  'app.strategy.concepts.link.problem.invalid',
  'app.strategy.concepts.upload.refusedType',
  'app.strategy.concepts.upload.refusedEmpty',
  'app.strategy.concepts.upload.refusedCount',
  'app.strategy.concepts.upload.refusedBytes',
  'app.strategy.concepts.upload.failed',
  'app.strategy.concepts.upload.progress',
  'app.strategy.concepts.upload.retry',
  'app.strategy.concepts.upload.dismiss',
  'app.strategy.concepts.emptyState.title',
  'app.strategy.concepts.emptyState.body',
  'app.strategy.concepts.emptyState.noPersonasTitle',
  'app.strategy.concepts.emptyState.noPersonasBody',
  'app.strategy.concepts.emptyState.toPersonas',
  'app.strategy.errors.personaInUseByConcepts',
  'app.strategy.errors.conceptPersonaInvalid',
  'app.strategy.errors.conceptUspModeRequired',
  'app.strategy.errors.conceptUspInvalid',
  'app.strategy.errors.decisionInvalid',
  'app.strategy.errors.conceptDeleteFailed',
  'app.strategy.errors.conceptDuplicateFailed',
  'app.strategy.errors.conceptMarketFull',
  'app.strategy.errors.imageInvalid',
  'app.strategy.errors.imageUnknownUpload',
  'app.strategy.errors.imageAlreadyCompleted',
  'app.strategy.errors.imageDeleteFailed',
  'app.strategy.errors.imageLimitCount',
  'app.strategy.errors.imageLimitBytes',
  'app.strategy.errors.linkInvalidUrl',
  'app.strategy.errors.linkLimitCount',
  'app.strategy.usp.deleteDialog.itemConcept',
  'app.strategy.usp.deleteDialog.conceptsNote',
  'app.waves.errors.marketHasConcepts',
  'app.strategy.validation.required',
]

/** `locale:key` pairs that are the same text as English on purpose (a product term, an address example), checked by hand. */
const SAME_AS_ENGLISH_BY_DESIGN = new Set<string>([
  'bg:app.strategy.concepts.field.cta',
  'cs:app.strategy.concepts.field.hook',
  'cs:app.strategy.concepts.field.cta',
  'cs:app.strategy.concepts.dialog.persona',
  'da:app.strategy.concepts.field.hook',
  'da:app.strategy.concepts.field.cta',
  'da:app.strategy.concepts.dialog.persona',
  'da:app.strategy.concepts.links.label',
  'de:app.strategy.concepts.field.hook',
  'de:app.strategy.concepts.field.cta',
  'de:app.strategy.concepts.dialog.persona',
  'de:app.strategy.concepts.dialog.usps',
  'de:app.strategy.concepts.links.label',
  'el:app.strategy.concepts.title',
  'el:app.strategy.concepts.card.number',
  'el:app.strategy.concepts.field.hook',
  'el:app.strategy.concepts.field.cta',
  'el:app.strategy.concepts.decision.concept',
  'es:app.strategy.concepts.field.cta',
  'es:app.strategy.concepts.dialog.persona',
  'et:app.strategy.concepts.field.cta',
  'et:app.strategy.concepts.dialog.persona',
  'fi:app.strategy.concepts.field.cta',
  'fr:app.strategy.concepts.title',
  'fr:app.strategy.concepts.card.number',
  'fr:app.strategy.concepts.field.angle',
  'fr:app.strategy.concepts.field.cta',
  'fr:app.strategy.concepts.dialog.persona',
  'fr:app.strategy.concepts.decision.concept',
  'fr:app.strategy.concepts.images.label',
  'ga:app.strategy.concepts.field.hook',
  'ga:app.strategy.concepts.field.cta',
  'hr:app.strategy.concepts.field.hook',
  'hr:app.strategy.concepts.field.cta',
  'hr:app.strategy.concepts.dialog.persona',
  'hu:app.strategy.concepts.field.hook',
  'hu:app.strategy.concepts.field.cta',
  'it:app.strategy.concepts.card.number',
  'it:app.strategy.concepts.field.hook',
  'it:app.strategy.concepts.field.cta',
  'it:app.strategy.concepts.dialog.persona',
  'it:app.strategy.concepts.decision.concept',
  'lt:app.strategy.concepts.field.cta',
  'lt:app.strategy.concepts.dialog.persona',
  'lv:app.strategy.concepts.field.cta',
  'lv:app.strategy.concepts.dialog.persona',
  'mt:app.strategy.concepts.field.hook',
  'mt:app.strategy.concepts.field.cta',
  'mt:app.strategy.concepts.dialog.usps',
  'nl:app.strategy.concepts.card.number',
  'nl:app.strategy.concepts.field.product',
  'nl:app.strategy.concepts.field.hook',
  'nl:app.strategy.concepts.field.cta',
  'nl:app.strategy.concepts.dialog.persona',
  'nl:app.strategy.concepts.decision.concept',
  'nl:app.strategy.concepts.links.label',
  'pl:app.strategy.concepts.field.hook',
  'pl:app.strategy.concepts.field.cta',
  'pl:app.strategy.concepts.dialog.persona',
  'pt:app.strategy.concepts.field.cta',
  'pt:app.strategy.concepts.dialog.persona',
  'ro:app.strategy.concepts.card.number',
  'ro:app.strategy.concepts.field.hook',
  'ro:app.strategy.concepts.field.cta',
  'ro:app.strategy.concepts.dialog.persona',
  'ro:app.strategy.concepts.decision.concept',
  'sk:app.strategy.concepts.field.hook',
  'sk:app.strategy.concepts.field.cta',
  'sl:app.strategy.concepts.field.hook',
  'sl:app.strategy.concepts.field.cta',
  'sl:app.strategy.concepts.dialog.persona',
  'sv:app.strategy.concepts.field.hook',
  'sv:app.strategy.concepts.field.cta',
  'sv:app.strategy.concepts.dialog.persona',
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

describe('PL-2.3 concepts strings', () => {
  it('checks 117 keys across 24 locales', () => {
    expect(KEYS).toHaveLength(117)
    expect(new Set(KEYS).size).toBe(KEYS.length)
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

  it('placeholder sets match en for every key', () => {
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

  it('no key is a plural pair in any locale (the counts are not pluralised)', () => {
    const offenders: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = load(locale)
      for (const key of KEYS) {
        if ((valueAt(bundle, key) as string).includes('|')) offenders.push(`${locale}:${key}`)
      }
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

  it('the old locked line is gone from every locale', () => {
    for (const locale of UI_LOCALES) {
      expect(valueAt(load(locale), 'app.strategy.stepper.locked')).toBeUndefined()
    }
  })
})
