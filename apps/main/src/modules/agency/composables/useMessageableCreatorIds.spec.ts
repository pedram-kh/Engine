/**
 * AH-116 (item 7) — `useMessageableCreatorIds`: the set of creators the agency can message, read
 * from the contact picker's list. Pins the lazy single read, the paging, the 10-page cap and that
 * every failure leaves the set EMPTY (fail closed), never half-filled.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/messaging/api/relationshipMessaging.api', () => ({
  relationshipMessagingApi: { messageableCreators: vi.fn() },
}))

import { relationshipMessagingApi } from '@/modules/messaging/api/relationshipMessaging.api'
import { MAX_PAGES, useMessageableCreatorIds } from './useMessageableCreatorIds'

const list = vi.mocked(relationshipMessagingApi.messageableCreators)

function page(ids: string[], current: number, last: number) {
  return {
    data: ids.map((id) => ({
      id,
      type: 'messageable_creator' as const,
      attributes: { display_name: null, avatar_url: null },
    })),
    meta: { total: ids.length, page: current, per_page: 100, last_page: last },
  }
}

describe('useMessageableCreatorIds (AH-116)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('reads nothing until asked', () => {
    const { canMessage } = useMessageableCreatorIds(() => 'agency-1')

    expect(list).not.toHaveBeenCalled()
    expect(canMessage('c1')).toBe(false)
  })

  it('reads once however many times it is asked, 100 a page', async () => {
    list.mockResolvedValue(page(['c1', 'c2'], 1, 1))
    const { ensureLoaded, canMessage } = useMessageableCreatorIds(() => 'agency-1')

    await Promise.all([ensureLoaded(), ensureLoaded()])
    await ensureLoaded()

    expect(list).toHaveBeenCalledTimes(1)
    expect(list).toHaveBeenCalledWith('agency-1', { page: 1, perPage: 100 })
    expect(canMessage('c1')).toBe(true)
    expect(canMessage('c2')).toBe(true)
    expect(canMessage('c3')).toBe(false)
  })

  it('never says yes to a missing creator id', async () => {
    list.mockResolvedValue(page(['c1'], 1, 1))
    const { ensureLoaded, canMessage } = useMessageableCreatorIds(() => 'agency-1')
    await ensureLoaded()

    expect(canMessage(null)).toBe(false)
    expect(canMessage(undefined)).toBe(false)
  })

  it('follows last_page, collecting every page', async () => {
    list
      .mockResolvedValueOnce(page(['c1'], 1, 3))
      .mockResolvedValueOnce(page(['c2'], 2, 3))
      .mockResolvedValueOnce(page(['c3'], 3, 3))
    const { ensureLoaded, canMessage } = useMessageableCreatorIds(() => 'agency-1')

    await ensureLoaded()

    expect(list).toHaveBeenCalledTimes(3)
    expect(['c1', 'c2', 'c3'].every((id) => canMessage(id))).toBe(true)
  })

  it('stops at the page cap: a creator beyond it is not in the set', async () => {
    list.mockImplementation(async (_agency, params) =>
      page([`c${params?.page}`], params?.page ?? 1, MAX_PAGES + 5),
    )
    const { ensureLoaded, canMessage } = useMessageableCreatorIds(() => 'agency-1')

    await ensureLoaded()

    expect(list).toHaveBeenCalledTimes(MAX_PAGES)
    expect(canMessage(`c${MAX_PAGES}`)).toBe(true)
    expect(canMessage(`c${MAX_PAGES + 1}`)).toBe(false)
  })

  it('a failure on a LATER page empties the whole set, so nothing half-checked is offered', async () => {
    list.mockResolvedValueOnce(page(['c1'], 1, 2)).mockRejectedValueOnce(new Error('boom'))
    const { ensureLoaded, canMessage } = useMessageableCreatorIds(() => 'agency-1')

    await expect(ensureLoaded()).resolves.toBeUndefined()

    expect(canMessage('c1')).toBe(false)
  })

  it('a failed read is not retried by a later ask', async () => {
    list.mockRejectedValue(new Error('boom'))
    const { ensureLoaded } = useMessageableCreatorIds(() => 'agency-1')

    await ensureLoaded()
    await ensureLoaded()

    expect(list).toHaveBeenCalledTimes(1)
  })
})
