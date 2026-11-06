/**
 * AH-119 — `useMessageInbox`: first load, filter / agency resets, stale-response protection,
 * cursor "load more", the 45 s poll and clean-up.
 *
 * Break-revert: drop the `mine !== generation` guard in `load` → the stale cases fail; make the
 * poll reset the list → the tail case fails; skip the de-dupe in `loadMore` → the de-dupe case
 * fails; leave the timer running on unmount → the unmount case fails.
 */

import type { MessageInboxResponse, MessageInboxRow } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, ref, type Ref } from 'vue'

vi.mock('../api/messageInbox.api', () => ({
  messageInboxApi: { list: vi.fn(), reassignOwner: vi.fn() },
}))

import { messageInboxApi } from '../api/messageInbox.api'
import {
  INBOX_PAGE_SIZE,
  INBOX_POLL_INTERVAL_MS,
  useMessageInbox,
  type InboxFilters,
} from './useMessageInbox'

const list = vi.mocked(messageInboxApi.list)

function row(id: string, type: MessageInboxRow['type'] = 'relationship_thread'): MessageInboxRow {
  return {
    id,
    type,
    attributes: {
      creator: { id: `c-${id}`, display_name: `Creator ${id}`, avatar_url: null },
      brand: null,
      campaign: null,
      assignment_id: null,
      last_message_at: null,
      preview: null,
      preview_kind: 'text',
      unread_count: 0,
      owner: null,
    },
  }
}

