import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/notifications.api', () => ({
  notificationsApi: { unreadCount: vi.fn() },
}))

import { notificationsApi } from '../api/notifications.api'

import { NOTIFICATIONS_REFRESH_EVENT, requestNotificationsRefresh } from './notificationsRefresh'
import { useNotificationPoll } from './useNotificationPoll'

/**
 * AH-102 — a foreground push asks every running poll to re-read the count NOW.
 * A separate spec file so `useNotificationPoll.spec.ts` stays untouched: that
 * file is the proof the poll's own behaviour did not change.
 */

function count(n: number) {
  return { data: { type: 'notification_unread_count', attributes: { unread_count: n } } } as never
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('notifications refresh event', () => {
  it('refetches the unread count immediately when requested', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValueOnce(count(1))
    const poll = useNotificationPoll()
    await poll.start()
    await flushPromises()
    expect(poll.unreadCount.value).toBe(1)

    vi.mocked(notificationsApi.unreadCount).mockResolvedValueOnce(count(4))
    requestNotificationsRefresh()
    await flushPromises()

    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(2)
    expect(poll.unreadCount.value).toBe(4)
    poll.cancel()
  })

  it('does nothing before start()', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(count(1))
    const poll = useNotificationPoll()

    requestNotificationsRefresh()
    await flushPromises()

    expect(notificationsApi.unreadCount).not.toHaveBeenCalled()
    poll.cancel()
  })

  it('stops listening after cancel()', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(count(1))
    const poll = useNotificationPoll()
    await poll.start()
    await flushPromises()
    poll.cancel()
    vi.mocked(notificationsApi.unreadCount).mockClear()

    requestNotificationsRefresh()
    await flushPromises()

    expect(notificationsApi.unreadCount).not.toHaveBeenCalled()
  })

  it('a second start() does not stack a second listener', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(count(1))
    const poll = useNotificationPoll()
    await poll.start()
    await poll.start()
    await flushPromises()
    vi.mocked(notificationsApi.unreadCount).mockClear()

    requestNotificationsRefresh()
    await flushPromises()

    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)
    poll.cancel()
  })

  it('exports the event name the wiring dispatches', () => {
    const seen = vi.fn()
    document.addEventListener(NOTIFICATIONS_REFRESH_EVENT, seen)
    requestNotificationsRefresh()
    document.removeEventListener(NOTIFICATIONS_REFRESH_EVENT, seen)

    expect(seen).toHaveBeenCalledTimes(1)
  })
})
