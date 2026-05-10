/**
 * Idle-timeout composable.
 *
 * After `durationMinutes` of no observed user activity, calls
 * `useAuthStore.logout()` and pushes the user to the sign-in page with
 * `?reason=session_expired`. Default duration is 30 minutes per
 * `docs/05-SECURITY-COMPLIANCE.md § 6`.
 *
 * The composable explicitly redirects (rather than relying on the 401
 * interceptor in `core/api/index.ts` to do it) because an idle user on a
 * fully-loaded page may not be issuing any HTTP requests at the moment
 * the timer fires. Without an explicit `router.push` here, the user
 * would remain on an authenticated page with a backend session that no
 * longer exists — the next interaction would surface a confusing 401
 * mid-action rather than the intended "you've been signed out" banner.
 *
 * The pre-answered chunk-6.5 Q1 said the composable could "let the 401
 * interceptor handle the redirect". That hidden assumption — that an
 * idle user is also issuing a backend request — does not hold for an
 * idle user staring at a fully-rendered page. This deviation is flagged
 * in the chunk-6.5 review file under "Open questions / deviations".
 *
 * Activity events tracked: `mousemove`, `keydown`, `click`, `scroll`,
 * `touchstart`. Listeners are attached on the window in `onMounted` and
 * detached in `onBeforeUnmount` — chunk-6.5 review priority #3 calls
 * out the memory-leak guard.
 */

import { onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

export const DEFAULT_IDLE_TIMEOUT_MINUTES = 30

export const ACTIVITY_EVENTS: ReadonlyArray<keyof WindowEventMap> = [
  'mousemove',
  'keydown',
  'click',
  'scroll',
  'touchstart',
]

export interface UseIdleTimeoutOptions {
  /**
   * Override the auth store. Tests pass a fake; production calls
   * `useAuthStore()` from this composable's module scope.
   */
  store?: ReturnType<typeof useAuthStore>
  /**
   * Override the router. Tests pass a fake; production resolves via
   * `useRouter()`.
   */
  router?: ReturnType<typeof useRouter>
  /**
   * Override the lifecycle hooks (Vue's `onMounted` / `onBeforeUnmount`).
   * Tests inject synchronous fakes so the timer + listeners can be
   * exercised without mounting a component.
   */
  onMounted?: typeof onMounted
  onBeforeUnmount?: typeof onBeforeUnmount
  /**
   * Override the global object the listeners are attached to. Defaults
   * to `window`. Tests pass a freshly-instantiated `EventTarget` so
   * cross-test bleed is impossible.
   */
  target?: Pick<Window, 'addEventListener' | 'removeEventListener'>
}

export function useIdleTimeout(
  durationMinutes: number = DEFAULT_IDLE_TIMEOUT_MINUTES,
  options: UseIdleTimeoutOptions = {},
): void {
  /* c8 ignore next 5 -- @preserve: production fallbacks; tests always inject. */
  const store = options.store ?? useAuthStore()
  const router = options.router ?? useRouter()
  const mount = options.onMounted ?? onMounted
  const unmount = options.onBeforeUnmount ?? onBeforeUnmount
  const target = options.target ?? window

  const durationMs = durationMinutes * 60_000

  let timer: ReturnType<typeof setTimeout> | null = null

  const fire = async (): Promise<void> => {
    timer = null
    try {
      await store.logout()
    } catch {
      // Logout failure is non-fatal: the session may already be gone.
      // We still want to redirect the user to the sign-in page so the
      // UI accurately reflects "you are signed out". The store's own
      // logout() already swallows 401s; any other failure is silently
      // dropped here so the redirect always happens.
    }
    void router.push({
      name: 'auth.sign-in',
      query: { reason: 'session_expired' },
    })
  }

  const reset = (): void => {
    if (timer !== null) {
      clearTimeout(timer)
    }
    timer = setTimeout(() => {
      void fire()
    }, durationMs)
  }

  const onActivity = (): void => {
    reset()
  }

  mount(() => {
    for (const event of ACTIVITY_EVENTS) {
      target.addEventListener(event, onActivity, { passive: true })
    }
    reset()
  })

  unmount(() => {
    for (const event of ACTIVITY_EVENTS) {
      target.removeEventListener(event, onActivity)
    }
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  })
}
