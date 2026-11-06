/**
 * AH-119 Stage B — `useMessageSearch`: the state machine behind the inbox search.
 *
 * Break-revert: search from 1 character → minChars fails; skip `reset()` on a term change →
 * the stale-results case fails; clear the cursor on a failed page → the cursor-kept case fails;
 * ignore `enabled` → the disabled case fails.
 */

import { effectScope, nextTick, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/messageSearch.api', () => ({
  MESSAGE_SEARCH_MIN_CHARS: 2,
  messageSearchApi: { search: vi.fn() },
}))

import { messageSearchApi } from '../api/messageSearch.api'
import { useMessageSearch } from './useMessageSearch'

const search = vi.mocked(messageSearchApi.search)

function hit(ulid: string) {
  return { id: 't', type: 'relationship_thread', attributes: { message_ulid: ulid } } as never
}
const resp = (ids: string[], next: string | null = null) => ({
  data: ids.map(hit),
  meta: { next_cursor: next, per_page: 30 },
})

function setup(agency: string | null = 'ag', term = '', enabled = true) {
  const agencyId = ref<string | null>(agency)
  const t = ref(term)
  const on = ref(enabled)
  const scope = effectScope()
  const api = scope.run(() => useMessageSearch(agencyId, t, on))!
  return { agencyId, t, on, api, scope }
}

async function settle(ms = 300) {
  await nextTick()
  await vi.advanceTimersByTimeAsync(ms)
}

describe('useMessageSearch', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    search.mockReset()
    search.mockResolvedValue(resp([]))
  })
  afterEach(() => vi.useRealTimers())

  it('is idle for an empty or blank term and when disabled', async () => {
    const s = setup('ag', '   ')
    expect(s.api.state.value).toBe('idle')
    s.t.value = 'brief'
    s.on.value = false
    await settle()
    expect(s.api.state.value).toBe('idle')
    expect(search).not.toHaveBeenCalled()
  })

  it('is minChars for one character, searching nothing', async () => {
    const s = setup()
    s.t.value = 'a'
    await settle(1000)
    expect(s.api.state.value).toBe('minChars')
    expect(search).not.toHaveBeenCalled()
  })

  it('is loading during the debounce, then results', async () => {
    search.mockResolvedValue(resp(['a', 'b']))
    const s = setup()
    s.t.value = 'ab'
    await nextTick()
    expect(s.api.state.value).toBe('loading')
    await settle(299)
    expect(search).not.toHaveBeenCalled()
    await settle(1)
    expect(search).toHaveBeenCalledWith('ag', 'ab')
    expect(s.api.state.value).toBe('results')
    expect(s.api.results.value).toHaveLength(2)
  })

  it('is empty for no hits and error for a failure', async () => {
    const s = setup()
    s.t.value = 'zz'
    await settle()
    expect(s.api.state.value).toBe('empty')
    search.mockRejectedValueOnce(new Error('x'))
    s.t.value = 'zzz'
    await settle()
    expect(s.api.state.value).toBe('error')
    expect(s.api.results.value).toEqual([])
  })

  it('clears old results at once when the term changes, before the new answer', async () => {
    search.mockResolvedValueOnce(resp(['a']))
    const s = setup()
    s.t.value = 'ab'
    await settle()
    expect(s.api.results.value).toHaveLength(1)
    s.t.value = 'abc'
    await nextTick()
    expect(s.api.results.value).toEqual([])
  })

  it('re-searches when the agency changes and ignores the old agency’s answer', async () => {
    let late!: (v: ReturnType<typeof resp>) => void
    search.mockImplementationOnce(() => new Promise((r) => (late = r)) as never)
    const s = setup('ag1', 'brief')
    await settle()
    s.agencyId.value = 'ag2'
    search.mockResolvedValueOnce(resp(['new']))
    await settle()
    late(resp(['old']))
    await settle(0)
    expect(search).toHaveBeenLastCalledWith('ag2', 'brief')
    expect(s.api.results.value.map((r) => r.attributes.message_ulid)).toEqual(['new'])
  })

  it('does not search without an agency', async () => {
    const s = setup(null, 'brief')
    await settle(1000)
    expect(search).not.toHaveBeenCalled()
    expect(s.api.state.value).toBe('empty')
  })

  it('loadMore follows the cursor, de-duplicates by message ULID and drops hasMore at the end', async () => {
    search.mockResolvedValueOnce(resp(['a', 'b'], 'C1')).mockResolvedValueOnce(resp(['b', 'c']))
    const s = setup()
    s.t.value = 'ab'
    await settle()
    expect(s.api.hasMore.value).toBe(true)
    await s.api.loadMore()
    expect(search).toHaveBeenLastCalledWith('ag', 'ab', 'C1')
    expect(s.api.results.value.map((r) => r.attributes.message_ulid)).toEqual(['a', 'b', 'c'])
    expect(s.api.hasMore.value).toBe(false)
  })

  it('a failed next page keeps the results and the cursor so it can be asked again', async () => {
    search.mockResolvedValueOnce(resp(['a'], 'C1')).mockRejectedValueOnce(new Error('x'))
    const s = setup()
    s.t.value = 'ab'
    await settle()
    await s.api.loadMore()
    expect(s.api.state.value).toBe('results')
    expect(s.api.hasMore.value).toBe(true)
    expect(s.api.loadingMore.value).toBe(false)
  })

  it('ignores a next page that arrives after the term changed', async () => {
    let late!: (v: ReturnType<typeof resp>) => void
    search.mockResolvedValueOnce(resp(['a'], 'C1'))
    const s = setup()
    s.t.value = 'ab'
    await settle()
    search.mockImplementationOnce(() => new Promise((r) => (late = r)) as never)
    const pending = s.api.loadMore()
    s.t.value = 'abc'
    search.mockResolvedValueOnce(resp(['n']))
    await settle()
    late(resp(['stale']))
    await pending
    expect(s.api.results.value.map((r) => r.attributes.message_ulid)).toEqual(['n'])
  })

  it('does not start a second page while one is loading', async () => {
    let late!: (v: ReturnType<typeof resp>) => void
    search.mockResolvedValueOnce(resp(['a'], 'C1'))
    const s = setup()
    s.t.value = 'ab'
    await settle()
    search.mockImplementationOnce(() => new Promise((r) => (late = r)) as never)
    const first = s.api.loadMore()
    await s.api.loadMore()
    late(resp(['b']))
    await first
    expect(search).toHaveBeenCalledTimes(2)
  })
})
