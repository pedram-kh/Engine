/** PL-1.3 W2 (part) — the Due column words, grouping, the Mine filter and the completed-line summary. */
import { describe, expect, it } from 'vitest'

import { completedSummary, formatDue, groupSteps } from './trackerFormat'
import { makeStep, TODAY } from './waveTestKit'

const words = { today: 'Today', tomorrow: 'Tomorrow' }
const due = (starts: string | null, ends: string | null): string | null =>
  formatDue(starts, ends, TODAY, 'en', words)

describe('formatDue', () => {
  it('writes Today and Tomorrow as words', () => {
    expect(due(null, '2026-11-18')).toBe('Today')
    expect(due(null, '2026-11-19')).toBe('Tomorrow')
  })

  it('adds the weekday inside the coming week and a plain date after it', () => {
    expect(due(null, '2026-11-23')).toBe('Mon, Nov 23')
    expect(due(null, '2026-11-30')).toBe('Nov 30')
    expect(due(null, '2026-11-10')).toBe('Nov 10')
  })

  it('adds the year when the date is not in the current year', () => {
    expect(due(null, '2027-01-09')).toContain('2027')
  })

  it('writes a range when both dates exist, the day only inside one month', () => {
    expect(due('2026-11-20', '2026-11-25')).toBe('Nov 20 → 25')
    expect(due('2026-11-28', '2026-12-03')).toBe('Nov 28 → Dec 3')
  })

  it('is empty without any date', () => {
    expect(due(null, null)).toBeNull()
  })
})

describe('groupSteps', () => {
  const me = '01MEMEMEMEMEMEMEMEMEMEMEME'
  const steps = [
    makeStep({ stream: 'ugc_video', owner: { id: me, name: 'Ada Admin' } }, 'a'),
    makeStep({ stream: 'ugc_video', status: 'done', completed_on: '2026-11-02' }, 'b'),
    makeStep({ stream: 'statics', owner: { id: 'other', name: 'Sam Staff' } }, 'c'),
    makeStep({ stream: 'stills' }, 'd'),
  ]

  it('groups by stream in the fixed order and splits open from completed', () => {
    const groups = groupSteps(steps, 'all', me)
    expect(groups.map((g) => g.stream)).toEqual(['ugc_video', 'statics', 'stills'])
    expect(groups[0]?.open.map((s) => s.id)).toEqual(['a'])
    expect(groups[0]?.completed.map((s) => s.id)).toEqual(['b'])
  })

  it('a stream filter keeps one group', () => {
    expect(groupSteps(steps, 'statics', me).map((g) => g.stream)).toEqual(['statics'])
  })

  it('Mine keeps only steps owned by the signed-in member and drops empty groups', () => {
    const groups = groupSteps(steps, 'mine', me)
    expect(groups.map((g) => g.stream)).toEqual(['ugc_video'])
    expect(groups[0]?.open.map((s) => s.id)).toEqual(['a'])
    expect(groups[0]?.completed).toEqual([])
  })

  it('Mine matches nothing without a signed-in member', () => {
    expect(groupSteps(steps, 'mine', null)).toEqual([])
  })
})

describe('completedSummary', () => {
  it('names the first and last step and spans the completed dates', () => {
    const done = [
      makeStep({ name: 'Kick-off call', status: 'done', completed_on: '2026-09-25' }),
      makeStep({ name: 'Briefing', status: 'done', completed_on: '2026-10-12' }),
      makeStep({ name: 'Editing', status: 'done', completed_on: '2026-10-03' }),
    ]
    expect(completedSummary(done)).toEqual({
      first: 'Kick-off call',
      last: 'Editing',
      from: '2026-09-25',
      to: '2026-10-12',
    })
  })
})

describe('groupSteps (PL-2.5)', () => {
  it('keeps a Done row that was set by hand beyond its strategy step in the open list, not in the folded completed line', () => {
    const hand = makeStep({
      status: 'done',
      strategy: { state: 'in_progress', markets_done: 1, markets_total: 2, hand_set_beyond: true },
    })
    const real = makeStep({
      status: 'done',
      strategy: { state: 'done', markets_done: 2, markets_total: 2, hand_set_beyond: false },
    })
    const [g] = groupSteps([hand, real], 'all', null)
    expect(g?.open).toEqual([hand])
    expect(g?.completed).toEqual([real])
  })
})
