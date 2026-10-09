import { isNativePlatform } from './isNativePlatform'

/**
 * AH-100d (item 8) — refresh the session state when the creator app comes
 * back to the foreground.
 *
 * Why: nothing in the app re-reads server state after the first load. A
 * creator submits the wizard, an admin approves them, and the app — which
 * people never sign out of — keeps showing "under review" until a manual
 * logout/login forces a fresh `/me` + bootstrap. The proactive fix is the push
 * notification (AH-102); this is the baseline that works with no push at all:
 * opening the app is itself the signal to ask the server what changed.
 *
 * Native only. The browser has no equivalent lifecycle (a tab is not
 * "resumed"), so on the web `startAppResumeRefresh` is a no-op and no
 * listener is ever added.
 */

/** Subscribe to "the app became active"; resolves to the unsubscribe. */
export type ResumeSubscribe = (onActive: () => void) => Promise<() => void>

/* c8 ignore start -- the Capacitor bridge call; exercised on device (the
   AH-100d walkthrough), injected as a fake in the spec. */
const capacitorResumeSource: ResumeSubscribe = async (onActive) => {
  const { App } = await import('@capacitor/app')
  const handle = await App.addListener('appStateChange', ({ isActive }) => {
    if (isActive) {
      onActive()
    }
  })
  return () => {
    void handle.remove()
  }
}
/* c8 ignore stop */

/**
 * Refresh the held session: `/me` first, then — for a creator who is still
 * signed in afterwards — the onboarding payload that carries the application
 * status. Stores are imported lazily (the same shape as the router guards) so
 * this module stays free of module-level store imports.
 *
 * Signed-out is a no-op: resuming onto the sign-in screen must not call
 * anything. A 401 on `/me` (the token died while the app slept) clears the
 * user inside `refreshSession()`; `/me` is exempt from the global 401 policy,
 * so the caller's `onSessionLapsed` (main.ts: the redirect to sign-in with the
 * same "session expired" reason that policy uses) runs instead, and bootstrap
 * is never fetched with a dead token. The redirect is injected, not imported:
 * only the wiring layer may touch the router singleton.
 */
export async function refreshSessionState(onSessionLapsed: () => void): Promise<void> {
  const { useAuthStore } = await import('@/modules/auth/stores/useAuthStore')
  const auth = useAuthStore()
  if (auth.user === null) {
    return
  }

  await auth.refreshSession()
  if (auth.user === null) {
    // Lapsed (the 401 above) vs. a deliberate sign-out that landed while the
    // request was in flight: only the former is "your session expired".
    if (auth.sessionLapsed) {
      onSessionLapsed()
    }
    return
  }
  if (auth.user.attributes.user_type !== 'creator') {
    return
  }

  const { useOnboardingStore } = await import('@/modules/onboarding/stores/useOnboardingStore')
  await useOnboardingStore().refresh()
}

/** Two foreground events closer than this are one resume (Face ID prompts,
 *  the share sheet and permission dialogs all flip the app inactive→active). */
export const RESUME_MIN_INTERVAL_MS = 5_000

export interface AppResumeOptions {
  /** What to run on resume. Defaults to {@link refreshSessionState}. */
  refresh?: () => Promise<void>
  /** Called when the refresh finds the session dead (token revoked/expired). */
  onSessionLapsed?: () => void
  /** The lifecycle source. Defaults to the Capacitor `appStateChange` event. */
  subscribe?: ResumeSubscribe
  /** Native gate; injectable so the web polarity is specced without Capacitor. */
  isNative?: () => boolean
  /** Clock, for the throttle. */
  now?: () => number
}

/**
 * Start the resume listener. Returns a stop function (always callable, a
 * no-op when nothing was started). A failing refresh never surfaces: a
 * background refresh that cannot reach the server is not an error state, and
 * the next resume retries.
 */
export function startAppResumeRefresh(options: AppResumeOptions = {}): () => void {
  const {
    onSessionLapsed = () => undefined,
    refresh = () => refreshSessionState(onSessionLapsed),
    subscribe = capacitorResumeSource,
    isNative = isNativePlatform,
    now = Date.now,
  } = options

  if (!isNative()) {
    return () => undefined
  }

  let lastRun = Number.NEGATIVE_INFINITY
  let stopped = false
  let unsubscribe: (() => void) | null = null

  const onActive = (): void => {
    const at = now()
    if (stopped || at - lastRun < RESUME_MIN_INTERVAL_MS) {
      return
    }
    lastRun = at
    refresh().catch(() => undefined)
  }

  subscribe(onActive)
    .then((off) => {
      if (stopped) {
        off()
        return
      }
      unsubscribe = off
    })
    .catch(() => undefined)

  return () => {
    stopped = true
    unsubscribe?.()
    unsubscribe = null
  }
}
