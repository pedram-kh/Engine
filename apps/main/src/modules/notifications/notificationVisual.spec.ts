import { describe, expect, it } from 'vitest'

import { compactAge, notificationVisual } from './notificationVisual'

describe('notificationVisual (AH-100d, frame 09)', () => {
  it('maps the three frame types: offer -> gift/info, connection -> link/accent, approved -> check/success', () => {
    expect(notificationVisual('assignment.invited')).toEqual({
      icon: 'mdi-gift-outline',
      tone: 'info',
    })
    expect(notificationVisual('agency_creator_relation.admin_connected')).toEqual({
      icon: 'mdi-link-variant',
      tone: 'accent',
    })
    expect(notificationVisual('creator.approved')).toEqual({
      icon: 'mdi-check-circle-outline',
      tone: 'success',
    })
  })

  it('gives a type the wire sends but the app does not know a neutral bell', () => {
    expect(notificationVisual('something.new')).toEqual({
      icon: 'mdi-bell-outline',
      tone: 'neutral',
    })
  })
})

describe('compactAge', () => {
  const NOW = Date.parse('2026-10-09T12:00:00Z')
  const ago = (ms: number): string => new Date(NOW - ms).toISOString()

  it('reads as the frame does: minutes, hours, days', () => {
    expect(compactAge(ago(5 * 60_000), 'en', NOW)).toBe('5m')
    expect(compactAge(ago(2 * 3_600_000), 'en', NOW)).toBe('2h')
    expect(compactAge(ago(26 * 3_600_000), 'en', NOW)).toBe('1d')
    expect(compactAge(ago(2 * 86_400_000), 'en', NOW)).toBe('2d')
  })

  it('never says 0 minutes', () => {
    expect(compactAge(ago(5_000), 'en', NOW)).toBe('1m')
  })

  it('falls back to a short date once a week old', () => {
    expect(compactAge(ago(10 * 86_400_000), 'en', NOW)).toMatch(/Sep/)
  })

  it('localises the unit rather than hard-coding English', () => {
    expect(compactAge(ago(2 * 86_400_000), 'fi', NOW)).toBe('2pv')
    expect(compactAge(ago(2 * 86_400_000), 'de', NOW)).toMatch(/^2\s?T$/u)
  })

  it('is empty for an unparseable timestamp', () => {
    expect(compactAge('not a date', 'en', NOW)).toBe('')
  })
})
