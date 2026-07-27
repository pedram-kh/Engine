/**
 * The jobs-board listing floor (AH-054, D3) — the SPA mirror of the backend
 * predicate in
 * `apps/api/app/Modules/Campaigns/Http/Requests/Concerns/ValidatesJobsBoardListing.php`.
 *
 * The API is the authority: switching `listed_on_jobs_board` on with any of
 * these fields empty is a 422 naming each one. This mirror exists so that 422
 * is a backstop rather than the user's first notice — the Settings toggle is
 * disabled, with the missing fields named inline, until the floor holds.
 *
 * The two field lists are pinned against each other by
 * `apps/main/tests/unit/architecture/listing-floor-parity.spec.ts`, which
 * source-scans the PHP trait. Adding a field on one side alone reds that spec.
 */

import type { CreateCampaignPayload } from '@catalyst/api-client'

/** Must match `ValidatesJobsBoardListing::LISTING_FLOOR_FIELDS`, in order. */
export const LISTING_FLOOR_FIELDS = [
  'description',
  'listing_duration',
  'listing_fee',
  'listing_languages',
  'listing_regions',
] as const

export type ListingFloorField = (typeof LISTING_FLOOR_FIELDS)[number]

/**
 * "Filled" agrees with the backend's `listingValueFilled()`: a string counts
 * only when it is non-empty after trimming, an array only when it has at least
 * one entry.
 */
function isFilled(value: unknown): boolean {
  if (Array.isArray(value)) return value.length > 0
  return typeof value === 'string' && value.trim() !== ''
}

/**
 * Which floor fields the given form state would still be missing. Evaluated
 * against the live form — the same merged state the PATCH will produce, since
 * the Settings form is seeded from the stored campaign.
 */
export function missingListingFloorFields(
  payload: Partial<CreateCampaignPayload>,
): ListingFloorField[] {
  return LISTING_FLOOR_FIELDS.filter(
    (field) => !isFilled((payload as Record<string, unknown>)[field]),
  )
}
