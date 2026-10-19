import { APP_LINK_DASHBOARD_ROUTE, resolveAppLink, type LinkResolver } from './appLink'

/**
 * What a tap on a creator link does (AH-103b). Same shape as the push-tap
 * handler (`core/platform/push/pushTapRouting.ts`), for the same reasons:
 *
 *   1. `await router.isReady()` — a cold-start link is delivered before the
 *      router has finished its first navigation (and before the session is
 *      restored); a `push` issued at that instant is overtaken by the initial
 *      navigation and the destination is lost (the AH-102 / AH-103 lesson).
 *   2. Resolve against the real route table (`appLink.ts`): a creator screen,
 *      the dashboard for an in-scope link that cannot be used (D2), or nothing
 *      for a link that is not ours.
 *   3. `router.push(...)`. The WHOLE chain is wrapped: it can never throw, and
 *      if pushing a resolved screen fails the dashboard is pushed instead, so
 *      a link never leaves the user on a blank screen.
 *
 * Signed out needs no code here: the destination's `requireAuth` guard sends
 * the user to `/sign-in?redirect=<fullPath>` and sign-in honours it, so the
 * user lands on the link's screen after signing in (R5). A spec drives the
 * real guard to pin it.
 *
 * A NEWER link supersedes an older one still waiting on the router. And an
 * identical URL within {@link APP_LINK_DUPLICATE_WINDOW_MS} of the last one is
 * dropped (D3): on a cold start Capacitor can deliver the same link both as an
 * `appUrlOpen` event and through `getLaunchUrl()`.
 *
 * The router is passed in (structurally typed) because only the boot wiring in
 * `main.ts` may touch the singleton; this module imports it nowhere.
 */
export interface AppLinkRouter extends LinkResolver {
  isReady(): Promise<void>
  push(to: string | { name: string }): unknown
}

/** An identical link closer than this to the previous one is the same delivery. */
export const APP_LINK_DUPLICATE_WINDOW_MS = 3_000

export interface AppLinkHandlerOptions {
  /** Clock, for the duplicate guard. */
  now?: () => number
}

export function createAppLinkHandler(
  router: AppLinkRouter,
  options: AppLinkHandlerOptions = {},
): (url: string) => void {
  const { now = Date.now } = options

  let latest = 0
  let lastUrl: string | null = null
  let lastAt = Number.NEGATIVE_INFINITY

  const dashboard = { name: APP_LINK_DASHBOARD_ROUTE }

  const navigate = async (to: string | { name: string }): Promise<void> => {
    try {
      await router.push(to)
    } catch {
      if (typeof to === 'string') {
        try {
          await router.push(dashboard)
        } catch {
          // Nothing more to try.
        }
      }
    }
  }

  const run = async (url: string, mine: number): Promise<void> => {
    await router.isReady()

    // Superseded while waiting for the router: the newer link owns the screen.
    if (mine !== latest) {
      return
    }

    const resolution = resolveAppLink(router, url)

    if (resolution.kind === 'ignore') {
      return
    }

    await navigate(resolution.kind === 'route' ? resolution.fullPath : dashboard)
  }

  return (url) => {
    const at = now()

    if (url === lastUrl && at - lastAt < APP_LINK_DUPLICATE_WINDOW_MS) {
      return
    }

    lastUrl = url
    lastAt = at
    latest += 1
    void run(url, latest).catch(() => undefined)
  }
}
