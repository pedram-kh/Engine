/**
 * PL-3.1 — the pure side of the creators step.
 */
import { describe, expect, it } from 'vitest'

import { makeCandidate, makeCreatorMarket, makeSlot } from './creatorsTestKit'
import {
  isDone,
  personasWithoutCreator,
  roomInMarket,
  roomInWave,
  selectable,
  statusPills,
  statusTone,
  waveCount,
  zeroOrOne,
} from './creatorsLogic'

describe('creatorsLogic (PL-3.1)', () => {
  it('statusTone: shortlisted neutral, contacted info, confirmed success, declined danger', () => {
    expect(statusTone('shortlisted')).toBe('neutral')
    expect(statusTone('contacted')).toBe('info')
    expect(statusTone('confirmed')).toBe('success')
    expect(statusTone('declined')).toBe('danger')
  })

  it('statusPills: in the fixed order, only statuses that have a creator', () => {
    const m = makeCreatorMarket('SK', [
      makeSlot(1, { status: 'declined' }),
      makeSlot(2, { status: 'shortlisted' }),
      makeSlot(3, { status: 'shortlisted' }),
    ])
    expect(statusPills(m)).toEqual([
      { status: 'shortlisted', count: 2 },
      { status: 'declined', count: 1 },
    ])
    expect(statusPills(makeCreatorMarket('RO', []))).toEqual([])
  })

  it('personasWithoutCreator reads the server warning as it is', () => {
    const m = makeCreatorMarket('SK', [])
    m.summary.warnings.personas_without_creator = ['SK-P2']
    expect(personasWithoutCreator(m)).toEqual(['SK-P2'])
  })

  it('selectable: not when already in the wave, not when erased', () => {
    expect(selectable(makeCandidate(1))).toBe(true)
    expect(selectable(makeCandidate(2, { already_in_wave: true }))).toBe(false)
    expect(selectable(makeCandidate(3, { is_erased: true }))).toBe(false)
  })

  it('room: never below zero, per market and per wave; waveCount sums the markets', () => {
    const m = makeCreatorMarket('SK', [makeSlot(1), makeSlot(2)])
    expect(roomInMarket(m, 30)).toBe(28)
    expect(roomInMarket(m, 1)).toBe(0)
    expect(roomInMarket(undefined, 30)).toBe(30)
    expect(roomInWave(99, 100)).toBe(1)
    expect(roomInWave(120, 100)).toBe(0)
    expect(waveCount([m, makeCreatorMarket('RO', [makeSlot(3)])])).toBe(3)
  })

  it('zeroOrOne and isDone', () => {
    expect(zeroOrOne(true)).toEqual([1])
    expect(zeroOrOne(false)).toEqual([])
    expect(isDone('done')).toBe(true)
    expect(isDone('in_progress')).toBe(false)
  })
})
