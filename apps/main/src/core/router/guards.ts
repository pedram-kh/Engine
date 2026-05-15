/**
 * Route guards composed via `meta.guards` and dispatched in a single
 * `router.beforeEach` (see `apps/main/src/core/router/index.ts`).
 *
 * Each guard is a pure async function that takes a `GuardContext` and
 * returns either:
 *   - `null` — the route is allowed to render.
 *   - A `RouteLocationRaw` — redirect the navigation there.
 *
 * Every guard returns the redirect rather than calling the Vue Router
 * `next()` callback so the router's `beforeEach` controls the actual
 * navigation flow. This makes each guard trivially testable in isolation
 * with a mocked store — no router instance required.
 *
 * Pre-answered chunk-6.5 Q1 specifies four terminal `bootstrapStatus`
 * states that `requireAuth` MUST distinguish:
 *   - `200`         → render the route.
 *   - `401`         → redirect to `auth.sign-in`.
 *   - `403 mfa-required` → redirect to `auth.2fa.enable`.
 *   - `'error'`     → redirect to `error.auth-bootstrap`.
 *
 * The store collapses the 200 / 401 paths to `bootstrapStatus = 'ready'`
 * (with `user === null` for 401), so this guard discriminates by reading
 * `user` and `mfaEnrollmentRequired` after `bootstrap()` resolves.
 */

import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'

import { useAgencyStore } from '@/core/stores/useAgencyStore'
import type { useAuthStore } from '@/modules/auth/stores/useAuthStore'

export type AuthStore = ReturnType<typeof useAuthStore>

export interface GuardContext {
  to: RouteLocationNormalized
  from: RouteLocationNormalized
  store: AuthStore
}

export type GuardResult = RouteLocationRaw | null

/**
 * Authenticated-only routes. Fires `bootstrap()` and waits — the store
 * deduplicates concurrent calls, so racing requireAuth invocations from
 * multiple guard chains collapse to a single `me()` call.
 *
 * Branches:
 *   - 200 + user → allow.
 *   - 401 (user === null) → redirect to `auth.sign-in`.
 *   - 403 mfa-enrollment-required → redirect to `auth.2fa.enable`,
 *     unless we ARE already navigating to `auth.2fa.enable` (avoid a
 *     redirect loop).
 *   - bootstrap error → redirect to `error.auth-bootstrap`, with the
 *     attempted path preserved as `?attempted=...` so the retry button
 *     can deep-link back.
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
    return { name: 'auth.2fa.enable' }
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
 * Guest-only routes (sign-in / sign-up). Authenticated users get bounced
 * to the dashboard so the back button never strands them on a sign-in
 * form they no longer need.
 *
 * We do NOT call `bootstrap()` here: the user landing on `/sign-in`
 * cold has no session by definition, and a misfired bootstrap would
 * paint a brief loading state on a page that should render immediately.
 * If a previous navigation already fired bootstrap, we read its result
 * from the store; otherwise we trust the absence of `user` to mean
 * "not signed in" and let the page render.
 */
export async function requireGuest(ctx: GuardContext): Promise<GuardResult> {
  const { store } = ctx

  if (store.user !== null) {
    return { name: 'app.dashboard' }
  }

  return null
}

/**
 * Routes that require the user to have MFA enrolled. Distinct from
 * `requireAuth`: a user with no MFA enrolment passes `requireAuth` but
 * fails this guard and is bounced to the enable-2fa page.
 *
 * Composes safely with `requireAuth` declared earlier in the chain — by
 * the time this runs, `bootstrap()` has already resolved and `user`
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
/**
 * Requires the current user to have agency_admin role in the active agency.
 * Redirects to /brands (the agency home) if they don't.
 *
 * Note: called AFTER requireAuth, so `store.user` is non-null here.
 */
export async function requireAgencyAdmin(_ctx: GuardContext): Promise<GuardResult> {
  const agencyStore = useAgencyStore()
  if (!agencyStore.isAdmin) {
    return { name: 'brands.list' }
  }
  return null
}

/**
 * Wizard-route guard (Sprint 3 Chunk 3 sub-step 2).
 *
 * Allows the user to enter the onboarding wizard ONLY when both
 * conditions hold:
 *
 *   1. `user.user_type === 'creator'` — non-creator types (agency
 *      users, platform admins) have no creator profile and would
 *      bounce off the bootstrap 404. They get redirected to the
 *      agency dashboard (their natural home) instead.
 *
 *   2. `application_status === 'incomplete'` — submitted / approved /
 *      rejected creators have either finished onboarding (no wizard
 *      to return to) or need the rejection-feedback surface
 *      (`/creator/dashboard`). They redirect to the creator
 *      dashboard.
 *
 * Composes safely AFTER `requireAuth`. By the time this runs,
 * `bootstrap()` has resolved and `user` is populated.
 *
 * Defense-in-depth (#40, Sprint 2 § 5.17): Vitest unit covers:
 *   (1) allow path (creator + incomplete),
 *   (2) deny non-creator path,
 *   (3) deny submitted/approved/rejected path,
 *   (4) registration in guards registry.
 * The break-revert: temporarily removing this guard from a wizard
 * route fails the no-unauthorized-access spec.
 */
export async function requireOnboardingAccess(ctx: GuardContext): Promise<GuardResult> {
  const { store } = ctx

  if (store.user === null) {
    // Defensive: requireAuth ahead of us should have caught this. If
    // requireOnboardingAccess was composed without requireAuth, fall
    // through to the sign-in redirect rather than crashing.
    return { name: 'auth.sign-in' }
  }

  if (store.user.attributes.user_type !== 'creator') {
    return { name: 'app.dashboard' }
  }

  // Lazy-load to avoid eager Pinia coupling — the onboarding store is
  // only constructed when a creator user reaches this guard.
  const { useOnboardingStore } = await import('@/modules/onboarding/stores/useOnboardingStore')
  const onboardingStore = useOnboardingStore()

  // Drive a bootstrap so we have authoritative application_status.
  // The store dedupes concurrent calls so racing guard invocations
  // collapse to a single backend call.
  await onboardingStore.bootstrap()

  const status = onboardingStore.applicationStatus
  if (status !== null && status !== 'incomplete') {
    return { name: 'creator.dashboard' }
  }

  return null
}

export const guards: Record<
  | 'requireAuth'
  | 'requireGuest'
  | 'requireMfaEnrolled'
  | 'requireAgencyAdmin'
  | 'requireOnboardingAccess',
  (ctx: GuardContext) => Promise<GuardResult>
> = {
  requireAuth,
  requireGuest,
  requireMfaEnrolled,
  requireAgencyAdmin,
  requireOnboardingAccess,
}
