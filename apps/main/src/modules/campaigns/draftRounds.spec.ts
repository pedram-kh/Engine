/**
 * AH-068 (D2) — the round-state mapping.
 *
 * Pins the four review statuses onto their one composite message, the `pending`
 * split that needs the assignment to disambiguate it, and the constant-parity
 * claim that every state this module can return has a message in the en source.
 */

import enApp from '@/core/i18n/locales/en/app.json'
import { describe, expect, it } from 'vitest'

import { ROUND_STATES, roundState, roundStateKey } from './draftRounds'

describe('roundState (AH-068, D2)', () => {
  it('names the three closed states from the review status alone', () => {
    expect(roundState('approved')).toBe('approved')
    expect(roundState('rejected')).toBe('notAccepted')
    expect(roundState('revision_requested')).toBe('changesRequested')
  })

  it('closed states ignore the assignment status entirely', () => {
    // A round that closed stays closed no matter where the assignment went.
    expect(roundState('approved', 'draft_submitted')).toBe('approved')
    expect(roundState('revision_requested', 'cancelled')).toBe('changesRequested')
    expect(roundState('rejected', 'posted')).toBe('notAccepted')
  })

  it('a pending round awaits review only while the assignment sits at draft_submitted', () => {
    expect(roundState('pending', 'draft_submitted')).toBe('awaitingReview')
  })

  // ── negative cases (§5.34): pending is NOT "awaiting review" everywhere ─────
  it('a pending round on an assignment that moved on is submitted, not awaiting review', () => {
    expect(roundState('pending', 'cancelled')).toBe('submitted')
    expect(roundState('pending', 'producing')).toBe('submitted')
    expect(roundState('pending', 'approved')).toBe('submitted')
  })

  it('a pending round with no assignment on the wire does not claim anyone is reviewing', () => {
    expect(roundState('pending')).toBe('submitted')
    expect(roundState('pending', null)).toBe('submitted')
  })
})

describe('roundStateKey (AH-068, D2)', () => {
  it('builds the shared app-namespace path, not a per-side one', () => {
    expect(roundStateKey('approved')).toBe('app.campaigns.review.roundState.approved')
    expect(roundStateKey('pending', 'draft_submitted')).toBe(
      'app.campaigns.review.roundState.awaitingReview',
    )
  })

  // §5.25: a state the mapping can return but the locale files don't carry would
  // render as a raw key path on a real surface.
  it('every round state has an en message carrying the {n} placeholder', () => {
    const messages = enApp.app.campaigns.review.roundState as Record<string, string>

    for (const state of ROUND_STATES) {
      expect(messages[state], `roundState.${state} missing from en/app.json`).toBeTypeOf('string')
      expect(messages[state], `roundState.${state} must interpolate the round number`).toContain(
        '{n}',
      )
    }

    // …and no message in the block is unreachable from the mapping.
    expect(Object.keys(messages).sort()).toEqual([...ROUND_STATES].sort())
  })
})
