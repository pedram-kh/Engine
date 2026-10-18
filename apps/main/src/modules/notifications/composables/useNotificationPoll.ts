/**
 * Unread-count polling composable (S11.0 Ch3a, D-4 / D-5).
 *
 * Mirrors the LIFECYCLE discipline of `useVendorBounce` — refs in composable
 * scope, a self-rescheduling `setTimeout`, a `cancelled` flag + `clearTimeout`,
 * and an `onBeforeUnmount(cancel)` cleanup guarded by `getCurrentInstance()` —
 * but NOT its shape: `useVendorBounce` is a terminating saga poll with
 * exponential backoff, whereas this is a STEADY, never-terminating count poll.
 * A flat 45 s interval is therefore correct (backoff would be wrong for a poll
 * that has no terminal state).
 *
 * State lives ONLY in refs (the §5.15 use-theme-is-SoT ratchet) — there is no
 * localStorage, no module-level singleton, no persistence surface of any kind.
 *
 * Reconciliation (D-5):
 *   - `applyMarkRead()` optimistically decrements by one (floored at 0);
 *   - `applyReadAll()` optimistically zeroes;
 *   - the steady poll (and any feed fetch that calls `set()`) is the
 *     AUTHORITATIVE reconciler — the endpoints already return authoritative
 *     counts, so an optimistic drift self-heals on the next tick.
 *
 * Tab-visibility gating (Ch3a review addition): a forever-poll that keeps
 * firing in every backgrounded tab is needless load. While the tab is hidden
 * the reschedule is suppressed; on `visibilitychange` back to visible the poll
 * resumes with one immediate refetch. The listener is composable-scoped and
 * torn down alongside the timer in `cancel()`.
 */

import { getCurrentInstance, onBeforeUnmount, ref, type Ref } from 'vue'

import { notificationsApi } from '../api/notifications.api'
import { NOTIFICATIONS_REFRESH_EVENT } from './notificationsRefresh'

export const NOTIFICATION_POLL_INTERVAL_MS = 45000

export interface NotificationPollHandle {
  unreadCount: Ref<number>
  /**
   * AH-108 — how many of `unreadCount` are new-message notifications (the
   * Messages tab badge). Set by the poll only: a feed fetch carries no message
   * split, so it never touches this.
   */
  messageUnreadCount: Ref<number>
  /**
   * AH-108 — true once a count has actually been read from the server (a poll or a
   * reconcile). Until then `unreadCount` is the placeholder 0, not a fact, and the
   * home-screen icon badge must not be written from it.
   */
  hasLoaded: Ref<boolean>
  isPolling: Ref<boolean>
  /** Start the steady poll (does an immediate first fetch). */
  start: () => Promise<void>
  /** Stop polling + tear down the visibility listener. */
  cancel: () => void
  /** Force an out-of-band refetch (e.g. on dropdown open). */
  refresh: () => Promise<void>
  /** Authoritatively set the count (e.g. from a feed fetch's meta.unread_count). */
  set: (count: number) => void
  /** Optimistic −1 on a single mark-read (floored at 0). */
  applyMarkRead: () => void
  /** Optimistic 0 on mark-all-read. */
  applyReadAll: () => void
}

export function useNotificationPoll(): NotificationPollHandle {
  const unreadCount = ref(0)
  const messageUnreadCount = ref(0)
  const hasLoaded = ref(false)
  const isPolling = ref(false)

  let cancelled = false
  let timeoutHandle: ReturnType<typeof setTimeout> | null = null
  let visibilityListener: (() => void) | null = null
  let refreshListener: (() => void) | null = null

  function isHidden(): boolean {
    return typeof document !== 'undefined' && document.visibilityState === 'hidden'
  }

  function clearPending(): void {
    if (timeoutHandle !== null) {
      clearTimeout(timeoutHandle)
      timeoutHandle = null
    }
  }

  function set(count: number): void {
    unreadCount.value = Math.max(0, count)
    hasLoaded.value = true
  }

  function applyMarkRead(): void {
    unreadCount.value = Math.max(0, unreadCount.value - 1)
  }

  function applyReadAll(): void {
    unreadCount.value = 0
    messageUnreadCount.value = 0
  }

  async function fetchCount(): Promise<void> {
    try {
      const envelope = await notificationsApi.unreadCount()
      if (cancelled) return
      const attributes = envelope.data.attributes
      set(attributes.unread_count)
      // An older backend sends no split: read it as 0 rather than leave a stale number.
      messageUnreadCount.value = Math.max(0, attributes.message_unread_count ?? 0)
    } catch {
      // Transient failure — keep the last known count and let the next tick
      // reconcile. A count poll must never surface an error to the user.
    }
  }

  function schedule(): void {
    if (cancelled) return
    // While hidden, do NOT re-arm the timer. The visibilitychange handler
    // resumes the loop (with an immediate fetch) when the tab returns.
    if (isHidden()) return
    timeoutHandle = setTimeout(() => {
      void tick()
    }, NOTIFICATION_POLL_INTERVAL_MS)
  }

  async function tick(): Promise<void> {
    if (cancelled) return
    await fetchCount()
    if (cancelled) return
    schedule()
  }

  function onVisibilityChange(): void {
    if (cancelled) return
    if (isHidden()) {
      // Going hidden: stop the in-flight reschedule; the loop pauses.
      clearPending()
      return
    }
    // Coming back to visible: refetch immediately, then resume the cadence.
    clearPending()
    void tick()
  }

  async function start(): Promise<void> {
    cancelled = false
    isPolling.value = true
    if (visibilityListener === null && typeof document !== 'undefined') {
      visibilityListener = onVisibilityChange
      document.addEventListener('visibilitychange', visibilityListener)
    }
    // AH-102 — a foreground push asks every running poll to re-read now. The
    // same lifetime as the visibility listener: added at start, removed at cancel.
    if (refreshListener === null && typeof document !== 'undefined') {
      refreshListener = () => {
        if (!cancelled) {
          void fetchCount()
        }
      }
      document.addEventListener(NOTIFICATIONS_REFRESH_EVENT, refreshListener)
    }
    await tick()
  }

  function cancel(): void {
    cancelled = true
    isPolling.value = false
    clearPending()
    if (visibilityListener !== null && typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', visibilityListener)
      visibilityListener = null
    }
    if (refreshListener !== null && typeof document !== 'undefined') {
      document.removeEventListener(NOTIFICATIONS_REFRESH_EVENT, refreshListener)
      refreshListener = null
    }
  }

  async function refresh(): Promise<void> {
    await fetchCount()
  }

  if (getCurrentInstance() !== null) {
    onBeforeUnmount(() => {
      cancel()
    })
  }

  return {
    unreadCount,
    messageUnreadCount,
    hasLoaded,
    isPolling,
    start,
    cancel,
    refresh,
    set,
    applyMarkRead,
    applyReadAll,
  }
}
