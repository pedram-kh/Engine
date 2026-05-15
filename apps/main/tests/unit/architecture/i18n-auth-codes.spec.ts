/**
 * Source-inspection regression test (binding new test pattern,
 * extending standard 5.1):
 *
 *   1. Walk every `*.php` file under
 *      `apps/api/app/Modules/Identity/`.
 *   2. Harvest every string literal matching either:
 *        - `auth.<dotted.path>`        — Identity-module error codes.
 *        - `rate_limit.<dotted.path>`  — chunk-7.1 named-limiter codes.
 *        - `invitation.<dotted.path>`  — Sprint 3 Chunk 4 magic-link
 *                                        invitation acceptance codes.
 *   3. For each harvested literal, assert it resolves to a string in
 *      the SPA's `en/auth.json`, `pt/auth.json`, AND `it/auth.json`
 *      bundles.
 *
 * The walk is deliberate: a backend-author who adds a new error code
 * must also ship a translation in all three locales, or this test
 * fails before merge. The test does NOT rely on a hand-maintained
 * code list — drift is the only thing it can detect, and that is
 * precisely the failure mode we care about.
 *
 * Path-collision policy: a backend code MUST NOT collide with a
 * parent path of another code. Resolve collisions by giving each
 * leaf its own dotted-path sibling on the backend (chunk 6.2-6.4
 * change-request #1 took this route for `auth.account_locked` →
 * `auth.account_locked.suspended`). This test enforces the policy
 * implicitly: a dotted path lands on a string or it doesn't.
 *
 * The allowlist below covers Laravel's framework config keys that
 * happen to live under the `auth.` namespace (config()->get(...) calls)
 * — they are NOT translation strings and the SPA is not expected to
 * carry them.
 *
 * Why `rate_limit.*` lives in `auth.json` (chunk 7.1 deviation note):
 * the only emit-sites today are inside `IdentityServiceProvider::registerRateLimits()`,
 * so the codes are conceptually part of the auth surface. If a future
 * non-auth limiter adopts the `rate_limit.*` prefix, the bundle entries
 * should split into a dedicated `errors.json` namespace at that point.
 * Until then a single bundle file keeps the locale-pair-edit story
 * (en + pt + it always edited together) tight.
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

/**
 * Regex-walks the file contents and returns the set of dotted-path
 * string literals it finds for each tracked top-level prefix
 * (`auth.*`, `rate_limit.*`), minus the {@link CONFIG_KEY_ALLOWLIST}
 * entries.
 */
function harvestAuthCodes(contents: string): Set<string> {
  const codes = new Set<string>()
  // Combined alternation. Matches both single and double quoted PHP
  // strings. Does NOT match `auth_*` / `rate_limit_*` (no dot),
  // `auth/login` (slash), or array-access syntax like
  // `$config['auth']['foo']`.
  const pattern = /['"](auth|rate_limit|invitation)\.[a-z_][a-zA-Z0-9_.]*['"]/g
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

/**
 * Walk a dotted path through a nested message object. Returns
 * `{ found: true, value }` only when the path lands on a string. A
 * path that lands on an object (parent path) is NOT a hit — backend
 * codes must resolve to leaf strings, and a colliding parent leaf
 * must be renamed on the backend rather than papered over with a
 * sentinel child (see file docblock).
 */
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

describe('i18n auth bundle covers every backend auth.* and rate_limit.* code', () => {
  it('harvests at least one code from the backend Identity module (sanity check)', async () => {
    const codes = await harvestAllAuthCodes()
    // If this fires, the regex-walk broke or the Identity tree moved —
    // do NOT loosen the assertion to silence the test.
    expect(codes.size).toBeGreaterThan(10)
    // Spot-check a known code so a regression that swallows all matches
    // surfaces immediately.
    expect(codes.has('auth.invalid_credentials')).toBe(true)
    expect(codes.has('auth.mfa.invalid_code')).toBe(true)
    // Pin the chunk-6.2-6.4 change-request #1 rename: the backend now
    // emits `auth.account_locked.suspended` (a leaf sibling of
    // `auth.account_locked.temporary`) rather than the colliding
    // parent leaf `auth.account_locked`. A botched rename where an
    // emit-site is updated but a test isn't (or vice-versa) trips
    // here BEFORE the per-locale resolution checks fire.
    expect(codes.has('auth.account_locked.suspended')).toBe(true)
    expect(codes.has('auth.account_locked')).toBe(false)
    // Chunk 7.1: the `rate_limit.exceeded` emit-site is the four
    // named limiters in IdentityServiceProvider. Pin the harvest so
    // a future rename of the code on the backend trips here before
    // the per-locale resolution checks fire.
    expect(codes.has('rate_limit.exceeded')).toBe(true)
  })

  for (const locale of ['en', 'pt', 'it'] as const) {
    it(`every harvested code resolves to a string in ${locale}/auth.json`, async () => {
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
            `Locale ${locale} is missing translations for ${missing.length} auth.* code(s):`,
            ...missing.map((code) => `  - ${code}`),
            '',
            `Add each key to apps/main/src/core/i18n/locales/${locale}/auth.json. ` +
              'If a backend code collides with a parent path of another code, ' +
              'rename the colliding code on the backend to give it its own ' +
              'leaf sibling — do NOT add a sentinel child to the bundle.',
          ].join('\n'),
        )
      }
    })
  }

  it('correctly skips Laravel framework config keys via the explicit allowlist', async () => {
    // Defensive: if the allowlist drifts away from what the harvester
    // sees, this test is the canary. Both entries below are
    // `config()->get(...)` lookups, NOT translation strings.
    const codes = await harvestAllAuthCodes()
    expect(codes.has('auth.admin_mfa_enforced')).toBe(false)
    expect(codes.has('auth.passwords.users.expire')).toBe(false)
  })
})
