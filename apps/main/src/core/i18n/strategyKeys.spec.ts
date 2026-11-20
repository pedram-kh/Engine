/**
 * PL-2.1 — i18n keys of the strategy workspace (USPs, personas, angles, copy, errors) and the wave dialog's
 * `wave.market_has_personas` message: presence, placeholder integrity, plural shape and the "not an English copy"
 * gate across all 24 UI locales (the "flaky ten" bg, el, et, fi, ga, hu, lt, lv, mt, ro included). Same shape as
 * `waveKeys.spec.ts`.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, 'locales')
const SOT = 'en'
const TARGETS = UI_LOCALES.filter((locale) => locale !== SOT)

const KEYS = [
  'app.strategy.title',
  'app.strategy.entry',
  'app.strategy.back',
  'app.strategy.cancel',
  'app.strategy.close',
  'app.strategy.save',
  'app.strategy.stepper.label',
  'app.strategy.stepper.usps',
  'app.strategy.stepper.personas',
  'app.strategy.stepper.matrix',
  'app.strategy.stepper.concepts',
  'app.strategy.stepper.creators',
  'app.strategy.stepper.scripts',
  'app.strategy.stepper.uspNone',
  'app.strategy.stepper.statusUsps',
  'app.strategy.stepper.personaNone',
  'app.strategy.stepper.statusPersonas',
  'app.strategy.stepper.notAvailable',
  'app.strategy.stepper.locked',
  'app.strategy.audience.label',
  'app.strategy.audience.placeholder',
  'app.strategy.audience.edit',
  'app.strategy.audience.dialogTitle',
  'app.strategy.usp.title',
  'app.strategy.usp.subtitle',
  'app.strategy.usp.counter',
  'app.strategy.usp.counterMax',
  'app.strategy.usp.add',
  'app.strategy.usp.maxReason',
  'app.strategy.usp.warning',
  'app.strategy.usp.emptyTitle',
  'app.strategy.usp.emptyBody',
  'app.strategy.usp.explainer',
  'app.strategy.usp.creativeAngle',
  'app.strategy.usp.copySeed',
  'app.strategy.usp.menu',
  'app.strategy.usp.edit',
  'app.strategy.usp.delete',
  'app.strategy.usp.moveEarlier',
  'app.strategy.usp.moveLater',
  'app.strategy.usp.deleteStaffReason',
  'app.strategy.usp.usedBy',
  'app.strategy.usp.validation.titleRequired',
  'app.strategy.usp.dialog.addTitle',
  'app.strategy.usp.dialog.editTitle',
  'app.strategy.usp.dialog.title',
  'app.strategy.usp.dialog.colourAuto',
  'app.strategy.usp.dialog.colourFixed',
  'app.strategy.usp.deleteDialog.title',
  'app.strategy.usp.deleteDialog.body',
  'app.strategy.usp.deleteDialog.bodyInUse',
  'app.strategy.usp.deleteDialog.confirmInUse',
  'app.strategy.usp.deleteDialog.refreshed',
  'app.strategy.persona.markets',
  'app.strategy.persona.marketLabel',
  'app.strategy.persona.linkAUsp',
  'app.strategy.persona.title',
  'app.strategy.persona.subtitle',
  'app.strategy.persona.add',
  'app.strategy.persona.tile',
  'app.strategy.persona.tileMax',
  'app.strategy.persona.warning',
  'app.strategy.persona.fewerThanMin',
  'app.strategy.persona.emptyState.title',
  'app.strategy.persona.emptyState.body',
  'app.strategy.persona.coreJob',
  'app.strategy.persona.drivers',
  'app.strategy.persona.goals',
  'app.strategy.persona.selfTalk.she',
  'app.strategy.persona.selfTalk.he',
  'app.strategy.persona.selfTalk.they',
  'app.strategy.persona.need.she',
  'app.strategy.persona.need.he',
  'app.strategy.persona.need.they',
  'app.strategy.persona.barriers',
  'app.strategy.persona.angles',
  'app.strategy.persona.menu',
  'app.strategy.persona.edit',
  'app.strategy.persona.delete',
  'app.strategy.persona.deleteTitle',
  'app.strategy.persona.deleteBody',
  'app.strategy.persona.deleteBodyAngles',
  'app.strategy.persona.addedByHand',
  'app.strategy.persona.linked',
  'app.strategy.persona.addAngle',
  'app.strategy.persona.noUsps',
  'app.strategy.persona.goToUsps',
  'app.strategy.persona.angleAdd',
  'app.strategy.persona.angleEdit',
  'app.strategy.persona.angleRemove',
  'app.strategy.persona.angleMenu',
  'app.strategy.persona.anglePlaceholder',
  'app.strategy.persona.angleText',
  'app.strategy.persona.removeAngleTitle',
  'app.strategy.persona.removeAngleBody',
  'app.strategy.persona.pronounName.she',
  'app.strategy.persona.pronounName.he',
  'app.strategy.persona.pronounName.they',
  'app.strategy.persona.dialog.addTitle',
  'app.strategy.persona.dialog.editTitle',
  'app.strategy.persona.dialog.code',
  'app.strategy.persona.dialog.codeAuto',
  'app.strategy.persona.dialog.codeHint',
  'app.strategy.persona.dialog.title',
  'app.strategy.persona.dialog.name',
  'app.strategy.persona.dialog.nameHint',
  'app.strategy.persona.dialog.pronoun',
  'app.strategy.persona.dialog.pronounHint',
  'app.strategy.persona.dialog.summary',
  'app.strategy.persona.dialog.listCount',
  'app.strategy.persona.dialog.addItem',
  'app.strategy.persona.dialog.removeItem',
  'app.strategy.persona.validation.titleRequired',
  'app.strategy.persona.validation.nameRequired',
  'app.strategy.persona.validation.codeRequired',
  'app.strategy.persona.validation.codeFormat',
  'app.strategy.persona.validation.angleRequired',
  'app.strategy.copy.open',
  'app.strategy.copy.title',
  'app.strategy.copy.source',
  'app.strategy.copy.submit',
  'app.strategy.copy.line',
  'app.strategy.copy.over',
  'app.strategy.validation.tooLong',
  'app.strategy.errors.loadFailed',
  'app.strategy.errors.saveFailed',
  'app.strategy.errors.gone',
  'app.strategy.errors.workspaceGone',
  'app.strategy.errors.uspMaxReached',
  'app.strategy.errors.uspOrderMismatch',
  'app.strategy.errors.uspInUse',
  'app.strategy.errors.personaMaxReached',
  'app.strategy.errors.personaMarketNotInWave',
  'app.strategy.errors.personaCodeTaken',
  'app.strategy.errors.copyInvalid',
  'app.strategy.errors.copyExceedsCap',
  'app.strategy.errors.copyExceedsCapPlain',
  'app.strategy.errors.angleAlreadyLinked',
  'app.strategy.errors.conflict',
  'app.strategy.errors.brandArchived',
  'app.strategy.errors.forbidden',
  'app.waves.errors.marketHasPersonas',
]

/** Keys whose value is a plural pair ("one | other"); each form carries {count}. */
const PLURAL_KEYS = [
  'app.strategy.stepper.statusUsps',
  'app.strategy.stepper.statusPersonas',
  'app.strategy.usp.usedBy',
  'app.strategy.usp.deleteDialog.bodyInUse',
  'app.strategy.usp.deleteDialog.confirmInUse',
  'app.strategy.persona.deleteBodyAngles',
]

