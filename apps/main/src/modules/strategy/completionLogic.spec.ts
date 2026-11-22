import { describe, expect, it } from 'vitest'

import {
  dataCount,
  isMarked,
  landingRoute,
  liveWarnings,
  markInfo,
  marketsFigures,
  stateFor,
} from './completionLogic'
import { makeSummary, mkt } from './strategyTestKit'

function withSteps(over: Record<string, unknown> = {}) {
  const s = makeSummary({
    markets: [mkt('SK', 3), mkt('RO', 1)],
    matrix: {
      cells_set: 3,
      cells_total: 8,
      by_market: [
        { market: 'SK', set: 3, total: 6 },
        { market: 'RO', set: 0, total: 0 },
      ],
    },
    steps: {
      usps: {
        state: 'in_progress',
        count: 3,
        marked: false,
        warnings: [],
        completed_by: null,
        completed_at: null,
      },
      personas: {
        state: 'in_progress',
        markets_done: 1,
        markets_total: 2,
        markets: [
          {
            market: 'SK',
            code: 'SK',
            state: 'done',
            count: 3,
            marked: true,
            warnings: [],
            completed_by: { name: 'Eva', former: false },
            completed_at: '2026-11-20T10:00:00+00:00',
          },
          {
            market: 'RO',
            code: 'RO',
            state: 'in_progress',
            count: 1,
            marked: false,
            warnings: [],
            completed_by: null,
            completed_at: null,
          },
        ],
      },
      matrix: {
        state: 'in_progress',
        markets_done: 0,
        markets_total: 2,
        markets: [
          {
            market: 'SK',
            code: 'SK',
            state: 'in_progress',
            count: 3,
            marked: false,
            warnings: [],
            completed_by: null,
            completed_at: null,
          },
          {
            market: 'RO',
            code: 'RO',
            state: 'not_started',
            count: 0,
            marked: false,
            warnings: [],
            completed_by: null,
            completed_at: null,
          },
        ],
      },
      concepts: { state: 'not_started', markets_done: 0, markets_total: 2, markets: [] },
    },
    ...over,
  } as never)
  return s.attributes
}

describe('completion logic (PL-2.5)', () => {
  it('reads the state of the wave and of a market', () => {
    const a = withSteps()
    expect(stateFor(a, 'usps', null)).toBe('in_progress')
    expect(stateFor(a, 'personas', 'SK')).toBe('done')
    expect(stateFor(a, 'personas', 'RO')).toBe('in_progress')
    expect(stateFor(a, 'matrix', 'RO')).toBe('not_started')
    expect(marketsFigures(a, 'personas')).toEqual({ done: 1, total: 2 })
    expect(marketsFigures(a, 'usps')).toBeNull()
    expect(dataCount(a, 'personas', 'SK')).toBe(3)
  })

  it('warns under four USPs, under two personas and for cells not set; an empty step is no warning', () => {
    const a = withSteps()
    expect(liveWarnings(a, 'usps', null)[0]).toMatchObject({
      code: 'usps_under_recommended',
      params: { count: 3, recommended: 4 },
    })
    expect(liveWarnings(a, 'personas', 'RO')[0]).toMatchObject({
      code: 'personas_under_minimum',
      params: { count: 1 },
    })
    expect(liveWarnings(a, 'personas', 'SK')).toEqual([])
    expect(liveWarnings(a, 'matrix', 'SK')[0]).toMatchObject({
      code: 'cells_not_set',
      params: { set: 3, total: 6, missing: 3 },
    })
    expect(liveWarnings(a, 'matrix', 'RO')).toEqual([])
  })

  it('reads who marked and when, with a former member', () => {
    const a = withSteps()
    expect(isMarked(a, 'personas', 'SK')).toBe(true)
    expect(markInfo(a, 'personas', 'SK')).toMatchObject({ name: 'Eva', former: false })
    expect(markInfo(a, 'personas', 'RO')).toBeNull()
  })

  it('turns the landing into a route: the market is a query only for a per-market step', () => {
    expect(landingRoute({ step: 'usps', market: null, market_id: null })).toEqual({
      name: 'strategy.usps',
      query: {},
    })
    expect(landingRoute({ step: 'personas', market: 'RO', market_id: '01RO' })).toEqual({
      name: 'strategy.personas',
      query: { market: '01RO' },
    })
  })
})
