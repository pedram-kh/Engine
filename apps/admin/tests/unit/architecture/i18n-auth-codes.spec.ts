/**
 * Source-inspection regression test mirroring
 * `apps/main/tests/unit/architecture/i18n-auth-codes.spec.ts`
 * (binding new test pattern, extending standard 5.1):
 *
 *   1. Walk every `*.php` file under
 *      `apps/api/app/Modules/Identity/`.
 *   2. Harvest every string literal matching either:
 *        - `auth.<dotted.path>`        — Identity-module error codes.
 *        - `rate_limit.<dotted.path>`  — chunk-7.1 named-limiter codes.
 *   3. For each harvested literal, assert it resolves to a string in
 *      the admin SPA's `en/auth.json`, `pt/auth.json`, AND
 *      `it/auth.json` bundles.
 *
 * The walk is deliberate: a backend-author who adds a new error code
 * must also ship a translation in all three admin locales (and in
 * main's, via the parallel test), or both tests fail before merge.
 *
 * Path-(b) decision (sub-chunk 7.3 review): the admin SPA gets its own
 * harvest-vs-resolve test under its own tree rather than extending
 * main's. Rationale:
 *   - Each SPA owns its own bundle independently; coupling the tests
 *     would make a future admin-only key (or an admin-only
 *     translation override) impossible to add without touching main's
 *     test file.
 *   - The harvest logic is identical (same backend tree, same
 *     prefixes); duplication is shallow.
 *   - Honest deviation flagged in the chunk-7.2-to-7.3 review file.
 *
 * Path-collision policy and the Laravel-config allowlist match main's
 * test verbatim — see that test's docblock for the full rationale.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')
const IDENTITY_ROOT = path.resolve(REPO_ROOT, 'apps/api/app/Modules/Identity')
const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')

/**
 * Laravel framework config keys that live under the `auth.` namespace.
 * These are NOT user-facing translation strings — they are pulled from
 * `config/auth.php` via `config()->get(...)` calls. Adding to this
 * allowlist requires a code review; do NOT add an entry just to make
 * the test pass.
 */
const CONFIG_KEY_ALLOWLIST: ReadonlySet<string> = new Set([
  'auth.admin_mfa_enforced',
  'auth.passwords.users.expire',
])

function harvestAuthCodes(contents: string): Set<string> {
  const codes = new Set<string>()
  const pattern = /['"](auth|rate_limit)\.[a-z_][a-zA-Z0-9_.]*['"]/g
  for (const match of contents.matchAll(pattern)) {
    const literal = match[0].slice(1, -1)
    if (!CONFIG_KEY_ALLOWLIST.has(literal)) {
      codes.add(literal)
    }
  }
  return codes
}

async function walkPhpFiles(directory: string): Promise<string[]> {
  const out: string[] = []
  const entries = await fs.readdir(directory, { withFileTypes: true })
  for (const entry of entries) {
    const absolute = path.join(directory, entry.name)
    if (entry.isDirectory()) {
      out.push(...(await walkPhpFiles(absolute)))
    } else if (entry.isFile() && entry.name.endsWith('.php')) {
      out.push(absolute)
    }
  }
  return out
}

async function harvestAllAuthCodes(): Promise<Set<string>> {
  const phpFiles = await walkPhpFiles(IDENTITY_ROOT)
  const codes = new Set<string>()
  for (const file of phpFiles) {
    const contents = await fs.readFile(file, 'utf8')
    for (const code of harvestAuthCodes(contents)) {
      codes.add(code)
    }
  }
  return codes
}

interface ResolutionResult {
  found: boolean
  value?: string
}

function resolveDottedKey(messages: unknown, dottedKey: string): ResolutionResult {
  const segments = dottedKey.split('.')
  let cursor: unknown = messages
  for (const segment of segments) {
    if (typeof cursor !== 'object' || cursor === null || Array.isArray(cursor)) {
      return { found: false }
    }
    const record = cursor as Record<string, unknown>
    if (!Object.prototype.hasOwnProperty.call(record, segment)) {
      return { found: false }
    }
    cursor = record[segment]
  }
  if (typeof cursor === 'string') {
    return { found: true, value: cursor }
  }
  return { found: false }
}

async function loadBundle(locale: 'en' | 'pt' | 'it'): Promise<unknown> {
  const file = path.join(LOCALE_ROOT, locale, 'auth.json')
  const raw = await fs.readFile(file, 'utf8')
  return JSON.parse(raw)
}

describe('admin i18n auth bundle covers every backend auth.* and rate_limit.* code', () => {
  it('harvests at least one code from the backend Identity module (sanity check)', async () => {
    const codes = await harvestAllAuthCodes()
    expect(codes.size).toBeGreaterThan(10)
    expect(codes.has('auth.invalid_credentials')).toBe(true)
    expect(codes.has('auth.mfa.invalid_code')).toBe(true)
    // The admin SPA is the SPA that actually surfaces
    // auth.mfa.enrollment_required (sub-chunk 7.4 guard). Pin the
    // harvest so a future rename of the code on the backend trips here
    // before the per-locale resolution checks fire.
    expect(codes.has('auth.mfa.enrollment_required')).toBe(true)
    expect(codes.has('auth.account_locked.suspended')).toBe(true)
    expect(codes.has('auth.account_locked')).toBe(false)
    expect(codes.has('rate_limit.exceeded')).toBe(true)
  })

  for (const locale of ['en', 'pt', 'it'] as const) {
    it(`every harvested code resolves to a string in admin's ${locale}/auth.json`, async () => {
      const codes = await harvestAllAuthCodes()
      const bundle = await loadBundle(locale)

      const missing: string[] = []
      for (const code of codes) {
        const result = resolveDottedKey(bundle, code)
        if (!result.found) {
          missing.push(code)
        }
      }

      if (missing.length > 0) {
        throw new Error(
          [
            `Admin locale ${locale} is missing translations for ${missing.length} auth.* code(s):`,
            ...missing.map((code) => `  - ${code}`),
            '',
            `Add each key to apps/admin/src/core/i18n/locales/${locale}/auth.json. ` +
              'If a backend code collides with a parent path of another code, ' +
              'rename the colliding code on the backend to give it its own ' +
              'leaf sibling — do NOT add a sentinel child to the bundle.',
          ].join('\n'),
        )
      }
    })
  }

  it('correctly skips Laravel framework config keys via the explicit allowlist', async () => {
    const codes = await harvestAllAuthCodes()
    expect(codes.has('auth.admin_mfa_enforced')).toBe(false)
    expect(codes.has('auth.passwords.users.expire')).toBe(false)
  })
})
