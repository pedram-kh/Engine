/**
 * AH-104b-del — every `account_deletion.*` code the API can answer, and every blocker
 * reason it can name, resolves to a string in all 24 locales.
 *
 * `POST /me/account-deletion` answers `account_deletion.blocked` (with `meta.reasons`, a
 * subset of the `ErasureEligibility` reason constants) and `account_deletion.not_available`.
 * The SPA renders them through `useErrorMessage` (the `account_deletion.` prefix) and the
 * blocker list through `account_deletion.blockers.<reason>`. A code or reason added on the
 * backend without copy fails here, before merge.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

import { UI_LOCALES } from '@catalyst/api-client'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')
const CONTROLLER = path.join(
  REPO_ROOT,
  'apps/api/app/Modules/Identity/Http/Controllers/AccountDeletionController.php',
)
const ELIGIBILITY = path.join(
  REPO_ROOT,
  'apps/api/app/Modules/Erasure/Eligibility/ErasureEligibility.php',
)
const LOCALE_ROOT = path.resolve(__dirname, '../../../src/core/i18n/locales')

async function harvestCodes(): Promise<string[]> {
  const source = await fs.readFile(CONTROLLER, 'utf8')
  const codes = new Set<string>()
  for (const match of source.matchAll(/['"](account_deletion\.[a-z_.]+)['"]/g)) {
    codes.add(match[1] as string)
  }
  return [...codes].sort()
}

async function harvestReasons(): Promise<string[]> {
  const source = await fs.readFile(ELIGIBILITY, 'utf8')
  const reasons: string[] = []
  for (const match of source.matchAll(/public const [A-Z_]+ = '([a-z_]+)';/g)) {
    reasons.push(match[1] as string)
  }
  return reasons.sort()
}

function resolve(messages: unknown, dotted: string): string | null {
  let cursor: unknown = messages
  for (const segment of dotted.split('.')) {
    if (typeof cursor !== 'object' || cursor === null) {
      return null
    }
    cursor = (cursor as Record<string, unknown>)[segment]
  }
  return typeof cursor === 'string' ? cursor : null
}

describe('account_deletion.* copy', () => {
  it('finds the codes and the reasons (non-vacuity)', async () => {
    expect(await harvestCodes()).toEqual([
      'account_deletion.blocked',
      'account_deletion.not_available',
    ])
    expect(await harvestReasons()).toEqual(['payment', 'vendor'])
  })

  it.each([...UI_LOCALES])(
    '%s: every code, reason and the generic fallback has copy',
    async (locale) => {
      const bundle = JSON.parse(
        await fs.readFile(path.join(LOCALE_ROOT, locale, 'creator.json'), 'utf8'),
      )
      const keys = [
        ...(await harvestCodes()),
        ...(await harvestReasons()).map((reason) => `account_deletion.blockers.${reason}`),
        'account_deletion.blockers.other',
        'account_deletion.blockers.contact',
        'creator.unavailable',
      ]
      const missing = keys.filter((key) => (resolve(bundle, key) ?? '') === '')
      expect(missing, `${locale}/creator.json is missing copy`).toEqual([])
    },
  )
})
