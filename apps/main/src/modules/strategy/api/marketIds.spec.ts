/** PL-2.4 — the bridge between the short code the screens use and the market ULID the API uses. */
import { beforeEach, describe, expect, it } from 'vitest'

import { fromWire, registerMarkets, toWire, urlToWire } from './marketIds'

const WAVE = '01JWAVE00000000000000000A'
const SK = '01JMARKETSK00000000000000'
const RO = '01JMARKETRO00000000000000'

describe('marketIds', () => {
  beforeEach(() => {
    registerMarkets(WAVE, [
      { market: SK, code: 'SK' },
      { market: RO, code: 'RO' },
    ])
  })

  it('turns a short code into the ULID in a request body, at any depth, and leaves other fields alone', () => {
    expect(
      toWire(WAVE, {
        market: 'SK',
        from_market: 'RO',
        to_market: 'SK',
        title: 'SK',
        cells: [{ persona: 'p1', market: 'RO' }],
      }),
    ).toEqual({
      market: SK,
      from_market: RO,
      to_market: SK,
      title: 'SK',
      cells: [{ persona: 'p1', market: RO }],
    })
  })

  it('turns the ULID back into the short code in a response', () => {
    expect(
      fromWire(WAVE, {
        data: [{ attributes: { market: SK, code: 'SK1' } }],
        meta: { by_market: [{ market: RO, set: 1 }], market: null },
      }),
    ).toEqual({
      data: [{ attributes: { market: 'SK', code: 'SK1' } }],
      meta: { by_market: [{ market: 'RO', set: 1 }], market: null },
    })
  })

  it('rewrites ?market= in a URL and leaves the rest of the query', () => {
    expect(urlToWire(WAVE, '/x/matrix?market=SK&b=1')).toBe(`/x/matrix?market=${SK}&b=1`)
    expect(urlToWire(WAVE, '/x/personas')).toBe('/x/personas')
  })

  it('learns the markets from a summary response, so the next call is bridged', () => {
    const other = '01JWAVE00000000000000000B'
    const summary = {
      data: {
        attributes: {
          markets: [{ market: '01JNEWULID00000000000000CZ', code: 'CZ', persona_count: 0 }],
        },
      },
    }
    expect(fromWire(other, summary).data.attributes.markets[0]?.market).toBe('CZ')
    expect(toWire(other, { market: 'CZ' })).toEqual({ market: '01JNEWULID00000000000000CZ' })
  })

  it('does nothing for a wave it has not learned, and leaves an unknown code as it is', () => {
    expect(toWire('01JUNKNOWN', { market: 'SK' })).toEqual({ market: 'SK' })
    expect(toWire(WAVE, { market: 'ZZ' })).toEqual({ market: 'ZZ' })
    expect(fromWire(WAVE, { market: '01JOTHER' })).toEqual({ market: '01JOTHER' })
  })
})
