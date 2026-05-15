/**
 * Source-inspection regression test (Sprint 3 Chunk 3 sub-step 1,
 * extending chunk-7.1 standard 5.1):
 *
 *   1. Walk every `*.php` file under
 *      `apps/api/app/Modules/Creators/`.
 *   2. Harvest every string literal matching `creator.<dotted.path>`
 *      with two filters applied:
 *        - skip codes whose first segment is `creator.wizard` AND
 *          ends in `_completed` (audit-event-action names, not
 *          error codes — they ship through the audit pipeline and
 *          never reach the user's i18n surface).
 *        - skip the standalone literal `creator.created` (the
 *          model-level audit event name emitted by the Audited trait;
 *          same rationale).
 *      The CODE_KIND_ALLOWLIST below enumerates the harvest-time
 *      exclusions for surface-level documentation.
 *   3. For each surviving literal, assert it resolves to a string in
 *      the SPA's `en/creator.json`, `pt/creator.json`, AND
 *      `it/creator.json` bundles.
 *
 * Why a dedicated spec rather than extending `i18n-auth-codes.spec.ts`:
 * the docblock at `i18n-auth-codes.spec.ts:32-38` anticipates this
 * split point. Adding `creator.*` as the fourth top-level prefix
 * triggers the split — each per-module spec stays narrow and the
 * blast radius of a backend rename is contained to one test file.
 *
 * Path-collision policy: a backend code MUST NOT collide with a
 * parent path of another code. Resolve collisions by giving each
 * leaf its own dotted-path sibling on the backend (chunk 6.2-6.4
 * change-request #1 precedent). This test enforces the policy
 * implicitly: a dotted path lands on a string or it doesn't.
 *
 * Standing contract: a backend-author who adds a new `creator.*`
 * error code must also ship a translation in all three locales, or
 * this test fails before merge. The chunk-3 widening of
 * `useErrorMessage.isLikelyBundledCode` to accept the `creator.`
 * prefix means a missed bundle entry surfaces as the unknown-error
 * fallback at runtime — which is exactly the regression mode this
 * test catches at CI time.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')
const CREATORS_ROOT = path.resolve(REPO_ROOT, 'apps/api/app/Modules/Creators')
const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')

/**
 * Backend audit-action names that LOOK like error codes (start with
 * `creator.`) but ride the audit pipeline rather than surfacing to
 * users via i18n. They are stored on `audit_logs.action` and
 * exposed only via admin tooling.
 *
 * The harvester drops these so the test stays focused on user-facing
 * error codes. Adding to this allowlist requires a code review; the
 * audit-action enum at `App\Modules\Audit\Enums\AuditAction` is the
 * authoritative source.
 */
const AUDIT_ACTION_ALLOWLIST: ReadonlySet<string> = new Set([
  'creator.created',
  'creator.wizard.profile_completed',
  'creator.wizard.social_completed',
  'creator.wizard.portfolio_completed',
  'creator.wizard.kyc_completed',
  'creator.wizard.tax_completed',
  'creator.wizard.payout_completed',
  'creator.wizard.contract_completed',
  'creator.wizard.submitted',
])

/**
 * Regex-walks the file contents and returns the set of `creator.*`
 * dotted-path string literals it finds, minus the
 * {@link AUDIT_ACTION_ALLOWLIST} entries.
 */
function harvestCreatorCodes(contents: string): Set<string> {
  const codes = new Set<string>()
  // Matches both single and double quoted PHP strings. Does NOT match
  // `creator_*` (no dot), `creator/foo` (slash), or array-access
  // syntax. Allows `creator.wizard.feature_disabled:` with a colon
  // suffix (used as an internal exception-message demarcation in the
  // service layer) — the colon part is sliced off after the match
  // because the colon is NOT part of the i18n key.
  const pattern = /['"](creator\.[a-z_][a-zA-Z0-9_.]*)(?::[a-zA-Z0-9_]*)?['"]/g
  for (const match of contents.matchAll(pattern)) {
    const literal = match[1] // group 1 is the dotted code without the colon suffix
    if (literal !== undefined && !AUDIT_ACTION_ALLOWLIST.has(literal)) {
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

async function harvestAllCreatorCodes(): Promise<Set<string>> {
  const phpFiles = await walkPhpFiles(CREATORS_ROOT)
  const codes = new Set<string>()
  for (const file of phpFiles) {
    const contents = await fs.readFile(file, 'utf8')
    for (const code of harvestCreatorCodes(contents)) {
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
 * codes must resolve to leaf strings.
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
  const file = path.join(LOCALE_ROOT, locale, 'creator.json')
  const raw = await fs.readFile(file, 'utf8')
  return JSON.parse(raw)
}

describe('i18n creator bundle covers every backend creator.* error code', () => {
  it('harvests at least one code from the backend Creators module (sanity check)', async () => {
    const codes = await harvestAllCreatorCodes()
    // If this fires, the regex-walk broke or the Creators tree moved —
    // do NOT loosen the assertion to silence the test.
    expect(codes.size).toBeGreaterThanOrEqual(4)
    // Spot-check the four error codes Chunk 1 + Chunk 2 ship so a
    // regression that swallows all matches surfaces immediately.
    expect(codes.has('creator.not_found')).toBe(true)
    expect(codes.has('creator.wizard.feature_disabled')).toBe(true)
    expect(codes.has('creator.wizard.feature_enabled')).toBe(true)
    expect(codes.has('creator.wizard.incomplete')).toBe(true)
  })

  it('correctly skips audit-action names via the allowlist', async () => {
    // Defensive: if the allowlist drifts away from what the harvester
    // sees, this test is the canary. Audit-action names ride a
    // separate pipeline and are NOT user-facing i18n keys.
    const codes = await harvestAllCreatorCodes()
    for (const auditAction of [
      'creator.created',
      'creator.wizard.kyc_completed',
      'creator.wizard.contract_completed',
      'creator.wizard.payout_completed',
    ]) {
      expect(codes.has(auditAction)).toBe(false)
    }
  })

  for (const locale of ['en', 'pt', 'it'] as const) {
    it(`every harvested code resolves to a string in ${locale}/creator.json`, async () => {
      const codes = await harvestAllCreatorCodes()
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
            `Locale ${locale} is missing translations for ${missing.length} creator.* code(s):`,
            ...missing.map((code) => `  - ${code}`),
            '',
            `Add each key to apps/main/src/core/i18n/locales/${locale}/creator.json. ` +
              'If the code is an audit-action name rather than a user-facing error code, ' +
              'add it to AUDIT_ACTION_ALLOWLIST in this file instead.',
          ].join('\n'),
        )
      }
    })
  }
})
