import { describe, expect, it } from 'vitest'

import { roundStateColor } from '@/modules/campaigns/draftRounds'

import { roundStateTone } from './draftRoundTone'

describe('roundStateTone (AH-114 L4)', () => {
  it('agrees with the card tint for every review status', () => {
    const reviews = ['pending', 'approved', 'rejected', 'revision_requested'] as const
    for (const review of reviews) {
      const color = roundStateColor(review, null)
      const tone = roundStateTone(review, null)
      const expected = color === 'error' ? 'danger' : color === undefined ? 'neutral' : color
      expect(tone).toBe(expected)
    }
  })

  it('maps the four coloured states and leaves an unqualified pending round neutral', () => {
    expect(roundStateTone('approved', null)).toBe('success')
    expect(roundStateTone('revision_requested', null)).toBe('warning')
    expect(roundStateTone('rejected', null)).toBe('danger')
    expect(roundStateTone('pending', 'draft_submitted')).toBe('info')
    expect(roundStateTone('pending', null)).toBe('neutral')
  })
})
