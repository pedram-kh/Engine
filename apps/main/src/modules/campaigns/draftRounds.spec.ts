/**
 * AH-068 (D2) — the round-state mapping.
 *
 * Pins the four review statuses onto their one composite message, the `pending`
 * split that needs the assignment to disambiguate it, and the constant-parity
 * claim that every state this module can return has a message in the en source.
 */

import enApp from '@/core/i18n/locales/en/app.json'
import { describe, expect, it } from 'vitest'

import {
  ROUND_STATES,
  roundCardTextStyle,
  roundState,
  roundStateColor,
  roundStateKey,
} from './draftRounds'

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

// Eyes-on fix batch, 2026-08-17 — the round chip used to be `color="primary"`
// on every surface no matter the outcome, so an approved round and a rejected
// one looked identical. This mapping reuses the app's existing semantic
// palette (the same success/warning/error the verified/revision banners
// already use) so a round's color can never disagree with its own words.
describe('roundStateColor', () => {
  it('reuses the app-wide success/warning/error/info vocabulary, one per state', () => {
    expect(roundStateColor('approved')).toBe('success')
    expect(roundStateColor('revision_requested')).toBe('warning')
    expect(roundStateColor('rejected')).toBe('error')
    expect(roundStateColor('pending', 'draft_submitted')).toBe('info')
  })

  it('a pending round on an assignment that moved on gets no color, matching its neutral wording', () => {
    expect(roundStateColor('pending', 'cancelled')).toBeUndefined()
    expect(roundStateColor('pending')).toBeUndefined()
  })

  // Drives the function through every reachable (reviewStatus, assignmentStatus)
  // combination `roundState()` itself is pinned against, and checks the color
  // it returns names the SAME state `roundStateKey()` would render in words —
  // two helpers reading one switch, never two sources of truth.
  it('never disagrees with roundStateKey about which state a round is in', () => {
    const cases: Array<[Parameters<typeof roundState>[0], Parameters<typeof roundState>[1]?]> = [
      ['approved'],
      ['rejected'],
      ['revision_requested'],
      ['pending', 'draft_submitted'],
      ['pending', 'cancelled'],
      ['pending', undefined],
    ]
    const wordToColor: Record<string, string | undefined> = {
      approved: 'success',
      notAccepted: 'error',
      changesRequested: 'warning',
      awaitingReview: 'info',
      submitted: undefined,
    }

    for (const [reviewStatus, assignmentStatus] of cases) {
      const state = roundState(reviewStatus, assignmentStatus)
      expect(roundStateColor(reviewStatus, assignmentStatus)).toBe(wordToColor[state])
    }
  })
})

// Eyes-on fix batch, 2026-08-17 — `text-medium-emphasis` ignores a tonal
// card's own color and forces a pale white-on-amber tone for text sitting on
// a `changesRequested` (warning) round; every surface that draws a round card
// (the review drawer's history, the board card drawer's Drafts tab) routes
// its on-card text through this helper instead so none of them can
// reintroduce that bug independently.
describe('roundCardTextStyle', () => {
  it("a changes-requested round's text uses the warning card's own on-color, not white", () => {
    expect(roundCardTextStyle('revision_requested')).toEqual({
      color: 'rgb(var(--v-theme-on-warning))',
    })
  })

  it('every colored state gets its matching on-color token', () => {
    expect(roundCardTextStyle('approved')).toEqual({ color: 'rgb(var(--v-theme-on-success))' })
    expect(roundCardTextStyle('rejected')).toEqual({ color: 'rgb(var(--v-theme-on-error))' })
    expect(roundCardTextStyle('pending', 'draft_submitted')).toEqual({
      color: 'rgb(var(--v-theme-on-info))',
    })
  })

  it('the neutral (colorless) submitted state gets no style override', () => {
    expect(roundCardTextStyle('pending', 'cancelled')).toBeUndefined()
    expect(roundCardTextStyle('pending')).toBeUndefined()
  })
})
