/**
 * What a tap on a push notification does (AH-102).
 *
 * A tap that cold-starts the app is delivered to the listener the moment it is
 * registered, which is BEFORE the router has finished its first navigation and
 * before the session is restored. A `push` issued at that instant is overtaken
 * by the initial navigation (to the dashboard) and the tap's destination is
 * lost: the device walk (E3 item 4, from killed) landed on the dashboard.
 *
 * So the destination is only pushed once the router has settled. From the
 * background the router is already ready, so the push is immediate.
 *
 * The router is passed in (structurally typed) because only the boot wiring in
 * `main.ts` may touch it; this module stays free of router imports.
 */
export interface TapRouter {
  isReady: () => Promise<void>
  push: (to: { name: string }) => unknown
}

/** The route a push tap opens. Per-type destinations are AH-103. */
export const PUSH_TAP_ROUTE_NAME = 'creator.notifications'

export function createPushTapHandler(router: TapRouter): () => void {
  return () => {
    void router
      .isReady()
      .then(() => router.push({ name: PUSH_TAP_ROUTE_NAME }))
      .catch(() => undefined)
  }
}
