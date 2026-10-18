import { describe, expect, it } from 'vitest'

import { BADGE_COUNT_CAP, formatBadgeCount } from './formatBadgeCount'

describe('formatBadgeCount (AH-108 Q8)', () => {
  it('is empty at zero, so a badge bound to it has nothing to draw', () => {
    expect(formatBadgeCount(0)).toBe('')
  })

  it('draws the number itself from 1 up to the cap', () => {
    expect(formatBadgeCount(1)).toBe('1')
    expect(formatBadgeCount(12)).toBe('12')
    expect(formatBadgeCount(99)).toBe('99')
  })

  it('caps at 99+ from 100 up (the boundary is exactly 99 / 100)', () => {
    expect(BADGE_COUNT_CAP).toBe(99)
    expect(formatBadgeCount(100)).toBe('99+')
    expect(formatBadgeCount(5000)).toBe('99+')
  })

  it('never paints a nonsense value on a tab', () => {
    expect(formatBadgeCount(-3)).toBe('')
    expect(formatBadgeCount(Number.NaN)).toBe('')
    expect(formatBadgeCount(Number.POSITIVE_INFINITY)).toBe('')
  })
})
