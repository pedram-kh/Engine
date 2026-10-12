/**
 * What a tap on a push notification does (AH-102, per-type destinations AH-103).
 *
 * A tap that cold-starts the app is delivered to the listener the moment it is
 * registered, which is BEFORE the router has finished its first navigation and
 * before the session is restored. A `push` issued at that instant is overtaken
 * by the initial navigation (to the dashboard) and the tap's destination is
 * lost: the device walk (E3 item 4, from killed) landed on the dashboard. The
 * AH-103 device spike saw the same order on both OSes (route `/` at the instant
 * of the tap), with the tap's `data` already present — so the gate below waits
 * for the router, not for the data.
 *
 * So the destination is only pushed once the router has settled. From the
 * background the router is already ready, so the push is immediate.
 *
 * The steps, in order, and what each guarantees:
 *   1. `await router.isReady()`.
 *   2. `deps.resolveTap(data)` — parse the payload and resolve it (pure; it
 *      never rejects, and a thrown error here is treated as "no destination").
 *   3. `router.push(destination)`, or the notification list when there is none.
 *      The WHOLE chain is wrapped: it can never throw, and if pushing a resolved
 *      destination fails the list is pushed instead — a tap never leaves the user
 *      on a blank screen.
 *   4. Mark the notification read — ONLY after the navigation has settled, ONLY
 *      if the user landed somewhere real (not the sign-in or bootstrap-error
 *      screen) and a session exists. Marking read before sign-in would send an
 *      unauthenticated PATCH into the native 401 policy (`core/api/index.ts`),
 *      which would redirect the user away from the destination they just chose.
 *
 * A SECOND tap while the first is still resolving supersedes it: the stale one
 * is dropped before it can navigate over the newer one.
 *
 * The router and every singleton are passed in (structurally typed) because only
 * the boot wiring in `main.ts` may touch them; this module imports neither.
 */
export interface TapRouter {
  isReady: () => Promise<void>
  push: (to: TapRoute) => unknown
  /** `router.currentRoute` — read after the push settles, to see where the user landed. */
  currentRoute: { value: { name?: string | symbol | null } }
}

export interface TapRoute {
  name: string
  params?: Record<string, string>
  query?: Record<string, string>
}

/** What resolving a tap's payload produced. */
export interface TapResolution {
  /** The destination, or `null` for "open the list". */
  to: TapRoute | null
  /** The in-app row to mark read once the user has landed. Absent when the in-app row is muted. */
  notificationUlid?: string
}

export interface PushTapDeps {
  resolveTap: (data: unknown) => Promise<TapResolution>
  /** True while a user session exists (read AFTER the navigation settles). */
  hasSession: () => boolean
  markRead: (notificationUlid: string) => Promise<unknown>
  /** Called after a successful mark-read, so the bell re-reads its count. */
  onMarkedRead: () => void
}

/** The route a push tap opens when nothing more specific resolves. */
export const PUSH_TAP_ROUTE_NAME = 'creator.notifications'

/** Routes where the user has NOT landed on what they asked for (no mark-read from there). */
const NOT_LANDED_ROUTE_NAMES: readonly string[] = ['auth.sign-in', 'error.auth-bootstrap']

export function createPushTapHandler(
  router: TapRouter,
  deps: PushTapDeps,
): (data: unknown) => void {
  let latest = 0

  const run = async (data: unknown, mine: number): Promise<void> => {
    await router.isReady()

    let resolution: TapResolution
    try {
      resolution = await deps.resolveTap(data)
    } catch {
      resolution = { to: null }
    }

    // Superseded while resolving (a lookup is a network call): the newer tap owns the screen.
    if (mine !== latest) {
      return
    }

    await navigate(resolution.to)

    if (mine !== latest || resolution.notificationUlid === undefined) {
      return
    }

    const landed = router.currentRoute.value.name
    if (
      !deps.hasSession() ||
      (typeof landed === 'string' && NOT_LANDED_ROUTE_NAMES.includes(landed))
    ) {
      return
    }

    try {
      await deps.markRead(resolution.notificationUlid)
      deps.onMarkedRead()
    } catch {
      // A 404 (row deleted) or any failure: the tap already did its job.
    }
  }

  const navigate = async (to: TapRoute | null): Promise<void> => {
    try {
      await router.push(to ?? { name: PUSH_TAP_ROUTE_NAME })
    } catch {
      if (to !== null) {
        try {
          await router.push({ name: PUSH_TAP_ROUTE_NAME })
        } catch {
          // Nothing more to try.
        }
      }
    }
  }

  return (data) => {
    latest += 1
    void run(data, latest).catch(() => undefined)
  }
}
