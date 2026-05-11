/**
 * Route guards for the admin SPA. Composed via `meta.guards` and
 * dispatched in a single `router.beforeEach` (see
 * `apps/admin/src/core/router/index.ts`).
 *
 * Mirror of `apps/main/src/core/router/guards.ts` (chunks 6.5–6.7) with
 * two admin-specific adaptations for the mandatory-MFA model:
 *
 *   1. `requireAuth`'s `mfaEnrollmentRequired` branch PRESERVES the
 *      intended destination as `?redirect=<fullPath>` on the
 *      `auth.2fa.enable` redirect. Main does NOT preserve it (main
 *      sends `{ name: 'auth.2fa.enable' }` with no query). Justified
 *      because admin's mandatory-MFA spec means EVERY new admin
 *      traverses this redirect; for main the path is rare. Sub-chunk
 *      7.5's `EnableTotpPage` consumes the query after successful
 *      enrolment.
 *
 *   2. Route names mirror main verbatim — `auth.sign-in`,
 *      `auth.2fa.enable`, `app.dashboard` — no `admin.` prefix. Admin
 *      and main are isolated SPAs with no namespace collision; uniform
 *      names make cross-SPA reviews easier.
 *
 * Each guard is a pure async function that takes a `GuardContext` and
 * returns either:
 *   - `null` — the route is allowed to render.
 *   - A `RouteLocationRaw` — redirect the navigation there.
 *
 * Every guard returns the redirect rather than calling Vue Router's
 * `next()` callback so the router's `beforeEach` controls the actual
 * navigation flow. This makes each guard trivially testable in
 * isolation with a fake store — no router instance required (mirror of
 * main's chunk-6.5 design).
 *
 * `bootstrapStatus` enum recap (matches `useAdminAuthStore.ts` chunk-7.2
 * contract — NOT the kickoff's paraphrased
 * `'authenticated'|'anonymous'|'mfa-required'|'error'`): the actual
 * enum is `'idle'|'loading'|'ready'|'error'`. The 200/401/403-mfa
 * paths all resolve to `bootstrapStatus = 'ready'`; the guard
 * discriminates by reading `user` and `mfaEnrollmentRequired` after
 * `bootstrap()` resolves. Only the bootstrap-error path lands at
 * `bootstrapStatus = 'error'`.
 */

import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'

import type { useAdminAuthStore } from '@/modules/auth/stores/useAdminAuthStore'

export type AuthStore = ReturnType<typeof useAdminAuthStore>

export interface GuardContext {
  to: RouteLocationNormalized
  from: RouteLocationNormalized
  store: AuthStore
}

export type GuardResult = RouteLocationRaw | null

/**
 * Authenticated-only routes. Fires `bootstrap()` and waits — the store
 * deduplicates concurrent calls, so racing requireAuth invocations from
 * multiple guard chains collapse to a single `apiClient.me()` call.
 *
 * Branches (in evaluation order):
 *   1. bootstrap error → redirect to `error.auth-bootstrap`, with the
 *      attempted path preserved as `?attempted=...` so the retry button
 *      can deep-link back. Prioritised over MFA + user-null branches
 *      because a transport/parse failure on /me must surface BEFORE we
 *      branch on the parsed state.
 *   2. `mfaEnrollmentRequired === true` → redirect to `auth.2fa.enable`,
 *      preserving the intended destination as `?redirect=<fullPath>`
 *      (admin-specific adaptation, see file docblock). Suppressed
 *      when we are already navigating to `auth.2fa.enable` (avoid a
 *      redirect loop on direct navigation).
 *   3. `user === null` → redirect to `auth.sign-in` with the
 *      intended destination preserved as `?redirect=<fullPath>` so the
 *      sign-in flow can deep-link back after successful auth (+ any
 *      required MFA enrolment).
 *   4. Otherwise → allow (return null).
 */
export async function requireAuth(ctx: GuardContext): Promise<GuardResult> {
  const { store, to } = ctx

  await store.bootstrap()

  if (store.bootstrapStatus === 'error') {
    return {
      name: 'error.auth-bootstrap',
      query: { attempted: to.fullPath },
    }
  }

  if (store.mfaEnrollmentRequired) {
    if (to.name === 'auth.2fa.enable') {
      return null
    }
    return {
      name: 'auth.2fa.enable',
      query: { redirect: to.fullPath },
    }
  }

  if (store.user === null) {
    return {
      name: 'auth.sign-in',
      query: { redirect: to.fullPath },
    }
  }

  return null
}

/**
 * Guest-only routes (sign-in). Authenticated admins get bounced to the
 * dashboard so the back button never strands them on a sign-in form
 * they no longer need.
 *
 * We do NOT call `bootstrap()` here: an admin landing on `/sign-in`
 * cold has no session by definition, and a misfired bootstrap would
 * paint a brief loading state on a page that should render
 * immediately. If a previous navigation already fired bootstrap, we
 * read its result from the store; otherwise we trust the absence of
 * `user` to mean "not signed in" and let the page render.
 */
export async function requireGuest(ctx: GuardContext): Promise<GuardResult> {
  const { store } = ctx

  if (store.user !== null) {
    return { name: 'app.dashboard' }
  }

  return null
}

/**
 * Routes that require the admin to have MFA enrolled. Distinct from
 * `requireAuth`: an admin with no MFA enrolment passes `requireAuth`
 * (via the `mfaEnrollmentRequired` branch's redirect to
 * `auth.2fa.enable`) but a route mounting `requireMfaEnrolled` is the
 * defence-in-depth check for the case where `user` is somehow set
 * (e.g. cache hit before /me revalidates) without `two_factor_enabled`
 * being true. Under normal operation the backend's
 * `EnsureMfaForAdmins` middleware gates `/admin/me` such that a 200
 * response always implies `two_factor_enabled === true`; this guard
 * catches the path where that invariant breaks.
 *
 * Composes safely with `requireAuth` declared earlier in the chain —
 * by the time this runs, `bootstrap()` has already resolved and `user`
 * is populated (or this guard would not have been reached).
 */
export async function requireMfaEnrolled(ctx: GuardContext): Promise<GuardResult> {
  const { store } = ctx

  if (store.user === null) {
    // Defensive: requireAuth should already have caught this. If
    // requireMfaEnrolled was composed without requireAuth ahead of it,
    // fall through to the sign-in redirect rather than crashing.
    return { name: 'auth.sign-in' }
  }

  if (!store.isMfaEnrolled) {
    return { name: 'auth.2fa.enable' }
  }

  return null
}

/**
 * Symbolic guard registry. The router's `beforeEach` resolves
 * `meta.guards: ['requireAuth', 'requireMfaEnrolled']` to these
 * function references and runs them in order.
 */
export const guards: Record<
  'requireAuth' | 'requireGuest' | 'requireMfaEnrolled',
  (ctx: GuardContext) => Promise<GuardResult>
> = {
  requireAuth,
  requireGuest,
  requireMfaEnrolled,
}
