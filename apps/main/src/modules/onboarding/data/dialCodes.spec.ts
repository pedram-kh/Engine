/**
 * AH-122 — DIAL_CODE_OPTIONS is keyed by ISO code, so each ISO code must appear once (the launch
 * list and the world list both carry US and CA), and a stored dial code resolves predictably.
 *
 * Break-revert: add a duplicate entry (or restore the launch-list US/CA overlap) → the first test
 * fails; make `isoForDialCode` ignore the creator's country → the second fails.
 */

import { describe, expect, it } from 'vitest'

import { DIAL_CODE_OPTIONS, isoForDialCode } from './dialCodes'

describe('DIAL_CODE_OPTIONS', () => {
  it('has no duplicate ISO codes', () => {
    const seen = new Map<string, number>()
    for (const o of DIAL_CODE_OPTIONS) seen.set(o.code, (seen.get(o.code) ?? 0) + 1)
    const dupes = [...seen].filter(([, n]) => n > 1).map(([c]) => c)
    expect(dupes).toEqual([])
  })
})

describe('isoForDialCode', () => {
  it("prefers the creator's country when its dial code matches", () => {
    expect(isoForDialCode('+1', 'CA')).toBe('CA')
    expect(isoForDialCode('+1', 'ca')).toBe('CA')
  })

  it('otherwise takes the first registry entry with that dial code', () => {
    expect(isoForDialCode('+1', 'ES')).toBe('US')
    expect(isoForDialCode('+1', null)).toBe('US')
  })

  it('returns "" for an empty or unknown dial code', () => {
    expect(isoForDialCode('', 'CA')).toBe('')
    expect(isoForDialCode('+999', 'CA')).toBe('')
  })
})
