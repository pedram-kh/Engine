/**
 * Admin idle + absolute session-timeout composable (Sprint 13, D-11).
 *
 * The admin console holds the platform's most privileged session, so its
 * timeout is TIGHTER than the main SPA's (`docs/05-SECURITY-COMPLIANCE.md
 * § 6` — admin hardening). Two independent caps run concurrently:
 *
 *   - IDLE (30 min): reset on every observed user activity. A walk-away
 *     console signs itself out after half an hour of inactivity.
 *   - ABSOLUTE (8 h): armed ONCE at mount and NEVER reset by activity. No
 *     matter how busy the admin is, the session cannot outlive 8 hours —
 *     the daily-driver re-auth boundary that bounds a stolen-laptop /
 *     forgotten-tab blast radius.
 *
 * Whichever fires first wins: it calls `useAdminAuthStore.logout()` and
 * redirects to the admin sign-in with `?reason=session_expired`. The
 * redirect is explicit (not delegated to the 401 interceptor) because an
 * idle admin staring at a rendered page issues no requests for the
 * interceptor to catch — mirror of main's chunk-6.5 rationale.
 *
 * This is the FRONTEND half of defence-in-depth; the backend session
 * lifetime (`config/session.php` for the admin cookie) is the
 * authoritative server-side bound. The two are deliberately belt-and-
 * braces: the composable gives the user a clean "you've been signed out"
 * UX before the server cookie lapses.
 */

import { onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'

import { useAdminAuthStore } from '@/modules/auth/stores/useAdminAuthStore'

export const DEFAULT_ADMIN_IDLE_TIMEOUT_MINUTES = 30

export const DEFAULT_ADMIN_ABSOLUTE_TIMEOUT_MINUTES = 8 * 60

export const ACTIVITY_EVENTS: ReadonlyArray<keyof WindowEventMap> = [
  'mousemove',
  'keydown',
  'click',
  'scroll',
  'touchstart',
]

export interface UseIdleTimeoutOptions {
  store?: ReturnType<typeof useAdminAuthStore>
  router?: ReturnType<typeof useRouter>
  onMounted?: typeof onMounted
  onBeforeUnmount?: typeof onBeforeUnmount
  target?: Pick<Window, 'addEventListener' | 'removeEventListener'>
}

export function useIdleTimeout(
  idleMinutes: number = DEFAULT_ADMIN_IDLE_TIMEOUT_MINUTES,
  absoluteMinutes: number = DEFAULT_ADMIN_ABSOLUTE_TIMEOUT_MINUTES,
  options: UseIdleTimeoutOptions = {},
): void {
  /* c8 ignore next 5 -- @preserve: production fallbacks; tests always inject. */
  const store = options.store ?? useAdminAuthStore()
  const router = options.router ?? useRouter()
  const mount = options.onMounted ?? onMounted
  const unmount = options.onBeforeUnmount ?? onBeforeUnmount
  const target = options.target ?? window

  const idleMs = idleMinutes * 60_000
  const absoluteMs = absoluteMinutes * 60_000

  let idleTimer: ReturnType<typeof setTimeout> | null = null
  let absoluteTimer: ReturnType<typeof setTimeout> | null = null
  let fired = false

  const fire = async (): Promise<void> => {
    if (fired) {
      return
    }
    fired = true
    clearIdle()
    clearAbsolute()
    try {
      await store.logout()
    } catch {
      // Logout failure is non-fatal (the session may already be gone);
      // we still redirect so the UI reflects "signed out".
    }
    void router.push({
      name: 'auth.sign-in',
      query: { reason: 'session_expired' },
    })
  }

  const clearIdle = (): void => {
    if (idleTimer !== null) {
      clearTimeout(idleTimer)
      idleTimer = null
    }
  }

  const clearAbsolute = (): void => {
    if (absoluteTimer !== null) {
      clearTimeout(absoluteTimer)
      absoluteTimer = null
    }
  }

  const resetIdle = (): void => {
    clearIdle()
    idleTimer = setTimeout(() => {
      void fire()
    }, idleMs)
  }

  const onActivity = (): void => {
    // Activity bumps the IDLE timer only — never the absolute cap.
    if (!fired) {
      resetIdle()
    }
  }

  mount(() => {
    for (const event of ACTIVITY_EVENTS) {
      target.addEventListener(event, onActivity, { passive: true })
    }
    resetIdle()
    absoluteTimer = setTimeout(() => {
      void fire()
    }, absoluteMs)
  })

  unmount(() => {
    for (const event of ACTIVITY_EVENTS) {
      target.removeEventListener(event, onActivity)
    }
    clearIdle()
    clearAbsolute()
  })
}
