/**
 * AH-119 — `messageInboxApi`: the query string the unified inbox sends and the owner reassign.
 *
 * Break-revert: stop repeating `brand[]` (join them) → the repeat case fails; send `unread=0`
 * for false → the omits-empties case fails; PATCH the wrong path or body key → the reassign case
 * fails. The HTTP singleton is mocked so no transport runs.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/core/api', () => ({
  http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import { http } from '@/core/api'

import { messageInboxApi, messageInboxQuery } from './messageInbox.api'

const mockHttp = vi.mocked(http)

function parsed(qs: string): URLSearchParams {
  return new URLSearchParams(qs.replace(/^\?/, ''))
}

describe('messageInboxQuery', () => {
  it('is empty with no params, so the URL carries no "?"', () => {
    expect(messageInboxQuery({})).toBe('')
  })

  it('omits every empty value: no selections, unread false, no cursor', () => {
    expect(
      messageInboxQuery({ brand: [], campaign: [], unread: false, cursor: null, owner: undefined }),
    ).toBe('')
    expect(messageInboxQuery({ cursor: '' })).toBe('')
  })

  it('repeats brand[] and campaign[] once per id, in order', () => {
    const q = parsed(messageInboxQuery({ brand: ['a', 'b'], campaign: ['c1', 'c2', 'c3'] }))
    expect(q.getAll('brand[]')).toEqual(['a', 'b'])
    expect(q.getAll('campaign[]')).toEqual(['c1', 'c2', 'c3'])
  })

  it('sends unread=1 only when true', () => {
    expect(parsed(messageInboxQuery({ unread: true })).get('unread')).toBe('1')
    expect(parsed(messageInboxQuery({ unread: false })).has('unread')).toBe(false)
  })

  it('sends owner when given, either value', () => {
    expect(parsed(messageInboxQuery({ owner: 'me' })).get('owner')).toBe('me')
    expect(parsed(messageInboxQuery({ owner: 'all' })).get('owner')).toBe('all')
  })

  it('sends the cursor and per_page', () => {
    const q = parsed(messageInboxQuery({ cursor: 'abc==', perPage: 30 }))
    expect(q.get('cursor')).toBe('abc==')
    expect(q.get('per_page')).toBe('30')
  })

  it('sends direct=1 only when direct is on', () => {
    expect(parsed(messageInboxQuery({ direct: true })).get('direct')).toBe('1')
    expect(parsed(messageInboxQuery({ direct: false })).has('direct')).toBe(false)
    expect(parsed(messageInboxQuery({})).has('direct')).toBe(false)
  })

  it('builds the whole query in a stable order', () => {
    const qs = messageInboxQuery({
      brand: ['a', 'b'],
      campaign: ['c'],
      unread: true,
      owner: 'me',
      cursor: 'cur',
      perPage: 10,
    })
    expect(decodeURIComponent(qs)).toBe(
      '?brand[]=a&brand[]=b&campaign[]=c&unread=1&owner=me&cursor=cur&per_page=10',
    )
  })
})

describe('messageInboxApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHttp.get.mockResolvedValue({ data: [], meta: {} })
    mockHttp.patch.mockResolvedValue({})
  })

  it('list GETs the agency inbox path with the query appended', async () => {
    await messageInboxApi.list('ag1', { brand: ['a'], unread: true })
    const url = mockHttp.get.mock.calls.at(-1)![0] as string
    expect(url.startsWith('/agencies/ag1/message-inbox?')).toBe(true)
    const q = parsed(url.split('?')[1] as string)
    expect(q.getAll('brand[]')).toEqual(['a'])
    expect(q.get('unread')).toBe('1')
  })

  it('list with no params hits the bare path', async () => {
    await messageInboxApi.list('ag1')
    expect(mockHttp.get).toHaveBeenCalledWith('/agencies/ag1/message-inbox')
  })

  it('reassignOwner PATCHes the creator owner path with owner_user_id', async () => {
    await messageInboxApi.reassignOwner('ag1', 'cr1', 'user-9')
    expect(mockHttp.patch).toHaveBeenCalledWith('/agencies/ag1/creators/cr1/owner', {
      owner_user_id: 'user-9',
    })
  })
})
