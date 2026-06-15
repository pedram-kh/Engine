/**
 * Architecture test — locale registry integrity + TS<->PHP parity
 * (standing standard 5.25).
 *
 * The frontend `EU_LANGUAGES` / `UI_LOCALES` registry in `locales.ts` and
 * the backend `App\Core\Enums\Locale` enum are two copies of the same
 * source of truth. This source-inspection test parses the PHP enum (no
 * eval) and asserts:
 *   - the enum CASES match `EU_LANGUAGES` (all 24, content-language set);
 *   - the enum's `UI_LOCALES` constant matches `UI_LOCALES` (rendered set).
 *
 * If either drifts, accepting a value on one layer that the other rejects
 * becomes possible — exactly the silent incoherence the split guards.
 */

import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

import { EU_LANGUAGES, UI_LOCALES, LANGUAGE_ENDONYMS, euLanguageOptions } from './locales'

const __dirname = dirname(fileURLToPath(import.meta.url))
// packages/api-client/src -> api-client -> packages -> repo root
const REPO_ROOT = join(__dirname, '..', '..', '..')
const BACKEND_ENUM_PATH = join(REPO_ROOT, 'apps', 'api', 'app', 'Core', 'Enums', 'Locale.php')

/** Extract the backing values of every `case X = 'yy';` in the enum. */
function parsePhpEnumCases(php: string): string[] {
  const items: string[] = []
  const re = /case\s+\w+\s*=\s*'([a-z]{2})'\s*;/g
  let m: RegExpExecArray | null
  while ((m = re.exec(php)) !== null) {
    if (m[1] !== undefined) items.push(m[1])
  }
  return items
}

/** Extract the quoted strings of a `const array NAME = [...]` declaration. */
function parsePhpArrayConst(php: string, constName: string): string[] {
  const re = new RegExp(`const array ${constName} = \\[([\\s\\S]*?)\\];`, 'm')
  const match = re.exec(php)
  if (match === null) {
    throw new Error(`Could not find PHP const ${constName} in Locale.php`)
  }
  const items: string[] = []
  const itemRe = /'([^']+)'/g
  let m: RegExpExecArray | null
  while ((m = itemRe.exec(match[1] ?? '')) !== null) {
    if (m[1] !== undefined) items.push(m[1])
  }
  return items
}

describe('locale registry integrity', () => {
  it('EU_LANGUAGES has all 24 EU languages, no duplicates', () => {
    expect(EU_LANGUAGES).toHaveLength(24)
    expect(new Set(EU_LANGUAGES).size).toBe(24)
  })

  it('UI_LOCALES is a subset of EU_LANGUAGES', () => {
    for (const code of UI_LOCALES) {
      expect(EU_LANGUAGES).toContain(code)
    }
  })

  it('LANGUAGE_ENDONYMS has exactly one entry per EU language', () => {
    expect(Object.keys(LANGUAGE_ENDONYMS).sort()).toEqual([...EU_LANGUAGES].sort())
  })

  it('euLanguageOptions lists all 24, English first, then by endonym', () => {
    const options = euLanguageOptions()
    expect(options).toHaveLength(24)
    expect(options[0]?.value).toBe('en')

    const rest = options.slice(1).map((o) => o.label)
    const sorted = [...rest].sort((a, b) =>
      new Intl.Collator('en', { sensitivity: 'base' }).compare(a, b),
    )
    expect(rest).toEqual(sorted)
  })
})

describe('TS <-> PHP locale parity (standing standard 5.25)', () => {
  const php = readFileSync(BACKEND_ENUM_PATH, 'utf-8')

  it('Locale enum cases match EU_LANGUAGES', () => {
    const backend = parsePhpEnumCases(php)
    expect(backend).toHaveLength(24)
    expect([...backend].sort()).toEqual([...EU_LANGUAGES].sort())
  })

  it('Locale::UI_LOCALES matches the TS UI_LOCALES', () => {
    const backend = parsePhpArrayConst(php, 'UI_LOCALES')
    expect([...backend].sort()).toEqual([...UI_LOCALES].sort())
  })
})
