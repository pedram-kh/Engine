/**
 * The board's 30s poll (Sprint 12 Chunk 2, D-4). Cloned from
 * {@link useMessageThread}'s poll discipline: a setTimeout-reschedule loop, an
 * `onBeforeUnmount` cleanup, refs-only, NO localStorage (§5.15).
 *
 * Unlike the messaging poll, this one does NOT own the initial fetch — the
 * BoardView calls `store.load()` on mount, then `start()`s this loop, whose tick
 * calls `store.refresh()` → `store.reconcile()`. The reconcile SKIPS any card in
 * the in-flight-move set (D-4), so a tick firing mid-move can never snap the
 * pending card back. The loop is mounted/unmounted with the board tab
 * (`v-if="tab === 'board'"`, Q3), so there is no background polling on an
 * unviewed tab.
 */

import { getCurrentInstance, onBeforeUnmount } from 'vue'

export const BOARD_POLL_INTERVAL_MS = 30000

export function useBoardPoll(tick: () => Promise<void>) {
  let cancelled = false
  let timer: ReturnType<typeof setTimeout> | null = null

  function clearTimer(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function schedule(): void {
    timer = setTimeout(() => {
      void run()
    }, BOARD_POLL_INTERVAL_MS)
  }

  async function run(): Promise<void> {
    if (cancelled) {
      return
    }
    await tick()
    if (cancelled) {
      return
    }
    schedule()
  }

  /** Begin the loop — the first tick fires at +30s (the initial load is the BoardView's). */
  function start(): void {
    cancelled = false
    clearTimer()
    schedule()
  }

  /** Stop the loop — no further ticks fire (the onBeforeUnmount discipline). */
  function stop(): void {
    cancelled = true
    clearTimer()
  }

  if (getCurrentInstance() !== null) {
    onBeforeUnmount(stop)
  }

  return { start, stop }
}
