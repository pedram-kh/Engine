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
 * Which floor fields the given state would still be missing.
 *
 * Two callers, two containers, ONE predicate (AH-059, D3):
 *
 *   - the Settings tab passes its live edit form — the merged state the PATCH
 *     will produce, since the form is seeded from the stored campaign;
 *   - the campaigns list passes a row's stored `attributes` directly, because the
 *     list payload carries every floor field.
 *
 * The parameter is typed as the five fields it actually reads rather than as
 * either container, so both fit without a cast and neither surface can drift into
 * evaluating a looser predicate than the other.
 */
export function missingListingFloorFields(
  source: Readonly<Partial<Record<ListingFloorField, unknown>>>,
): ListingFloorField[] {
  return LISTING_FLOOR_FIELDS.filter((field) => !isFilled(source[field]))
}
