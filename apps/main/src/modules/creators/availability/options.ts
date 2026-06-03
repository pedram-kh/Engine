/**
 * Static option sets for the availability dialog selects (D-b9).
 *
 * `KIND_VALUES` is the creator-settable kinds ONLY — `assignment_auto` is
 * system-reserved (Sprint 8 auto-block flow) and must NEVER be offered in
 * the create/edit dialog. The `CreatorSettableKind` type makes including
 * it a compile error; this array is the runtime list the dialog renders,
 * and the unit test asserts it omits `assignment_auto` (spot-check anchor).
 *
 * The API sends raw enum values with no localized labels (Divergence #4),
 * so the dialog maps each value through the `availability.*` i18n bundle.
 */

import type { AvailabilityBlockType, CreatorSettableKind } from '@catalyst/api-client'

export const BLOCK_TYPE_VALUES: readonly AvailabilityBlockType[] = ['hard', 'soft']

export const KIND_VALUES: readonly CreatorSettableKind[] = [
  'vacation',
  'personal',
  'exclusive_contract',
  'other',
]
