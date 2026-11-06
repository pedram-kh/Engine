/**
 * AH-119 — the inbox filters as route query. Pins the round trip, that garbage is ignored, that
 * `owner` only ever reads `me` or `all`, that a clean inbox has a clean URL, and that carrying the
 * filters onto a thread link drops unrelated keys such as `name`.
 */

import { describe, expect, it } from 'vitest'

import { NO_FILTERS, filterQueryOf, filtersFromQuery, filtersToQuery } from './inboxFilterQuery'

describe('inboxFilterQuery', () => {
  it('round-trips every filter through the query', () => {
    const filters = {
      brand: ['a', 'b'],
      campaign: ['c'],
      unread: true,
      owner: 'me' as const,
      direct: true,
    }
    const query = filtersToQuery(filters)
    expect(query).toEqual({ brand: 'a,b', campaign: 'c', unread: '1', owner: 'me', direct: '1' })
    expect(filtersFromQuery(query as Record<string, string>)).toEqual(filters)
  })

  it('reads the defaults from an empty query', () => {
    expect(filtersFromQuery({})).toEqual(NO_FILTERS)
  })

  it('writes a clean URL (no keys at all) when no filter is on', () => {
    expect(filtersToQuery(NO_FILTERS)).toEqual({})
  })

  it('writes only the keys that are on', () => {
    expect(filtersToQuery({ ...NO_FILTERS, unread: true })).toEqual({ unread: '1' })
    expect(filtersToQuery({ ...NO_FILTERS, brand: ['a'] })).toEqual({ brand: 'a' })
  })

  it('reads direct only from 1, and writes direct=1 only when on', () => {
    expect(filtersFromQuery({ direct: '1' }).direct).toBe(true)
    expect(filtersFromQuery({ direct: 'yes' }).direct).toBe(false)
    expect(filtersToQuery({ ...NO_FILTERS, direct: true })).toEqual({ direct: '1' })
    expect(filtersToQuery(NO_FILTERS)).not.toHaveProperty('direct')
  })

  it('never writes owner=all', () => {
    expect(filtersToQuery({ ...NO_FILTERS, owner: 'all' })).not.toHaveProperty('owner')
  })

  it('trims ids and drops empty segments of a comma list', () => {
    expect(filtersFromQuery({ brand: ' a , ,b,, ', campaign: ',' })).toMatchObject({
      brand: ['a', 'b'],
      campaign: [],
    })
  })

  it('takes the first value of a repeated key', () => {
    expect(filtersFromQuery({ brand: ['a,b', 'z'] }).brand).toEqual(['a', 'b'])
  })

  it('ignores garbage: unread must be exactly 1, owner must be exactly me', () => {
    expect(filtersFromQuery({ unread: 'true' }).unread).toBe(false)
    expect(filtersFromQuery({ unread: '0' }).unread).toBe(false)
    expect(filtersFromQuery({ unread: null }).unread).toBe(false)
    expect(filtersFromQuery({ owner: 'ME' }).owner).toBe('all')
    expect(filtersFromQuery({ owner: 'someone-else' }).owner).toBe('all')
    expect(filtersFromQuery({ owner: '' }).owner).toBe('all')
  })

  it('accepts owner=me and unread=1', () => {
    expect(filtersFromQuery({ owner: 'me', unread: '1' })).toMatchObject({
      owner: 'me',
      unread: true,
    })
  })

  it('filterQueryOf keeps the filter keys and drops unrelated ones such as name', () => {
    expect(
      filterQueryOf({ brand: 'a', owner: 'me', name: 'Maya', other: 'x', unread: 'nope' }),
    ).toEqual({ brand: 'a', owner: 'me' })
  })

  it('filterQueryOf of a query with only unrelated keys is empty', () => {
    expect(filterQueryOf({ name: 'Maya' })).toEqual({})
  })
})
