/**
 * Architecture test — brand FLOOR mirror parity (backend ↔ frontend),
 * AH-053 D6.
 *
 * The floor is duplicated by design:
 *
 *   - Backend:  `Brand::FLOOR_FIELDS` / `Brand::floorMissingFields()` — the
 *               refusal that keeps a brand from being left incomplete, applied
 *               to create AND to every subsequent edit.
 *   - Frontend: `brandFloor.ts` `BRAND_FLOOR_FIELDS` — what holds the submit
 *               button and names the missing fields inline.
 *
 * A one-sided edit is the failure this catches. Add a seventh field
 * backend-only and the SPA offers a save the API refuses, with no on-screen
 * reason — worst for exactly the brands the floor targets, the pre-floor ones
 * that hard-block on their next edit. The creator floor-mirror-parity spec is
 * the precedent.
 *
 * Source-inspection only: the PHP file is parsed as text.
 */

import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

import { BRAND_FLOOR_FIELDS } from '@/modules/brands/brandFloor'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO_ROOT = join(__dirname, '..', '..', '..', '..', '..')

const BRAND_MODEL_PATH = join(
  REPO_ROOT,
  'apps',
  'api',
  'app',
  'Modules',
  'Brands',
  'Models',
  'Brand.php',
)

function backendFloorFields(): string[] {
  const php = readFileSync(BRAND_MODEL_PATH, 'utf-8')
  const match = /public const array FLOOR_FIELDS = \[([\s\S]*?)\];/.exec(php)
  if (match?.[1] === undefined) {
    throw new Error(
      'brand-floor-parity: could not locate FLOOR_FIELDS — did the model shape change?',
    )
  }
  return [...match[1].matchAll(/'([a-z_]+)'/g)].map((m) => m[1] as string)
}

describe('brand floor mirror parity (D6)', () => {
  it('names the same fields, in the same order, on both sides', () => {
    expect(backendFloorFields()).toEqual([...BRAND_FLOOR_FIELDS])
  })

  it('pins the floor set itself, so a silent two-sided edit still surfaces here', () => {
    expect([...BRAND_FLOOR_FIELDS]).toEqual([
      'name',
      'slug',
      'description',
      'industry',
      'website_url',
      'logo_path',
    ])
  })

  it('every floor field has a user-facing label, so the inline hint never shows a raw key', async () => {
    const enApp = (await import('@/core/i18n/locales/en/app.json')).default as unknown as {
      app: { brands: { floor: { fields: Record<string, string> } } }
    }
    const labels = enApp.app.brands.floor.fields

    for (const field of BRAND_FLOOR_FIELDS) {
      expect(labels[field], `missing label for ${field}`).toBeTruthy()
    }
  })
})
