/**
 * AH-103 (C4.3) — the one string this chunk added, in all 24 UI locales.
 *
 * `i18n-locale-parity.spec.ts` already fails a locale whose key-set differs from en.
 * What parity cannot see, and this spec adds (the AH-102 locale process,
 * WORKING-PROCESS §3/§4):
 *
 *  1. the key exists at all, as a non-empty string, in every locale (parity passes
 *     trivially if a key is absent from EVERY locale);
 *  2. the VALUE check: no non-English locale ships English — a translator-less
 *     locale falling back to English keeps its key, so a key-set comparison cannot
 *     detect it. It runs over all 23 non-English locales and names the flaky ten
 *     (bg, el, et, fi, ga, hu, lt, lv, mt, ro) as a non-vacuity check, because
 *     those are where machine-translation baselines have gone missing before;
 *  3. no placeholder syntax: the string is a static link label, so a stray `{x}`
 *     would render literally.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')

const FLAKY_TEN = ['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro'] as const

/** Every key AH-103 added, dotted from the `creator.json` bundle root. */
const KEYS = ['creator.ui.assignments.detail.notFoundAction'] as const

async function load(locale: string): Promise<Record<string, unknown>> {
  const file = path.join(LOCALE_ROOT, locale, 'creator.json')
  return JSON.parse(await fs.readFile(file, 'utf8')) as Record<string, unknown>
}

function valueAt(bundle: Record<string, unknown>, dotted: string): unknown {
  let node: unknown = bundle
  for (const segment of dotted.split('.')) {
    if (typeof node !== 'object' || node === null) {
      return undefined
    }
    node = (node as Record<string, unknown>)[segment]
  }
  return node
}

describe('AH-103 deep-link strings — 24 locales', () => {
  it('covers all 24 UI locales, including the flaky ten', () => {
    expect(UI_LOCALES).toHaveLength(24)
    for (const locale of FLAKY_TEN) {
      expect(UI_LOCALES).toContain(locale)
    }
  })

  it('every locale has every key as a non-empty string, with no placeholder syntax', async () => {
    for (const locale of UI_LOCALES) {
      const bundle = await load(locale)
      for (const key of KEYS) {
        const value = valueAt(bundle, key)
        expect(typeof value, `${locale}:${key}`).toBe('string')
        expect((value as string).trim().length, `${locale}:${key}`).toBeGreaterThan(0)
        expect(value as string, `${locale}:${key}`).not.toMatch(/[{}]/)
      }
    }
  })

  it('VALUE CHECK: no non-English locale ships the English string', async () => {
    const en = await load('en')
    const offenders: string[] = []

    for (const locale of UI_LOCALES) {
      if (locale === 'en') {
        continue
      }
      const bundle = await load(locale)
      for (const key of KEYS) {
        if (valueAt(bundle, key) === valueAt(en, key)) {
          offenders.push(`${locale}:${key}`)
        }
      }
    }

    expect(offenders, `English left in a non-English locale: ${offenders.join(', ')}`).toEqual([])
  })

  it('the value check is not vacuous: the flaky ten each differ from en', async () => {
    const en = await load('en')

    for (const locale of FLAKY_TEN) {
      const bundle = await load(locale)
      for (const key of KEYS) {
        expect(valueAt(bundle, key), `${locale}:${key}`).not.toBe(valueAt(en, key))
      }
    }
  })
})
