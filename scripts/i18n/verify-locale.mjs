#!/usr/bin/env node
/**
 * Pre-flip locale verifier (EU-locale S8) — frontend.
 *
 * Checks a generated locale's SPA bundles against the `en` source of truth
 * BEFORE the locale enters `UI_LOCALES` (the S7 architecture specs only iterate
 * the rendered set, so a not-yet-flipped locale is otherwise unguarded). Same
 * three gates as `i18n-locale-parity.spec.ts`:
 *   1. file-set + keyset parity (no missing / no extra key);
 *   2. placeholder integrity ({named} token set per message == en);
 *   3. plural form-count parity (|-split form count == en) + CLDR renderability
 *      (en form count <= the locale's CLDR category count).
 *
 * Usage:  node scripts/i18n/verify-locale.mjs <locale> [<locale> ...]
 * Exits non-zero (and prints every violation) if any locale drifts from en.
 */

import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const SPAS = ['apps/main', 'apps/admin']
const SOT_LOCALE = 'en'
const NAMED_TOKEN = /\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g

function localesRoot(spa) {
  return path.join(REPO_ROOT, spa, 'src/core/i18n/locales')
}

function namespaceFiles(spa, locale) {
  return readdirSync(path.join(localesRoot(spa), locale))
    .filter((f) => f.endsWith('.json'))
    .sort()
}

function collectLeaves(node, prefix, out) {
  if (typeof node !== 'object' || node === null) return
  for (const [key, value] of Object.entries(node)) {
    const dotted = prefix === '' ? key : `${prefix}.${key}`
    if (typeof value === 'string') out.set(dotted, value)
    else collectLeaves(value, dotted, out)
  }
}

function loadLeaves(spa, locale, file) {
  const out = new Map()
  collectLeaves(JSON.parse(readFileSync(path.join(localesRoot(spa), locale, file), 'utf8')), '', out)
  return out
}

function tokens(value) {
  const set = new Set()
  for (const m of value.matchAll(NAMED_TOKEN)) set.add(m[1])
  return [...set].sort()
}

/** CLDR cardinal category count for a locale, via the ICU engine (the S3 SOT). */
function categoryCount(locale) {
  const pr = new Intl.PluralRules(locale)
  const seen = new Set()
  for (let n = 0; n <= 200; n++) seen.add(pr.select(n))
  for (const n of [0.5, 1.5, 2.5, 10.5, 100.5, 1000000]) seen.add(pr.select(n))
  return seen.size
}

function verify(spa, locale, violations) {
  const sotFiles = namespaceFiles(spa, SOT_LOCALE)
  let targetFiles
  try {
    targetFiles = namespaceFiles(spa, locale)
  } catch {
    violations.push(`${spa}: ${locale} has no locales/${locale} directory`)
    return
  }
  if (JSON.stringify(targetFiles) !== JSON.stringify(sotFiles)) {
    violations.push(`${spa}/${locale}: namespace file set differs from en`)
  }
  const cats = categoryCount(locale)
  for (const file of sotFiles) {
    if (!targetFiles.includes(file)) continue
    const en = loadLeaves(spa, SOT_LOCALE, file)
    const target = loadLeaves(spa, locale, file)
    for (const key of en.keys()) {
      if (!target.has(key)) violations.push(`${spa}/${locale}/${file}: MISSING ${key}`)
    }
    for (const key of target.keys()) {
      if (!en.has(key)) violations.push(`${spa}/${locale}/${file}: EXTRA   ${key}`)
    }
    for (const [key, enValue] of en) {
      const targetValue = target.get(key)
      if (targetValue === undefined) continue
      const enTok = JSON.stringify(tokens(enValue))
      const tgtTok = JSON.stringify(tokens(targetValue))
      if (enTok !== tgtTok) {
        violations.push(`${spa}/${locale}/${file}: ${key} placeholders ${tgtTok} != en ${enTok}`)
      }
      const enForms = enValue.split('|').length
      const tgtForms = targetValue.split('|').length
      if (enForms !== tgtForms) {
        violations.push(`${spa}/${locale}/${file}: ${key} ${tgtForms} plural forms != en ${enForms}`)
      }
      if (enForms > cats) {
        violations.push(`${spa}/${locale}/${file}: ${key} en form count ${enForms} > ${locale} categories ${cats}`)
      }
    }
  }
}

const locales = process.argv.slice(2)
if (locales.length === 0) {
  console.error('usage: node scripts/i18n/verify-locale.mjs <locale> [<locale> ...]')
  process.exit(2)
}

let failed = false
for (const locale of locales) {
  const violations = []
  for (const spa of SPAS) verify(spa, locale, violations)
  if (violations.length === 0) {
    console.log(`PASS  ${locale}  (frontend: main + admin parity with en)`)
  } else {
    failed = true
    console.error(`FAIL  ${locale}  (${violations.length} violations)`)
    for (const v of violations) console.error('  ' + v)
  }
}
process.exit(failed ? 1 : 0)
