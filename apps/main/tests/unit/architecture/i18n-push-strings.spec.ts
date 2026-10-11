/**
 * AH-102 (C3.2) — the push strings in all 24 UI locales.
 *
 * The existing `i18n-notifications-parity.spec.ts` already pins that every
 * locale exposes an IDENTICAL key-set, so a key missing from one locale fails
 * there. This spec adds what parity cannot see:
 *
 *  1. the AH-102 keys exist at all, and are non-empty strings (parity passes
 *     trivially if a key is absent from EVERY locale);
 *  2. the VALUE check (WORKING-PROCESS §3/§4): no non-English locale ships an
 *     English string — the failure mode is a translator-less locale falling back
 *     to English, which a key-set comparison cannot detect because the key is
 *     present. It runs over all 23 non-English locales and names the flaky ten
 *     (bg, el, et, fi, ga, hu, lt, lv, mt, ro) as a non-vacuity check, because
 *     those are where machine-translation baselines have gone missing before;
 *  3. no placeholder syntax: these strings are static (the lock-screen copy with
 *     names lives server-side in `lang/*\/push.php`), so a stray `{name}` would
 *     render literally.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')

const FLAKY_TEN = ['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro'] as const

/** Every key AH-102 added under `notifications.*`, dotted from the bundle root. */
const PUSH_KEYS = [
  'notifications.preferences.channels.push',
  'notifications.preferences.push.off',
  'notifications.preferences.push.enable',
  'notifications.preferences.push.blocked',
  'notifications.preferences.push.openSettings',
  'notifications.push.softAsk.title',
  'notifications.push.softAsk.body',
  'notifications.push.softAsk.note',
  'notifications.push.softAsk.accept',
  'notifications.push.softAsk.decline',
  'notifications.push.channel.name',
  'notifications.push.channel.description',
] as const

/**
 * The ONE key allowed to equal English. "Push" is the loanword every EU locale's
 * product UI uses for the column header (it is not an untranslated fallback), and
 * a column header has no room for a paraphrase. It is asserted to be exactly
 * "Push" below so the exemption cannot quietly widen to a real string.
 */
const SAME_AS_ENGLISH_BY_DESIGN = ['notifications.preferences.channels.push'] as const

async function load(locale: string): Promise<Record<string, unknown>> {
  const file = path.join(LOCALE_ROOT, locale, 'notifications.json')
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

describe('AH-102 push strings — 24 locales', () => {
  it('covers all 24 UI locales (the spec is looking at the full registry)', () => {
    expect(UI_LOCALES).toHaveLength(24)
    for (const locale of FLAKY_TEN) {
      expect(UI_LOCALES).toContain(locale)
    }
  })

  it('every locale has every push key as a non-empty string', async () => {
    const missing: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = await load(locale)
      for (const key of PUSH_KEYS) {
        const value = valueAt(bundle, key)
        if (typeof value !== 'string' || value.trim() === '') {
          missing.push(`${locale}:${key}`)
        }
      }
    }
    expect(missing).toEqual([])
  })

  it('VALUE CHECK: no non-English locale ships an English string (flaky ten included)', async () => {
    const en = await load('en')
    const offenders: string[] = []

    for (const locale of UI_LOCALES) {
      if (locale === 'en') {
        continue
      }
      const bundle = await load(locale)
      for (const key of PUSH_KEYS) {
        if ((SAME_AS_ENGLISH_BY_DESIGN as readonly string[]).includes(key)) {
          continue
        }
        if (valueAt(bundle, key) === valueAt(en, key)) {
          offenders.push(`${locale}:${key}`)
        }
      }
    }
    expect(offenders).toEqual([])
  })

  it('the one same-as-English exemption is exactly the "Push" column header, everywhere', async () => {
    for (const locale of UI_LOCALES) {
      const bundle = await load(locale)
      for (const key of SAME_AS_ENGLISH_BY_DESIGN) {
        expect(valueAt(bundle, key)).toBe('Push')
      }
    }
  })

  it('the value check is not vacuous: the English source differs from a translated flaky-ten locale on every checked key', async () => {
    const en = await load('en')
    const bg = await load('bg')
    const checked = PUSH_KEYS.filter(
      (key) => !(SAME_AS_ENGLISH_BY_DESIGN as readonly string[]).includes(key),
    )

    expect(checked).toHaveLength(PUSH_KEYS.length - 1)
    for (const key of checked) {
      expect(typeof valueAt(en, key)).toBe('string')
      expect(valueAt(bg, key)).not.toBe(valueAt(en, key))
    }
  })

  it('no push string carries placeholder syntax (the strings are static)', async () => {
    const offenders: string[] = []
    for (const locale of UI_LOCALES) {
      const bundle = await load(locale)
      for (const key of PUSH_KEYS) {
        const value = valueAt(bundle, key)
        if (typeof value === 'string' && /[{}@|]/.test(value)) {
          offenders.push(`${locale}:${key}`)
        }
      }
    }
    expect(offenders).toEqual([])
  })
})
