/**
 * Tab-scoped Decision-B flag for the Welcome Back UX (Sprint 3
 * Chunk 3 sub-step 2, Refinement 1).
 *
 * Lives outside the Pinia store + outside the component setup so:
 *   - It survives component unmount/remount within the same SPA
 *     tab lifetime (the desired "did we already show Welcome Back
 *     once this tab" semantic).
 *   - It re-initialises to `false` on hard refresh (module
 *     re-evaluation), which is exactly the "fresh tab" semantic
 *     Decision B wants for the welcome-vs-auto-advance branch.
 *
 * Why not the Pinia store's `wasBootstrappedThisSession` flag?
 *   The store's flag flips INSIDE `bootstrap()` — by the time the
 *   `WelcomeBackPage` mounts, the router guard has already
 *   awaited bootstrap, so the flag would always read `true`. The
 *   flag exists for tests + future audit; the actual Decision-B
 *   branch needs a flag set AT MOUNT-time, which is what this
 *   module-scoped boolean provides.
 *
 * Defense-in-depth (#40) break-revert: temporarily defaulting
 * `priorBootstrap` to `true` makes the "fresh-load → Welcome Back"
 * spec fail (auto-advance fires on first mount), which is the
 * regression mode this signal exists to catch.
 */

let priorBootstrap = false

export function hasMountedBefore(): boolean {
  return priorBootstrap
}

export function markMounted(): void {
  priorBootstrap = true
}

/**
 * Reset the module-scoped flag back to its "fresh page load" state.
 *
 * Called in production from `useAuthStore.clearUser()` so that a
 * sign-out → sign-in cycle within the SAME tab re-shows the landing
 * page for the next session. Without this, the flag stayed `true`
 * for the tab's whole lifetime and the second login auto-advanced
 * straight past the Welcome / Let's-get-started screen (the SPA does
 * an in-tab `router.push` on logout, not a hard reload, so the module
 * never re-evaluated). Tab-scoped persistence is still the right
 * behaviour WITHIN a single authenticated session (navigating back
 * to `/onboarding` mid-session auto-advances) — this reset only fires
 * when the session itself is torn down.
 *
 * Also used by the Vitest harness in `beforeEach` to give each spec a
 * clean starting state.
 */
export function resetWelcomeBackFlag(): void {
  priorBootstrap = false
}
