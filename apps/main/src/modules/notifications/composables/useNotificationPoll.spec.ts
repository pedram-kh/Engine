import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'

vi.mock('../api/notifications.api', () => ({
  notificationsApi: {
    list: vi.fn(),
    unreadCount: vi.fn(),
    markRead: vi.fn(),
    readAll: vi.fn(),
  },
}))

import { notificationsApi } from '../api/notifications.api'

import { requestNotificationsRefresh } from './notificationsRefresh'

import {
  NOTIFICATION_POLL_INTERVAL_MS,
  useNotificationPoll,
  type NotificationPollHandle,
} from './useNotificationPoll'

function countEnvelope(n: number): {
  data: { type: 'notification_unread_count'; attributes: { unread_count: number } }
} {
  return { data: { type: 'notification_unread_count', attributes: { unread_count: n } } }
}

function splitEnvelope(
  total: number,
  messages?: number,
): {
  data: {
    type: 'notification_unread_count'
    attributes: { unread_count: number; message_unread_count?: number }
  }
} {
  return {
    data: {
      type: 'notification_unread_count',
      attributes: {
        unread_count: total,
        ...(messages === undefined ? {} : { message_unread_count: messages }),
      },
    },
  }
}

function setVisibility(state: 'visible' | 'hidden'): void {
  Object.defineProperty(document, 'visibilityState', {
    value: state,
    configurable: true,
  })
  document.dispatchEvent(new Event('visibilitychange'))
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()
  setVisibility('visible')
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useNotificationPoll', () => {
  it('does an immediate fetch on start and sets the authoritative count', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(3))
    const poll = useNotificationPoll()
    expect(poll.unreadCount.value).toBe(0)

    await poll.start()
    await flushPromises()

    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)
    expect(poll.unreadCount.value).toBe(3)
    poll.cancel()
  })

  it('re-polls steadily at the flat 45s interval (no backoff)', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(1))
    const poll = useNotificationPoll()
    await poll.start()
    await flushPromises()
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_INTERVAL_MS)
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_INTERVAL_MS)
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(3)
    poll.cancel()
  })

  it('optimistic applyMarkRead decrements by one, floored at zero', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(2))
    const poll = useNotificationPoll()
    await poll.start()
    await flushPromises()
    expect(poll.unreadCount.value).toBe(2)

    poll.applyMarkRead()
    expect(poll.unreadCount.value).toBe(1)
    poll.applyMarkRead()
    expect(poll.unreadCount.value).toBe(0)
    poll.applyMarkRead()
    expect(poll.unreadCount.value).toBe(0)
    poll.cancel()
  })

  it('optimistic applyReadAll zeroes the count', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(7))
    const poll = useNotificationPoll()
    await poll.start()
    await flushPromises()
    expect(poll.unreadCount.value).toBe(7)

    poll.applyReadAll()
    expect(poll.unreadCount.value).toBe(0)
    poll.cancel()
  })

  it('the steady poll reconciles an optimistic drift on the next tick', async () => {
    vi.mocked(notificationsApi.unreadCount)
      .mockResolvedValueOnce(countEnvelope(5))
      .mockResolvedValue(countEnvelope(4))
    const poll = useNotificationPoll()
    await poll.start()
    await flushPromises()
    expect(poll.unreadCount.value).toBe(5)

    // Optimistically over-decrement (simulate two local mark-reads).
    poll.applyMarkRead()
    poll.applyMarkRead()
    expect(poll.unreadCount.value).toBe(3)

    // Next authoritative tick wins.
    await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_INTERVAL_MS)
    expect(poll.unreadCount.value).toBe(4)
    poll.cancel()
  })

  it('pauses the reschedule while the tab is hidden and refetches on return to visible', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(1))
    const poll = useNotificationPoll()
    await poll.start()
    await flushPromises()
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)

    // Go hidden — the in-flight reschedule is cleared; advancing time fires nothing.
    setVisibility('hidden')
    await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_INTERVAL_MS * 3)
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)

    // Back to visible — one immediate refetch, then the cadence resumes.
    setVisibility('visible')
    await flushPromises()
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_INTERVAL_MS)
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(3)
    poll.cancel()
  })

  it('cancel() stops the loop — no further fetches fire', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(1))
    const poll = useNotificationPoll()
    await poll.start()
    await flushPromises()
    const before = vi.mocked(notificationsApi.unreadCount).mock.calls.length

    poll.cancel()
    await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_INTERVAL_MS * 3)
    expect(vi.mocked(notificationsApi.unreadCount).mock.calls.length).toBe(before)
  })

  // The onBeforeUnmount proof (the BulkInvitePage.spec / useVendorBounce.spec
  // cleanup precedent): mounted in a real component, the poll must stop firing
  // the instant the component unmounts.
  it('tears down the poll on component unmount (onBeforeUnmount)', async () => {
    vi.mocked(notificationsApi.unreadCount).mockResolvedValue(countEnvelope(1))

    let handle: NotificationPollHandle | null = null
    const Harness = defineComponent({
      setup() {
        handle = useNotificationPoll()
        void handle.start()
        return () => null
      },
    })

    const wrapper = mount(Harness)
    await flushPromises()
    const before = vi.mocked(notificationsApi.unreadCount).mock.calls.length
    expect(before).toBeGreaterThanOrEqual(1)

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_INTERVAL_MS * 3)
    await flushPromises()

    expect(vi.mocked(notificationsApi.unreadCount).mock.calls.length).toBe(before)
    expect((handle as NotificationPollHandle | null)?.isPolling.value).toBe(false)
  })

  // AH-108 — the Messages tab's number rides the same request as the bell's.
  describe('messageUnreadCount (AH-108)', () => {
    it('takes the message split from the same unread-count response as the total', async () => {
      vi.mocked(notificationsApi.unreadCount).mockResolvedValue(splitEnvelope(9, 4))
      const poll = useNotificationPoll()

      await poll.start()
      await flushPromises()

      expect(poll.unreadCount.value).toBe(9)
      expect(poll.messageUnreadCount.value).toBe(4)
      expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)
      poll.cancel()
    })

    it('reads an older backend with no split as 0 and clears a stale number', async () => {
      vi.mocked(notificationsApi.unreadCount).mockResolvedValueOnce(splitEnvelope(5, 5))
      const poll = useNotificationPoll()
      await poll.start()
      await flushPromises()
      expect(poll.messageUnreadCount.value).toBe(5)

      vi.mocked(notificationsApi.unreadCount).mockResolvedValue(splitEnvelope(5))
      await vi.advanceTimersByTimeAsync(NOTIFICATION_POLL_INTERVAL_MS)
      await flushPromises()
      expect(poll.messageUnreadCount.value).toBe(0)
      poll.cancel()
    })

    it('mark-all-read zeroes both numbers at once', async () => {
      vi.mocked(notificationsApi.unreadCount).mockResolvedValue(splitEnvelope(6, 2))
      const poll = useNotificationPoll()
      await poll.start()
      await flushPromises()

      poll.applyReadAll()

      expect(poll.unreadCount.value).toBe(0)
      expect(poll.messageUnreadCount.value).toBe(0)
      poll.cancel()
    })

    it('re-reads the split on the refresh event (a thread was just read)', async () => {
      vi.mocked(notificationsApi.unreadCount).mockResolvedValueOnce(splitEnvelope(3, 3))
      const poll = useNotificationPoll()
      await poll.start()
      await flushPromises()
      expect(poll.messageUnreadCount.value).toBe(3)

      vi.mocked(notificationsApi.unreadCount).mockResolvedValue(splitEnvelope(0, 0))
      requestNotificationsRefresh()
      await flushPromises()

      expect(poll.messageUnreadCount.value).toBe(0)
      poll.cancel()
    })
  })
})
