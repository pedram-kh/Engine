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

export const NOTIFICATION_POLL_INTERVAL_MS = 45000

export interface NotificationPollHandle {
  unreadCount: Ref<number>
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
  const isPolling = ref(false)

  let cancelled = false
  let timeoutHandle: ReturnType<typeof setTimeout> | null = null
  let visibilityListener: (() => void) | null = null

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
  }

  function applyMarkRead(): void {
    unreadCount.value = Math.max(0, unreadCount.value - 1)
  }

  function applyReadAll(): void {
    unreadCount.value = 0
  }

  async function fetchCount(): Promise<void> {
    try {
      const envelope = await notificationsApi.unreadCount()
      if (cancelled) return
      set(envelope.data.attributes.unread_count)
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
    isPolling,
    start,
    cancel,
    refresh,
    set,
    applyMarkRead,
    applyReadAll,
  }
}
