/**
 * Source-inspection architecture gate — every `t(...)` call site in the
 * main SPA's own source resolves against the `en` message bundle.
 *
 * Sibling of `apps/admin/tests/unit/architecture/i18n-used-keys-resolve.spec.ts`
 * (AH-094), ported verbatim for this SPA's larger surface. Same motivating
 * gap: `i18n-locale-parity.spec.ts` diffs each non-`en` locale against `en`
 * (missing/extra keys, placeholder drift, plural-form drift) — it never
 * asks "does anything in `src/` actually call a key that doesn't exist
 * ANYWHERE, including `en`, the SOT itself?". A key used by a component but
 * absent from every bundle — a typo'd path, a renamed namespace, a stale
 * copy-pasted prefix — passes `i18n-locale-parity` cleanly (nothing to diff
 * against) and fails silently at runtime as a raw dotted-key render.
 * `i18n-auth-codes.spec.ts` / `i18n-creator-codes.spec.ts` are the closest
 * existing relatives, but both are scoped to specific backend-code
 * prefixes (`auth.*`/`rate_limit.*`, `creator.*`) harvested from
 * `apps/api`, not to `t()` calls in this SPA's own source. This test closes
 * that gap for `apps/main`.
 *
 * Two call shapes are harvested:
 *   1. STATIC   — `t('creator.foo.bar')` / `t("creator.foo.bar")`: the
 *      whole dotted path is a literal; resolved exactly.
 *   2. TEMPLATE-PREFIX — `` t(`creator.foo.${dynamicPart}`) ``: only the
 *      literal portion before the first `${` is checked, and only for
 *      existing AS A NAMESPACE (an object, not required to be a leaf) —
 *      the dynamic suffix is one of finitely many enum-like values (a
 *      status, a kind, ...) this test cannot enumerate statically. This
 *      still catches a renamed/typo'd prefix, which is the failure mode
 *      that actually produces a raw-key render.
 *
 * A THIRD shape — `` t(`${variable}.title`) ``, where the prefix itself is
 * a variable — has no static prefix at all and is deliberately NOT
 * harvested (there is nothing to check). None exist in this SPA today
 * (the harvest below finds zero); if one is introduced, it silently isn't
 * covered by this test — same accepted limitation as admin's sibling.
 */

import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')
const EN_LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales/en')

/** `t(` preceded by a non-identifier char (or start of file) — excludes `format(`, `Object.t(`, etc. */
const STATIC_CALL = /\bt\(\s*['"]([a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)+)['"]/g
const TEMPLATE_CALL = /\bt\(\s*`([^`]*)`/g

interface Harvested {
  key: string
  file: string
  kind: 'static' | 'prefix'
}

function walkSourceFiles(directory: string, out: string[] = []): string[] {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      walkSourceFiles(absolute, out)
    } else if (/\.(vue|ts)$/.test(entry.name) && !entry.name.endsWith('.spec.ts')) {
      out.push(absolute)
    }
  }
  return out
}

function harvestFromFile(absolutePath: string, contents: string): Harvested[] {
  const relative = path.relative(SRC_ROOT, absolutePath)
  const found: Harvested[] = []

  for (const match of contents.matchAll(STATIC_CALL)) {
    const key = match[1]
    if (key !== undefined) {
      found.push({ key, file: relative, kind: 'static' })
    }
  }

  for (const match of contents.matchAll(TEMPLATE_CALL)) {
    const template = match[1] ?? ''
    const dollarIndex = template.indexOf('${')
    // No static prefix at all (dynamic from the first character) — nothing
    // to check; see the docblock's "third shape" note.
    if (dollarIndex <= 0) {
      continue
    }
    const prefix = template.slice(0, dollarIndex).replace(/\.$/, '')
    found.push({ key: prefix, file: relative, kind: 'prefix' })
  }

  return found
}

function harvestAll(): Harvested[] {
  const files = walkSourceFiles(SRC_ROOT)
  const out: Harvested[] = []
  for (const file of files) {
    out.push(...harvestFromFile(file, readFileSync(file, 'utf8')))
  }
  return out
}

function buildEnBundle(): unknown {
  const files = readdirSync(EN_LOCALE_ROOT).filter((file) => file.endsWith('.json'))
  let merged: Record<string, unknown> = {}
  for (const file of files) {
    const data = JSON.parse(readFileSync(path.join(EN_LOCALE_ROOT, file), 'utf8')) as Record<
      string,
      unknown
    >
    merged = deepMerge(merged, data)
  }
  return merged
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function deepMerge(
  a: Record<string, unknown>,
  b: Record<string, unknown>,
): Record<string, unknown> {
  const out: Record<string, unknown> = { ...a }
  for (const key of Object.keys(b)) {
    const existing = out[key]
    const incoming = b[key]
    out[key] =
      isPlainObject(existing) && isPlainObject(incoming) ? deepMerge(existing, incoming) : incoming
  }
  return out
}

/** Resolves to a leaf string (a real message), not merely a path that exists. */
function resolvesToString(bundle: unknown, dottedKey: string): boolean {
  let cursor: unknown = bundle
  for (const segment of dottedKey.split('.')) {
    if (!isPlainObject(cursor) || !Object.prototype.hasOwnProperty.call(cursor, segment)) {
      return false
    }
    cursor = cursor[segment]
  }
  return typeof cursor === 'string'
}

/** Resolves to an object (a namespace a dynamic suffix can still land in). */
function resolvesToNamespace(bundle: unknown, dottedKey: string): boolean {
  let cursor: unknown = bundle
  for (const segment of dottedKey.split('.')) {
    if (!isPlainObject(cursor) || !Object.prototype.hasOwnProperty.call(cursor, segment)) {
      return false
    }
    cursor = cursor[segment]
  }
  return isPlainObject(cursor)
}

describe('main i18n — every t() call site resolves against en (used-key coverage)', () => {
  it('harvests calls from the SPA source (sanity check)', () => {
    const harvested = harvestAll()
    expect(harvested.length).toBeGreaterThan(200)
    expect(harvested.some((h) => h.key.startsWith('creator.'))).toBe(true)
  })

  it("every static t('dotted.key') call resolves to a string in en", () => {
    const bundle = buildEnBundle()
    const missing = harvestAll()
      .filter((h) => h.kind === 'static')
      .filter((h) => !resolvesToString(bundle, h.key))

    if (missing.length > 0) {
      const lines = missing.map((m) => `  - ${m.key}  (${m.file})`)
      throw new Error(
        [
          `${missing.length} t() call(s) reference a key missing from en:`,
          ...lines,
          '',
          'Add the key under the correct apps/main/src/core/i18n/locales/en/*.json ' +
            "file, or fix the call site if the path is stale/typo'd.",
        ].join('\n'),
      )
    }
  })

  it('every template-literal t(`static.prefix.${dynamic}`) prefix resolves to a namespace in en', () => {
    const bundle = buildEnBundle()
    const missing = harvestAll()
      .filter((h) => h.kind === 'prefix')
      .filter((h) => !resolvesToNamespace(bundle, h.key))

    if (missing.length > 0) {
      const lines = missing.map((m) => `  - ${m.key}.\${...}  (${m.file})`)
      throw new Error(
        [
          `${missing.length} template-literal t() call(s) have a prefix missing from en:`,
          ...lines,
        ].join('\n'),
      )
    }
  })
})