function page(
  rows: MessageInboxRow[],
  next: string | null = null,
  unread = 0,
  facets: MessageInboxResponse['meta']['facets'] = { brands: [], campaigns: [] },
): MessageInboxResponse {
  return { data: rows, meta: { unread_threads_total: unread, facets, next_cursor: next } }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

const NONE: InboxFilters = { brand: [], campaign: [], unread: false, owner: 'all', direct: false }

function mountInbox(agency: string | null = 'ag1', filters: InboxFilters = NONE) {
  const agencyId: Ref<string | null> = ref(agency)
  const filterRef: Ref<InboxFilters> = ref({ ...filters })
  let api!: ReturnType<typeof useMessageInbox>
  const Host = defineComponent({
    setup() {
      api = useMessageInbox(agencyId, filterRef)
      return () => null
    },
  })
  const wrapper = mount(Host)
  return { wrapper, agencyId, filterRef, api: () => api }
}

const ids = (api: ReturnType<typeof useMessageInbox>): string[] => api.rows.value.map((r) => r.id)

describe('useMessageInbox', () => {
  beforeEach(() => {
    // Real setImmediate stays, so flushPromises still resolves.
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    list.mockReset()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('loads page one on mount with the agency, the filters, no cursor and the page size', async () => {
    list.mockResolvedValue(page([row('a'), row('b')], 'cur1', 4, { brands: [], campaigns: [] }))
    const { wrapper, api } = mountInbox('ag1', {
      brand: ['b1'],
      campaign: ['k1'],
      unread: true,
      owner: 'me',
      direct: false,
    })
    expect(api().loading.value).toBe(true)
    await flushPromises()

    expect(list).toHaveBeenCalledTimes(1)
    expect(list).toHaveBeenCalledWith('ag1', {
      brand: ['b1'],
      campaign: ['k1'],
      unread: true,
      owner: 'me',
      direct: false,
      cursor: null,
      perPage: INBOX_PAGE_SIZE,
    })
    expect(ids(api())).toEqual(['a', 'b'])
    expect(api().unreadThreadsTotal.value).toBe(4)
    expect(api().hasMore.value).toBe(true)
    expect(api().loading.value).toBe(false)
    wrapper.unmount()
  })

  it('takes the facets from the response', async () => {
    const facets = {
      brands: [{ id: 'b1', name: 'Acme', color: 'blue' as const, unread_threads: 2 }],
      campaigns: [{ id: 'k1', name: 'Summer', brand_id: 'b1', unread_threads: 1 }],
    }
    list.mockResolvedValue(page([row('a')], null, 0, facets))
    const { wrapper, api } = mountInbox()
    await flushPromises()
    expect(api().facets.value).toEqual(facets)
    expect(api().hasMore.value).toBe(false)
    wrapper.unmount()
  })

  it('does not fetch while the agency is null, and loads once it is set', async () => {
    list.mockResolvedValue(page([row('a')]))
    const { wrapper, agencyId, api } = mountInbox(null)
    await flushPromises()
    await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS * 2)
    expect(list).not.toHaveBeenCalled()
    expect(api().loadError.value).toBe(false)

    agencyId.value = 'ag1'
    await flushPromises()
    expect(list).toHaveBeenCalledTimes(1)
    expect(ids(api())).toEqual(['a'])
    wrapper.unmount()
  })

  it('a filter change drops the list at once and reloads page one for the new filters', async () => {
    const second = deferred<MessageInboxResponse>()
    list.mockResolvedValueOnce(page([row('a')], 'cur1')).mockReturnValueOnce(second.promise)
    const { wrapper, filterRef, api } = mountInbox()
    await flushPromises()
    expect(ids(api())).toEqual(['a'])

    filterRef.value = { ...NONE, unread: true }
    // Dropped synchronously: the old list never shows under the new filter.
    expect(ids(api())).toEqual([])
    expect(api().hasMore.value).toBe(false)
    expect(api().loading.value).toBe(true)

    second.resolve(page([row('z')]))
    await flushPromises()
    expect(list).toHaveBeenLastCalledWith(
      'ag1',
      expect.objectContaining({ unread: true, cursor: null }),
    )
    expect(ids(api())).toEqual(['z'])
    wrapper.unmount()
  })

  it('does not refetch when the filters are set to the same values', async () => {
    list.mockResolvedValue(page([row('a')]))
    const { wrapper, filterRef } = mountInbox()
    await flushPromises()
    filterRef.value = { ...NONE }
    await flushPromises()
    expect(list).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('drops a stale response from the old filters that lands after the new one', async () => {
    const old = deferred<MessageInboxResponse>()
    const fresh = deferred<MessageInboxResponse>()
    list.mockReturnValueOnce(old.promise).mockReturnValueOnce(fresh.promise)
    const { wrapper, filterRef, api } = mountInbox()
    await flushPromises()

    filterRef.value = { ...NONE, owner: 'me' }
    fresh.resolve(page([row('new')], null, 1))
    await flushPromises()
    expect(ids(api())).toEqual(['new'])

    old.resolve(page([row('old')], 'stale-cursor', 99))
    await flushPromises()
    expect(ids(api())).toEqual(['new'])
    expect(api().unreadThreadsTotal.value).toBe(1)
    expect(api().hasMore.value).toBe(false)
    wrapper.unmount()
  })

  it('drops a stale response from the old agency', async () => {
    const old = deferred<MessageInboxResponse>()
    const fresh = deferred<MessageInboxResponse>()
    list.mockReturnValueOnce(old.promise).mockReturnValueOnce(fresh.promise)
    const { wrapper, agencyId, api } = mountInbox('ag1')
    await flushPromises()

    agencyId.value = 'ag2'
    fresh.resolve(page([row('ag2-row')]))
    await flushPromises()
    old.resolve(page([row('ag1-row')]))
    await flushPromises()

    expect(list).toHaveBeenLastCalledWith('ag2', expect.anything())
    expect(ids(api())).toEqual(['ag2-row'])
    wrapper.unmount()
  })

  it('a stale ERROR from the old filters does not flag the new list', async () => {
    const old = deferred<MessageInboxResponse>()
    list.mockReturnValueOnce(old.promise).mockResolvedValueOnce(page([row('new')]))
    const { wrapper, filterRef, api } = mountInbox()
    await flushPromises()
    filterRef.value = { ...NONE, unread: true }
    await flushPromises()
    old.reject(new Error('boom'))
    await flushPromises()
    expect(api().loadError.value).toBe(false)
    expect(ids(api())).toEqual(['new'])
    wrapper.unmount()
  })

  describe('loadMore', () => {
    it('appends the next page using the cursor and moves the cursor on', async () => {
      list
        .mockResolvedValueOnce(page([row('a'), row('b')], 'cur1'))
        .mockResolvedValueOnce(page([row('c')], null))
      const { wrapper, api } = mountInbox()
      await flushPromises()

      await api().loadMore()
      expect(list).toHaveBeenLastCalledWith('ag1', expect.objectContaining({ cursor: 'cur1' }))
      expect(ids(api())).toEqual(['a', 'b', 'c'])
      expect(api().hasMore.value).toBe(false)
      wrapper.unmount()
    })

    it('de-dupes a row the next page repeats', async () => {
      list
        .mockResolvedValueOnce(page([row('a'), row('b')], 'cur1'))
        .mockResolvedValueOnce(page([row('b'), row('c')], null))
      const { wrapper, api } = mountInbox()
      await flushPromises()
      await api().loadMore()
      expect(ids(api())).toEqual(['a', 'b', 'c'])
      wrapper.unmount()
    })

    it('treats the same id of two thread types as two rows', async () => {
      list
        .mockResolvedValueOnce(page([row('x', 'relationship_thread')], 'cur1'))
        .mockResolvedValueOnce(page([row('x', 'assignment_thread')], null))
      const { wrapper, api } = mountInbox()
      await flushPromises()
      await api().loadMore()
      expect(api().rows.value.map((r) => `${r.type}:${r.id}`)).toEqual([
        'relationship_thread:x',
        'assignment_thread:x',
      ])
      wrapper.unmount()
    })

    it('does nothing without a cursor', async () => {
      list.mockResolvedValue(page([row('a')], null))
      const { wrapper, api } = mountInbox()
      await flushPromises()
      await api().loadMore()
      expect(list).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })

    it('does not start a second request while one is in flight', async () => {
      const more = deferred<MessageInboxResponse>()
      list.mockResolvedValueOnce(page([row('a')], 'cur1')).mockReturnValueOnce(more.promise)
      const { wrapper, api } = mountInbox()
      await flushPromises()
      const first = api().loadMore()
      expect(api().loadingMore.value).toBe(true)
      void api().loadMore()
      expect(list).toHaveBeenCalledTimes(2)
      more.resolve(page([row('b')]))
      await first
      expect(api().loadingMore.value).toBe(false)
      wrapper.unmount()
    })

    it('drops a page that lands after the filters changed', async () => {
      const more = deferred<MessageInboxResponse>()
      list
        .mockResolvedValueOnce(page([row('a')], 'cur1'))
        .mockReturnValueOnce(more.promise)
        .mockResolvedValueOnce(page([row('z')], null))
      const { wrapper, filterRef, api } = mountInbox()
      await flushPromises()
      const pending = api().loadMore()
      filterRef.value = { ...NONE, unread: true }
      await flushPromises()
      more.resolve(page([row('stale')], 'stale-cur'))
      await pending
      expect(ids(api())).toEqual(['z'])
      expect(api().hasMore.value).toBe(false)
      wrapper.unmount()
    })

    it('a failed page keeps the list and the cursor, so the next call retries', async () => {
      list
        .mockResolvedValueOnce(page([row('a')], 'cur1'))
        .mockRejectedValueOnce(new Error('boom'))
        .mockResolvedValueOnce(page([row('b')], null))
      const { wrapper, api } = mountInbox()
      await flushPromises()
      await api().loadMore()
      expect(ids(api())).toEqual(['a'])
      expect(api().hasMore.value).toBe(true)
      expect(api().loadError.value).toBe(false)
      await api().loadMore()
      expect(ids(api())).toEqual(['a', 'b'])
      wrapper.unmount()
    })
  })

  describe('poll', () => {
    it('refreshes every 45 s, not before', async () => {
      list.mockResolvedValue(page([row('a')]))
      const { wrapper } = mountInbox()
      await flushPromises()
      expect(list).toHaveBeenCalledTimes(1)

      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS - 1)
      expect(list).toHaveBeenCalledTimes(1)
      await vi.advanceTimersByTimeAsync(1)
      expect(list).toHaveBeenCalledTimes(2)
      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS)
      expect(list).toHaveBeenCalledTimes(3)
      wrapper.unmount()
    })

    it('refreshes the head and keeps the loaded tail and its cursor', async () => {
      list
        .mockResolvedValueOnce(page([row('a'), row('b')], 'cur1', 1))
        .mockResolvedValueOnce(page([row('c')], 'cur2', 1))
        // The poll: a new conversation on top, a moved, b gone from page one's window.
        .mockResolvedValueOnce(page([row('new'), row('a')], 'cur-head', 7))
        .mockResolvedValueOnce(page([row('d')], null))
      const { wrapper, api } = mountInbox()
      await flushPromises()
      await api().loadMore()
      expect(ids(api())).toEqual(['a', 'b', 'c'])

      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS)
      // Head first (fresh order), then the already loaded rows the head did not return.
      expect(ids(api())).toEqual(['new', 'a', 'b', 'c'])
      expect(api().unreadThreadsTotal.value).toBe(7)
      // The cursor still points past the loaded pages, not at the poll's own cursor.
      expect(api().hasMore.value).toBe(true)
      await api().loadMore()
      expect(list).toHaveBeenLastCalledWith('ag1', expect.objectContaining({ cursor: 'cur2' }))
      expect(ids(api())).toEqual(['new', 'a', 'b', 'c', 'd'])
      wrapper.unmount()
    })

    it('replaces a row with its fresher copy from the head', async () => {
      const stale = row('a')
      const fresh = row('a')
      fresh.attributes.unread_count = 5
      list.mockResolvedValueOnce(page([stale])).mockResolvedValueOnce(page([fresh]))
      const { wrapper, api } = mountInbox()
      await flushPromises()
      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS)
      expect(api().rows.value).toHaveLength(1)
      expect(api().rows.value[0]?.attributes.unread_count).toBe(5)
      wrapper.unmount()
    })

    it('a filter change restarts the 45 s clock', async () => {
      list.mockResolvedValue(page([row('a')]))
      const { wrapper, filterRef } = mountInbox()
      await flushPromises()
      await vi.advanceTimersByTimeAsync(40_000)
      filterRef.value = { ...NONE, unread: true }
      await flushPromises()
      expect(list).toHaveBeenCalledTimes(2)
      await vi.advanceTimersByTimeAsync(10_000)
      // 50 s after mount but only 10 s after the change: no poll yet.
      expect(list).toHaveBeenCalledTimes(2)
      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS - 10_000)
      expect(list).toHaveBeenCalledTimes(3)
      wrapper.unmount()
    })

    it('keeps polling after a failed poll', async () => {
      list
        .mockResolvedValueOnce(page([row('a')]))
        .mockRejectedValueOnce(new Error('boom'))
        .mockResolvedValueOnce(page([row('a'), row('b')]))
      const { wrapper, api } = mountInbox()
      await flushPromises()
      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS)
      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS)
      expect(ids(api())).toEqual(['a', 'b'])
      wrapper.unmount()
    })
  })

  describe('loadError', () => {
    it('is set when the first load fails with nothing loaded, and clears on the next success', async () => {
      list.mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce(page([row('a')]))
      const { wrapper, api } = mountInbox()
      await flushPromises()
      expect(api().loadError.value).toBe(true)
      expect(api().loading.value).toBe(false)

      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS)
      expect(api().loadError.value).toBe(false)
      expect(ids(api())).toEqual(['a'])
      wrapper.unmount()
    })

    it('stays false when a poll fails but rows are already loaded, and the rows stay', async () => {
      list.mockResolvedValueOnce(page([row('a')])).mockRejectedValueOnce(new Error('boom'))
      const { wrapper, api } = mountInbox()
      await flushPromises()
      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS)
      expect(list).toHaveBeenCalledTimes(2)
      expect(api().loadError.value).toBe(false)
      expect(ids(api())).toEqual(['a'])
      wrapper.unmount()
    })

    it('a filter change that fails shows the error (the old rows were dropped)', async () => {
      list.mockResolvedValueOnce(page([row('a')])).mockRejectedValueOnce(new Error('boom'))
      const { wrapper, filterRef, api } = mountInbox()
      await flushPromises()
      filterRef.value = { ...NONE, unread: true }
      await flushPromises()
      expect(api().loadError.value).toBe(true)
      expect(ids(api())).toEqual([])
      wrapper.unmount()
    })
  })

  describe('unmount', () => {
    it('clears the poll timer and never fetches again', async () => {
      list.mockResolvedValue(page([row('a')]))
      const { wrapper } = mountInbox()
      await flushPromises()
      expect(vi.getTimerCount()).toBe(1)

      wrapper.unmount()
      expect(vi.getTimerCount()).toBe(0)
      await vi.advanceTimersByTimeAsync(INBOX_POLL_INTERVAL_MS * 3)
      expect(list).toHaveBeenCalledTimes(1)
    })

    it('ignores a response that lands after unmount and does not reschedule', async () => {
      const inflight = deferred<MessageInboxResponse>()
      list.mockReturnValueOnce(inflight.promise)
      const { wrapper, api } = mountInbox()
      wrapper.unmount()
      inflight.resolve(page([row('late')]))
      await flushPromises()
      expect(ids(api())).toEqual([])
      expect(vi.getTimerCount()).toBe(0)
    })

    it('a filter change after unmount does not fetch', async () => {
      list.mockResolvedValue(page([row('a')]))
      const { wrapper, filterRef } = mountInbox()
      await flushPromises()
      wrapper.unmount()
      filterRef.value = { ...NONE, unread: true }
      await flushPromises()
      expect(list).toHaveBeenCalledTimes(1)
      expect(vi.getTimerCount()).toBe(0)
    })
  })
})
