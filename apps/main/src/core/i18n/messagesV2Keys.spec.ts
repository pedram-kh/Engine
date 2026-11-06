/**
 * Messaging v2 + brand colour i18n keys — presence, placeholder integrity and
 * "not an English copy" gate across all 24 UI locales (the "flaky ten" bg, el,
 * et, fi, ga, hu, lt, lv, mt, ro included).
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, 'locales')
const SOT = 'en'
const TARGETS = UI_LOCALES.filter((locale) => locale !== SOT)

const INBOX = [
  'tagDirect',
  'tagCampaign',
  'allBrands',
  'allCampaigns',
  'searchBrands',
  'searchCampaigns',
  'brandsHeading',
  'campaignsHeading',
  'selectedCount',
  'clearSelection',
  'clearFilters',
  'ownerMine',
  'ownerAll',
  'ownerFilterLabel',
  'noBrandMatch',
  'noCampaignMatch',
  'viewCampaign',
  'noOwner',
  'loadMore',
  'filteredEmpty',
].map((k) => `app.messaging.inbox.${k}`)

const THREAD = [
  'moreActions',
  'reassignOwner',
  'reassignTitle',
  'reassignHint',
  'reassignSelect',
  'reassignConfirm',
  'reassignCancel',
  'reassignDone',
  'reassignError',
  'ownerLabel',
].map((k) => `app.messaging.thread.${k}`)

const ATTACHMENT = [
  'attachmentDropHint',
  'attachmentImageUnavailable',
  'attachmentPasteUnsupportedType',
].map((k) => `app.messaging.${k}`)

const COLOURS = ['violet', 'green', 'amber', 'blue', 'pink', 'teal', 'red', 'slate'].map(
  (k) => `app.brands.color.${k}`,
)

const KEYS = [...INBOX, ...THREAD, ...ATTACHMENT, 'app.brands.fields.color', ...COLOURS]

/**
 * (locale:key) pairs that are legitimately identical to English.
 */
const SAME_AS_ENGLISH_BY_DESIGN = new Set<string>([
  // Brand and campaign names are placeholders; the template has no translatable words.
  ...TARGETS.map((locale) => `${locale}:app.messaging.inbox.tagCampaign`),
  // "Direct" is the same word in French, Romanian and Dutch.
  'fr:app.messaging.inbox.tagDirect',
  'ro:app.messaging.inbox.tagDirect',
  'nl:app.messaging.inbox.tagDirect',
  // The product's own nav label for "Brands" is the English loanword in da, el and mt.
  'da:app.messaging.inbox.brandsHeading',
  'el:app.messaging.inbox.brandsHeading',
  'mt:app.messaging.inbox.brandsHeading',
  // "Mine" is the same word in Danish (possessive "mine").
  'da:app.messaging.inbox.ownerMine',
  // "Violet" is the same colour word in French and Romanian.
  'fr:app.brands.color.violet',
  'ro:app.brands.color.violet',
])

const NAMED_TOKEN = /\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g

function load(locale: string): unknown {
  return JSON.parse(readFileSync(path.join(LOCALE_ROOT, locale, 'app.json'), 'utf8')) as unknown
}

function valueAt(bundle: unknown, key: string): unknown {
  let node: unknown = bundle
  for (const part of key.split('.')) {
    if (typeof node !== 'object' || node === null) {
      return undefined
    }
    node = (node as Record<string, unknown>)[part]
  }
  return node
}

function tokens(value: string): string[] {
  return [...new Set([...value.matchAll(NAMED_TOKEN)].map((m) => m[1] as string))].sort()
}

describe('messaging v2 / brand colour i18n keys', () => {
  it('checks 42 keys across 24 locales', () => {
    expect(KEYS).toHaveLength(42)
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

  it('keeps the " · " separator in tagCampaign and {count} in every plural form', () => {
    for (const locale of UI_LOCALES) {
      const bundle = load(locale)
      expect(valueAt(bundle, 'app.messaging.inbox.tagCampaign')).toBe('{brand} · {campaign}')
      const forms = (valueAt(bundle, 'app.messaging.inbox.selectedCount') as string).split('|')
      expect(forms).toHaveLength(2)
      for (const form of forms) {
        expect(form).toContain('{count}')
      }
    }
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
