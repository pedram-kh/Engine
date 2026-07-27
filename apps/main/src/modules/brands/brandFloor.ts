/**
 * The brand completeness floor (AH-053, D6) — the SPA mirror of
 * `Brand::FLOOR_FIELDS` / `Brand::floorMissingFields()` in
 * `apps/api/app/Modules/Brands/Models/Brand.php`.
 *
 * The API is the authority: it refuses any write that would leave a brand
 * incomplete. This mirror exists so users see inline requirements instead of a
 * 422 — including on the brands that PREDATE the floor, which hard-block on
 * their next edit and would otherwise give no on-screen reason.
 *
 * Pinned against the backend list by
 * `apps/main/tests/unit/architecture/brand-floor-parity.spec.ts`.
 */

import type { CreateBrandPayload } from '@catalyst/api-client'

/** Must match `Brand::FLOOR_FIELDS`, in order. */
export const BRAND_FLOOR_FIELDS = [
  'name',
  'slug',
  'description',
  'industry',
  'website_url',
  'logo_path',
] as const

export type BrandFloorField = (typeof BRAND_FLOOR_FIELDS)[number]

/** Agrees with `Brand::isFilled()` — whitespace does not satisfy the floor. */
function isFilled(value: unknown): boolean {
  return typeof value === 'string' && value.trim() !== ''
}

/**
 * Which floor fields are still missing, given the form state and whether the
 * brand currently has a logo.
 *
 * `logo_path` is not a form field — the logo is uploaded through its own
 * endpoint — so it is passed separately rather than read from the payload.
 */
export function brandFloorMissingFields(
  payload: Partial<CreateBrandPayload>,
  hasLogo: boolean,
): BrandFloorField[] {
  return BRAND_FLOOR_FIELDS.filter((field) => {
    if (field === 'logo_path') return !hasLogo
    return !isFilled((payload as Record<string, unknown>)[field])
  })
}
