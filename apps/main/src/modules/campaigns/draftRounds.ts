/**
 * The draft-round vocabulary (AH-068, D1/D2) — one mapping from a round's stored
 * state to the one string that names it.
 *
 * A round IS a `campaign_drafts` row: `version` is the round number and the row's
 * review columns are that round's closing feedback (D1 — no counter, no new
 * storage). This module owns the only translation of that state into copy, so the
 * five surfaces that name a round (creator detail history, the agency Drafts tab,
 * the review drawer's history, the review drawer's heading, the board card
 * drawer's latest-draft chip) cannot drift into saying different things about the
 * same row.
 *
 * The rendered form is ONE i18n message per state ("Draft 2 — changes requested"),
 * never a label concatenated with a status chip: a locale that orders the clause
 * differently — or joins it with something other than an em dash — has to be able
 * to say so, and a concatenation in the template takes that away. It would also
 * put the composite beyond the reach of the placeholder-parity gate.
 *
 * `pending` is the one state the review status cannot name alone. A round awaits
 * review only while the assignment actually sits at `draft_submitted`; a pending
 * round on an assignment that has moved elsewhere is submitted but no longer under
 * anyone's eye, so it gets the neutral form rather than a claim that someone is
 * looking at it.
 */

import type { AssignmentStatus, DraftReviewStatus } from '@catalyst/api-client'

/** The `app.campaigns.review.roundState.*` leaves, in en-source order. */
export const ROUND_STATES = [
  'awaitingReview',
  'changesRequested',
  'approved',
  'notAccepted',
  'submitted',
] as const

export type RoundState = (typeof ROUND_STATES)[number]

/** The i18n block the five composite messages live under. */
const ROUND_STATE_PREFIX = 'app.campaigns.review.roundState'

/**
 * Which round state a stored draft row is in.
 *
 * `assignmentStatus` is optional because not every surface has it (a Drafts-tab
 * row's assignment is nullable on the wire). Absent, a `pending` round resolves to
 * the neutral `submitted` — the honest reading, since "awaiting review" is a claim
 * about the assignment, not about the draft.
 */
export function roundState(
  reviewStatus: DraftReviewStatus,
  assignmentStatus?: AssignmentStatus | null,
): RoundState {
  switch (reviewStatus) {
    case 'approved':
      return 'approved'
    case 'rejected':
      return 'notAccepted'
    case 'revision_requested':
      return 'changesRequested'
    case 'pending':
      return assignmentStatus === 'draft_submitted' ? 'awaitingReview' : 'submitted'
  }
}

/**
 * The full i18n key for a round's one-line name. Interpolate with `{ n: version }`.
 * The path is built here and nowhere else, so the five surfaces share one block.
 */
export function roundStateKey(
  reviewStatus: DraftReviewStatus,
  assignmentStatus?: AssignmentStatus | null,
): string {
  return `${ROUND_STATE_PREFIX}.${roundState(reviewStatus, assignmentStatus)}`
}
