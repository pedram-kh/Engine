/**
 * Source-inspection architecture gate (EU-locale S7) — en-SOT PARITY for every
 * rendered UI locale, across EVERY namespace bundle in this SPA.
 *
 * `i18n-notifications-parity.spec.ts` pins parity for the single `notifications`
 * bundle; this generalises that contract to the whole `locales/` tree and adds
 * two further gates a missing-key check can't catch:
 *
 *   1. KEYSET PARITY  — every non-`en` locale exposes EXACTLY the en key-set,
 *      file-by-file (no missing key → no silent English fallback; no extra key
 *      → no dead translation drifting from the SOT).
 *   2. PLACEHOLDER INTEGRITY — each message carries the SAME set of `{named}`
 *      interpolation tokens as its en source, so a translator can't drop
 *      `{count}` or rename `{minutes}` → `{minutos}` and ship a broken render.
 *   3. PLURAL FORM-COUNT — each pluralised (`|`-split) message has the same
 *      number of forms as its en source, that count is valid for `en`
 *      (≤ en's CLDR category count), and the SOT shape stays renderable in
 *      every locale (≤ that locale's category count). Form-count is driven by
 *      the shared `pluralFormCount` registry (the S3 CLDR SOT), NOT hand-typed.
 *
 * The locale list is the shared `UI_LOCALES` registry — the SAME source the app
 * renders from (`createI18n({ availableLocales: [...UI_LOCALES] })`) — so the S8
 * flip to 24 rendered locales extends this gate with no edit here.
 *
 * Literal vue-i18n escapes (`{'@'}`) are intentionally NOT treated as tokens.
 */

import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES, pluralFormCount } from '@catalyst/api-client'

const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')
const SOT_LOCALE = 'en'
const TARGET_LOCALES = UI_LOCALES.filter((locale) => locale !== SOT_LOCALE)

/** vue-i18n named interpolation: `{name}`, `{count}`, `{n}`. Excludes `{'@'}`. */
const NAMED_TOKEN = /\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g

type Leaves = Map<string, string>

function readJson(file: string): unknown {
  return JSON.parse(readFileSync(file, 'utf8')) as unknown
}

function namespaceFiles(locale: string): string[] {
  return readdirSync(path.join(LOCALE_ROOT, locale))
    .filter((file) => file.endsWith('.json'))
    .sort()
}

/** Flatten a nested message object into dotted-key → string-value pairs. */
function collectLeaves(node: unknown, prefix: string, out: Leaves): void {
  if (typeof node !== 'object' || node === null) {
    return
  }
  for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
    const dotted = prefix === '' ? key : `${prefix}.${key}`
    if (typeof value === 'string') {
      out.set(dotted, value)
    } else {
      collectLeaves(value, dotted, out)
    }
  }
}

function loadLeaves(locale: string, file: string): Leaves {
  const out: Leaves = new Map()
  collectLeaves(readJson(path.join(LOCALE_ROOT, locale, file)), '', out)
  return out
}

/** The set of `{named}` tokens used anywhere in a message (across plural forms). */
function placeholderTokens(value: string): Set<string> {
  const tokens = new Set<string>()
  for (const match of value.matchAll(NAMED_TOKEN)) {
    const token = match[1]
    if (token !== undefined) {
      tokens.add(token)
    }
  }
  return tokens
}

/** vue-i18n splits a pluralised message on `|`; a plain message is one form. */
function pluralFormCountOf(value: string): number {
  return value.split('|').length
}

function sortedSet(set: Set<string>): string[] {
  return [...set].sort()
}

describe('i18n locale parity — en SOT across every namespace (S7)', () => {
  it('every locale ships the same set of namespace files as en', () => {
    const sot = namespaceFiles(SOT_LOCALE)
    expect(sot.length).toBeGreaterThan(0)
    for (const locale of TARGET_LOCALES) {
      expect(namespaceFiles(locale), `${locale} namespace files differ from en`).toEqual(sot)
    }
  })

  it('every locale exposes EXACTLY the en key-set, file by file', () => {
    const violations: string[] = []
    for (const file of namespaceFiles(SOT_LOCALE)) {
      const en = loadLeaves(SOT_LOCALE, file)
      for (const locale of TARGET_LOCALES) {
        const target = loadLeaves(locale, file)
        for (const key of en.keys()) {
          if (!target.has(key)) {
            violations.push(`${locale}/${file}: MISSING ${key}`)
          }
        }
        for (const key of target.keys()) {
          if (!en.has(key)) {
            violations.push(`${locale}/${file}: EXTRA   ${key}`)
          }
        }
      }
    }
    expect(violations, `keyset drift from en:\n${violations.join('\n')}`).toEqual([])
  })

  it('every message carries the same {named} placeholders as its en source', () => {
    const violations: string[] = []
    for (const file of namespaceFiles(SOT_LOCALE)) {
      const en = loadLeaves(SOT_LOCALE, file)
      for (const locale of TARGET_LOCALES) {
        const target = loadLeaves(locale, file)
        for (const [key, enValue] of en) {
          const targetValue = target.get(key)
          if (targetValue === undefined) {
            continue // keyset gate already reports the miss
          }
          const enTokens = sortedSet(placeholderTokens(enValue))
          const targetTokens = sortedSet(placeholderTokens(targetValue))
          if (JSON.stringify(enTokens) !== JSON.stringify(targetTokens)) {
            violations.push(
              `${locale}/${file}: ${key} placeholders [${targetTokens}] != en [${enTokens}]`,
            )
          }
        }
      }
    }
    expect(violations, `placeholder drift from en:\n${violations.join('\n')}`).toEqual([])
  })

  it('every pluralised message has a CLDR-valid, en-matching form count', () => {
    const violations: string[] = []
    for (const file of namespaceFiles(SOT_LOCALE)) {
      const en = loadLeaves(SOT_LOCALE, file)
      for (const [key, enValue] of en) {
        const enForms = pluralFormCountOf(enValue)

        // The SOT shape must itself be renderable in en (≤ en's categories).
        if (enForms > pluralFormCount(SOT_LOCALE)) {
          violations.push(
            `en/${file}: ${key} has ${enForms} forms > en category count ${pluralFormCount(SOT_LOCALE)}`,
          )
        }

        for (const locale of TARGET_LOCALES) {
          const targetValue = loadLeaves(locale, file).get(key)
          if (targetValue === undefined) {
            continue
          }
          const targetForms = pluralFormCountOf(targetValue)
          if (targetForms !== enForms) {
            violations.push(
              `${locale}/${file}: ${key} has ${targetForms} plural forms != en ${enForms}`,
            )
          }
          // The en form count must fit this locale's CLDR categories so the
          // S3 clamp renders it without overflow.
          if (enForms > pluralFormCount(locale)) {
            violations.push(
              `${locale}/${file}: ${key} en form count ${enForms} > ${locale} category count ${pluralFormCount(locale)}`,
            )
          }
        }
      }
    }
    expect(violations, `plural form-count drift:\n${violations.join('\n')}`).toEqual([])
  })
})