/**
 * `locale:key` pairs that are the same word as English on purpose: product terms and loanwords (USP, Persona,
 * Code, Creators, Scripts, Concepts), checked by hand. Every other pair must differ from English.
 */
const SAME_AS_ENGLISH_BY_DESIGN = new Set<string>([
  'da:app.strategy.stepper.creators',
  'da:app.strategy.stepper.scripts',
  'de:app.strategy.stepper.usps',
  'de:app.strategy.stepper.personas',
  'de:app.strategy.stepper.statusUsps',
  'de:app.strategy.usp.title',
  'de:app.strategy.persona.title',
  'de:app.strategy.persona.dialog.code',
  'de:app.strategy.persona.dialog.name',
  'es:app.strategy.stepper.personas',
  'es:app.strategy.persona.title',
  'fr:app.strategy.stepper.personas',
  'fr:app.strategy.stepper.concepts',
  'fr:app.strategy.stepper.scripts',
  'fr:app.strategy.persona.title',
  'fr:app.strategy.persona.dialog.code',
  'lv:app.strategy.stepper.personas',
  'lv:app.strategy.persona.title',
  'nl:app.strategy.stepper.creators',
  'nl:app.strategy.stepper.scripts',
  'nl:app.strategy.persona.dialog.code',
  'pt:app.strategy.stepper.personas',
  'pt:app.strategy.persona.title',
  'sv:app.strategy.stepper.creators',
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

describe('PL-2.1 strategy strings', () => {
  it('checks 142 keys across 24 locales', () => {
    expect(KEYS).toHaveLength(142)
    expect(UI_LOCALES).toHaveLength(24)
  })

  it('every locale has every key as a non-empty string', () => {
    const missing: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = load(locale)
      for (const key of KEYS) {
        const value = valueAt(bundle, key)
        if (typeof value !== 'string' || value.trim() === '') {
          missing.push(`${locale}:${key}`)
        }
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
        if (JSON.stringify(expected) !== JSON.stringify(actual)) {
          offenders.push(`${locale}:${key}`)
        }
      }
    }
    expect(offenders).toEqual([])
  })

  it('plural keys are two forms, each with {count}, in every locale', () => {
    const offenders: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = load(locale)
      for (const key of PLURAL_KEYS) {
        const forms = (valueAt(bundle, key) as string).split('|')
        if (forms.length !== 2 || forms.some((f) => !f.includes('{count}'))) {
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

  it('the pronoun labels exist for she, he and they', () => {
    const en = load(SOT)
    for (const p of ['she', 'he', 'they']) {
      expect(valueAt(en, `app.strategy.persona.selfTalk.${p}`)).toEqual(expect.any(String))
      expect(valueAt(en, `app.strategy.persona.need.${p}`)).toEqual(expect.any(String))
      expect(valueAt(en, `app.strategy.persona.pronounName.${p}`)).toEqual(expect.any(String))
    }
    expect(valueAt(en, 'app.strategy.persona.selfTalk.she')).toBe('What she tells herself')
    expect(valueAt(en, 'app.strategy.persona.selfTalk.he')).toBe('What he tells himself')
    expect(valueAt(en, 'app.strategy.persona.selfTalk.they')).toBe('What they tell themselves')
  })

  it('no non-English locale ships an English copy (flaky ten included)', () => {
    const en = load(SOT)
    const offenders: string[] = []
    for (const locale of TARGETS) {
      const bundle = load(locale)
      for (const key of KEYS) {
        if (SAME_AS_ENGLISH_BY_DESIGN.has(`${locale}:${key}`)) {
          continue
        }
        if (valueAt(bundle, key) === valueAt(en, key)) {
          offenders.push(`${locale}:${key}`)
        }
      }
    }
    expect(offenders).toEqual([])
  })
})
