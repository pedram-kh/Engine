/**
 * Architecture test — jobs-board LISTING floor parity (backend ↔ frontend),
 * AH-054 D3.
 *
 * The floor is duplicated by design:
 *
 *   - Backend:  ValidatesJobsBoardListing::LISTING_FLOOR_FIELDS — the 422 that
 *               refuses to leave a campaign listed-but-empty.
 *   - Frontend: listingFloor.ts LISTING_FLOOR_FIELDS — what disables the
 *               Settings toggle and names the missing fields inline.
 *
 * The API is the authority; the mirror exists so the 422 is never the user's
 * first notice. A one-sided edit (a seventh floor field added backend-only)
 * would let the SPA offer a toggle the API then rejects, with no on-screen
 * cause — the failure mode this spec exists to catch. The creator
 * floor-mirror-parity spec is the precedent.
 *
 * Source-inspection only: both files are parsed as text.
 */

import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

import { LISTING_FLOOR_FIELDS } from '@/modules/campaigns/listingFloor'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO_ROOT = join(__dirname, '..', '..', '..', '..', '..')

const BACKEND_TRAIT_PATH = join(
  REPO_ROOT,
  'apps',
  'api',
  'app',
  'Modules',
  'Campaigns',
  'Http',
  'Requests',
  'Concerns',
  'ValidatesJobsBoardListing.php',
)

function backendFloorFields(): string[] {
  const php = readFileSync(BACKEND_TRAIT_PATH, 'utf-8')
  const match = /public const array LISTING_FLOOR_FIELDS = \[([\s\S]*?)\];/.exec(php)
  if (match?.[1] === undefined) {
    throw new Error(
      'listing-floor-parity: could not locate LISTING_FLOOR_FIELDS — did the trait shape change?',
    )
  }
  return [...match[1].matchAll(/'([a-z_]+)'/g)].map((m) => m[1] as string)
}

describe('jobs-board listing floor parity (D3)', () => {
  it('names the same fields, in the same order, on both sides', () => {
    expect(backendFloorFields()).toEqual([...LISTING_FLOOR_FIELDS])
  })

  it('pins the floor set itself, so a silent two-sided edit still surfaces here', () => {
    expect([...LISTING_FLOOR_FIELDS]).toEqual([
      'description',
      'listing_duration',
      'listing_fee',
      'listing_languages',
      'listing_regions',
    ])
  })
})
