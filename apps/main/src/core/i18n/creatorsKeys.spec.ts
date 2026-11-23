/**
 * PL-3.1 — i18n keys of the Creators step (step 5, the picker, the client's decision, the errors): presence, placeholder
 * integrity, no plural pipes (the strings are count-neutral on purpose) and the "not an English copy" gate across all 24 UI
 * locales. Same shape as `pl25Keys.spec.ts`.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, 'locales')
const SOT = 'en'
const TARGETS = UI_LOCALES.filter((locale) => locale !== SOT)

const KEYS = [
  'app.strategy.creators.title',
  'app.strategy.creators.subtitle',
  'app.strategy.creators.add',
  'app.strategy.creators.close',
  'app.strategy.creators.inWave',
  'app.strategy.creators.addSlotCaption',
  'app.strategy.creators.stepper.none',
  'app.strategy.creators.stepper.count',
  'app.strategy.creators.summary.total',
  'app.strategy.creators.summary.coverage',
  'app.strategy.creators.summary.personaWarning',
  'app.strategy.creators.status.shortlisted',
  'app.strategy.creators.status.contacted',
  'app.strategy.creators.status.confirmed',
  'app.strategy.creators.status.declined',
  'app.strategy.creators.detail.applied',
  'app.strategy.creators.detail.invited',
  'app.strategy.creators.detail.offer_sent',
  'app.strategy.creators.detail.countered',
  'app.strategy.creators.detail.in_work',
  'app.strategy.creators.detail.delivered',
  'app.strategy.creators.detail.draft_rejected',
  'app.strategy.creators.detail.cancelled',
  'app.strategy.creators.detail.application_rejected',
  'app.strategy.creators.card.unnamed',
  'app.strategy.creators.card.notAssigned',
  'app.strategy.creators.card.persona',
  'app.strategy.creators.card.status',
  'app.strategy.creators.card.statusHint',
  'app.strategy.creators.card.slotCode',
  'app.strategy.creators.card.editCode',
  'app.strategy.creators.card.verified',
  'app.strategy.creators.card.example',
  'app.strategy.creators.card.profile',
  'app.strategy.creators.card.replace',
  'app.strategy.creators.card.remove',
  'app.strategy.creators.decision.title',
  'app.strategy.creators.decision.choice',
  'app.strategy.creators.decision.choices.approved',
  'app.strategy.creators.decision.choices.suggest_another',
  'app.strategy.creators.decision.clientName',
  'app.strategy.creators.decision.note',
  'app.strategy.creators.decision.comment',
  'app.strategy.creators.decision.replaces',
  'app.strategy.creators.decision.why.name',
  'app.strategy.creators.decision.why.comment',
  'app.strategy.creators.decision.why.nameAndComment',
  'app.strategy.creators.decision.status.approved',
  'app.strategy.creators.decision.status.suggest_another',
  'app.strategy.creators.decision.record',
  'app.strategy.creators.decision.history',
  'app.strategy.creators.decision.historyTitle',
  'app.strategy.creators.decision.cardMoved',
  'app.strategy.creators.decision.cardNotMoved',
  'app.strategy.creators.remove.title',
  'app.strategy.creators.remove.body',
  'app.strategy.creators.remove.bodyDecisions',
  'app.strategy.creators.empty.title',
  'app.strategy.creators.empty.body',
  'app.strategy.creators.noCampaign.title',
  'app.strategy.creators.noCampaign.body',
  'app.strategy.creators.noCampaign.askAdmin',
  'app.strategy.creators.noCampaign.linkTitle',
  'app.strategy.creators.noCampaign.linkLabel',
  'app.strategy.creators.noCampaign.linkNone',
  'app.strategy.creators.noCampaign.link',
  'app.strategy.creators.noCampaign.createTitle',
  'app.strategy.creators.noCampaign.createLabel',
  'app.strategy.creators.noCampaign.create',
  'app.strategy.creators.picker.title',
  'app.strategy.creators.picker.titleReplace',
  'app.strategy.creators.picker.subtitle',
  'app.strategy.creators.picker.search',
  'app.strategy.creators.picker.country',
  'app.strategy.creators.picker.removeCountry',
  'app.strategy.creators.picker.prefiltered',
  'app.strategy.creators.picker.results',
  'app.strategy.creators.picker.emptyTitle',
  'app.strategy.creators.picker.emptyBody',
  'app.strategy.creators.picker.emptyFilteredTitle',
  'app.strategy.creators.picker.emptyFilteredBody',
  'app.strategy.creators.picker.openCampaign',
  'app.strategy.creators.picker.more',
  'app.strategy.creators.picker.alreadyInWave',
  'app.strategy.creators.picker.alreadyInMarket',
  'app.strategy.creators.picker.applied',
  'app.strategy.creators.picker.invited',
  'app.strategy.creators.picker.selected',
  'app.strategy.creators.picker.assignPersona',
  'app.strategy.creators.picker.choosePerCreator',
  'app.strategy.creators.picker.add',
  'app.strategy.creators.picker.replaceWith',
  'app.strategy.creators.picker.roomMarket',
  'app.strategy.creators.picker.roomWave',
  'app.strategy.creators.errors.campaignRequired',
  'app.strategy.creators.errors.notEligible',
  'app.strategy.creators.errors.alreadyInWave',
  'app.strategy.creators.errors.capMarket',
  'app.strategy.creators.errors.capWave',
  'app.strategy.creators.errors.personaInUse',
  'app.strategy.creators.errors.slotCodeTaken',
  'app.strategy.creators.errors.removeBlockedInWork',
  'app.waves.errors.marketHasCreators',
]

/** `locale:key` pairs that are the same word as English on purpose (a loan word or a name), checked by hand. */
const SAME_AS_ENGLISH_BY_DESIGN = new Set<string>([
  'cs:app.strategy.creators.card.persona',
  'da:app.strategy.creators.title',
  'da:app.strategy.creators.stepper.count',
  'da:app.strategy.creators.summary.total',
  'da:app.strategy.creators.card.persona',
  'da:app.strategy.creators.card.status',
  'de:app.strategy.creators.card.persona',
  'de:app.strategy.creators.card.status',
  'es:app.strategy.creators.card.persona',
  'et:app.strategy.creators.card.persona',
  'fr:app.strategy.creators.card.persona',
  'hr:app.strategy.creators.card.persona',
  'hr:app.strategy.creators.card.status',
  'it:app.strategy.creators.card.persona',
  'lt:app.strategy.creators.card.persona',
  'lv:app.strategy.creators.card.persona',
  'nl:app.strategy.creators.title',
  'nl:app.strategy.creators.stepper.count',
  'nl:app.strategy.creators.summary.total',
  'nl:app.strategy.creators.card.persona',
  'nl:app.strategy.creators.card.status',
  'pl:app.strategy.creators.card.persona',
  'pl:app.strategy.creators.card.status',
  'pt:app.strategy.creators.card.persona',
  'ro:app.strategy.creators.card.persona',
  'sl:app.strategy.creators.card.persona',
  'sv:app.strategy.creators.title',
  'sv:app.strategy.creators.stepper.count',
  'sv:app.strategy.creators.summary.total',
  'sv:app.strategy.creators.card.persona',
  'sv:app.strategy.creators.card.status',
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
  return [
    ...new Set([...text.matchAll(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g)].map((m) => m[1] ?? '')),
  ].sort()
}

describe('PL-3.1 creators strings', () => {
  it('checks 103 keys across 24 locales', () => {
    expect(KEYS).toHaveLength(103)
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
        if (JSON.stringify(expected) !== JSON.stringify(actual)) offenders.push(`${locale}:${key}`)
      }
    }
    expect(offenders).toEqual([])
  })

  it('no key is a plural pair (the strings are count-neutral)', () => {
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
})
