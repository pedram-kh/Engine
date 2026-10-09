import { getCurrentScope, onScopeDispose, watch } from 'vue'
import type { Ref } from 'vue'

/** How often the under-review page asks whether an admin has decided. */
export const APPLICATION_STATUS_POLL_MS = 30_000

export interface ApplicationStatusPollOptions {
  /** The poll runs only while this is true (the page is in the pending branch). */
  active: Readonly<Ref<boolean>>
  /** The silent re-read (`useOnboardingStore().refresh`). */
  refresh: () => Promise<void>
  intervalMs?: number
  /** Injectable for the spec; defaults to the real document. */
  isHidden?: () => boolean
}

/**
 * AH-100d (item 8, layer b) — re-poll the application status while the
 * under-review page is on screen, so an admin's approval flips the page live
 * instead of waiting for a logout/login.
 *
 * It is deliberately small and polite:
 *   - starts only while `active` (pending) and stops the moment it is not, so
 *     the poll ends itself when the status flips;
 *   - stops on unmount / scope disposal (no leaked timer after leaving the page);
 *   - skips a tick while the document is hidden (a backgrounded WebView) — the
 *     app-resume listener covers the moment it comes back;
 *   - never overlaps itself (the store also shares one in-flight request);
 *   - swallows failures: a missed tick is retried on the next one.
 *
 * The proactive version of this is AH-102's push (approval joins the
 * push-worthy set). The web page does not poll — a named follow-up.
 */
export function useApplicationStatusPoll(options: ApplicationStatusPollOptions): void {
  const {
    active,
    refresh,
    intervalMs = APPLICATION_STATUS_POLL_MS,
    isHidden = () => typeof document !== 'undefined' && document.hidden,
  } = options

  let timer: ReturnType<typeof setInterval> | null = null
  let running = false

  function stop(): void {
    if (timer !== null) {
      clearInterval(timer)
      timer = null
    }
  }

  async function tick(): Promise<void> {
    if (running || isHidden()) {
      return
    }
    running = true
    try {
      await refresh()
    } catch {
      // A missed tick is retried by the next one.
    } finally {
      running = false
    }
  }

  function start(): void {
    if (timer === null) {
      timer = setInterval(() => void tick(), intervalMs)
    }
  }

  watch(
    active,
    (isActive) => {
      if (isActive) {
        start()
      } else {
        stop()
      }
    },
    { immediate: true },
  )

  if (getCurrentScope() !== undefined) {
    onScopeDispose(stop)
  }
}
