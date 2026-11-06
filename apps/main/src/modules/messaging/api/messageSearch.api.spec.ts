/**
 * AH-119 Stage B — `messageSearchApi`: the URL and query string the search sends.
 *
 * Break-revert: stop encoding `q` → the encoding case fails; drop the 200-char cap → the cap case
 * fails; send an empty `cursor=` → the omits-empties case fails; call the wrong path → the path
 * case fails. The HTTP singleton is mocked so no transport runs.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/core/api', () => ({ http: { get: vi.fn() } }))

import { http } from '@/core/api'

import { messageSearchApi, messageSearchQuery } from './messageSearch.api'

describe('messageSearchQuery', () => {
  it('sends q only for a first page', () => {
    expect(messageSearchQuery('brief')).toBe('?q=brief')
  })

  it('omits an empty or null cursor and sends a real one', () => {
    expect(messageSearchQuery('a b', null)).toBe('?q=a+b')
    expect(messageSearchQuery('a b', '')).toBe('?q=a+b')
    expect(messageSearchQuery('x', 'CUR 1')).toBe('?q=x&cursor=CUR+1')
  })

  it('encodes characters that would break the query', () => {
    const q = new URLSearchParams(messageSearchQuery('a&b=c#d?é').slice(1))
    expect(q.get('q')).toBe('a&b=c#d?é')
    expect(q.has('b')).toBe(false)
  })

  it('caps q at 200 characters (the server answers 422 above)', () => {
    const q = new URLSearchParams(messageSearchQuery('x'.repeat(250)).slice(1))
    expect(q.get('q')).toHaveLength(200)
  })
})

describe('messageSearchApi.search', () => {
  beforeEach(() => vi.clearAllMocks())

  it('GETs /agencies/{agency}/message-search with the term and cursor', async () => {
    vi.mocked(http.get).mockResolvedValue({ data: [], meta: { next_cursor: null, per_page: 30 } })
    await messageSearchApi.search('ag-1', 'brief', 'CUR')
    expect(http.get).toHaveBeenCalledWith('/agencies/ag-1/message-search?q=brief&cursor=CUR')
  })

  it('returns the response body as is', async () => {
    const body = { data: [], meta: { next_cursor: 'N', per_page: 30 } }
    vi.mocked(http.get).mockResolvedValue(body)
    await expect(messageSearchApi.search('ag-1', 'zz')).resolves.toBe(body)
  })
})
