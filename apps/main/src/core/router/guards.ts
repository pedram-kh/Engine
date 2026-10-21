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
 * PRECONDITION gate: this page only makes sense for an enrolled user.
 *
 * Its one caller is `auth.2fa.disable` — you cannot disable 2FA you never
 * set up. This is NOT the policy gate, and it deliberately does not know
 * about exemptions (AH-087): an exempt, un-enrolled user has nothing to
 * disable, so sending them to the disable page would be incoherent. The
 * policy gate is `requireMfaUnlessExempt` below.
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
 * POLICY gate (AH-087): the admin-sensitive agency routes require 2FA
 * enrollment UNLESS a platform admin has exempted this user.
 *
 * Split out of `requireMfaEnrolled` because that guard was doing two
 * different jobs — a policy gate on the admin-sensitive routes and a
 * precondition gate on the self-service disable page — and only the policy
 * half can honour an exemption. Branching on the route name inside one
 * guard was rejected: a per-user data field is available, and route-name
 * branching inside a security gate hides the distinction it is making.
 *
 * INERTNESS (the AH-087 review's first priority): `mfa_exempt` is the
 * `users.mfa_exempted_at !== null` projection, and that column is NULL for
 * every user who has not been explicitly exempted — no backfill, no
 * default flip. So for the entire existing population this guard evaluates
 * `!isMfaEnrolled && !isMfaExempt`, which is exactly what
 * `requireMfaEnrolled` evaluated before: same redirect, same target, same
 * order. The exemption branch can only fire for a user an admin acted on.
 *
 * This gate is CLIENT-SIDE ONLY, and so is the requirement it enforces:
 * no server-side middleware has ever required 2FA enrollment on an agency
 * route (only the admin guard's `EnsureMfaForAdmins` does, for platform
 * admins, unconditionally and without consulting exemptions). Recorded in
 * docs/tech-debt.md — before MFA is presented to agencies or compliance as
 * a security control, the server-side gate must ship and this branch moves
 * into it.
 */
export async function requireMfaUnlessExempt(ctx: GuardContext): Promise<GuardResult> {
  const { store } = ctx

  if (store.user === null) {
    // Defensive: same posture as requireMfaEnrolled — fall through to
    // sign-in rather than crashing if composed without requireAuth.
    return { name: 'auth.sign-in' }
  }

  if (store.isMfaExempt) {
    return null
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

/**
 * Agency-shell guard (Sprint 6 Chunk 1, D-7). The inverse of
 * `requireOnboardingAccess`: that guard bounces non-creators OUT of the
 * wizard; this one bounces creators OUT of the agency shell.
 *
 * Closes the standing gap (tech-debt: "Defensive requireAgencyUser guard"):
 * `requireAuth` alone does not inspect `user_type`, so a creator who knows the
 * URLs (or whose browser autocompletes `/brands` from history) could render
 * `BrandListPage` / `CreatorRosterPage` / etc. behind `requireAuth`. The
 * backend is the SOT for authorization (every mutation 403s for a creator), so
 * this is a UX/mental-model polish, not a security fix — but a creator should
 * never see the agency chrome they don't belong in.
 *
 * The bounce signal is "a `creator`-type user who holds NO agency membership".
 * `user_type` alone is NOT sufficient: an agency teammate signs up through the
 * public form (which stamps `user_type = 'creator'`) and then accepts an agency
 * invitation, which adds an `AgencyMembership` WITHOUT flipping `user_type`
 * (see `AgencyInvitationService::accept` + `SignUpService`). Such a user is a
 * legitimate agency member and must reach the shell — so we additionally
 * require an empty membership list before bouncing. The authoritative
 * membership list lives on `useAgencyStore` (seeded from the `/me` payload
 * during `requireAuth`'s bootstrap, the same store `requireAgencyAdmin` reads).
 *
 * Bounced users go to `onboarding.welcome-back` (their natural home, which
 * itself re-dispatches via `requireOnboardingAccess` to the right wizard step
 * or the creator dashboard). Every NON-creator user_type (agency_user,
 * platform_admin, brand_user) falls through unconditionally — bouncing a
 * non-creator into `onboarding.welcome-back` would ping-pong against
 * `requireOnboardingAccess` (which bounces non-creators back to the dashboard).
 *
 * Composes AFTER `requireAuth` (it assumes a resolved user + a bootstrapped
 * agency store) and BEFORE any `requireMfaEnrolled` / `requireAgencyAdmin` — a
 * membership-less creator is bounced before those checks can leak anything
 * about the page.
 *
 * Defense-in-depth (#40, Sprint 2 § 5.17): the unit suite covers the
 * creator-without-membership redirect, the creator-WITH-membership pass-through,
 * each non-creator fall-through, the defensive no-user case, and registry
 * registration; the route-walking arch-test
 * (`agency-routes-agency-user-guard.spec.ts`) asserts every agency-shell route
 * actually carries the guard.
 */
export async function requireAgencyUser(ctx: GuardContext): Promise<GuardResult> {
  const { store } = ctx

  if (store.user === null) {
    // Defensive: requireAuth ahead of us should have caught this. Fall through
    // to sign-in rather than crashing if composed without requireAuth.
    return { name: 'auth.sign-in' }
  }

  if (store.user.attributes.user_type !== 'creator') {
    return null
  }

  // A creator who belongs to at least one agency (e.g. signed up via the public
  // form, then accepted an agency invitation) is a real teammate — let them in.
  const agencyStore = useAgencyStore()
  if (agencyStore.memberships.length > 0) {
    return null
  }

  return { name: 'onboarding.welcome-back' }
}

/**
 * AH-104b-del B4 — the Security page (delete account) exists only while the flag is on.
 *
 * The server resolves it per user into `attributes.features.account_deletion` (true only for
 * a creator with `account_deletion_enabled` active), so the route, the avatar-menu entry and
 * the native More entry all read the same value. With the flag off the route does not render:
 * the creator goes to the dashboard, the same place a missing page would send them, and the
 * API itself answers 404 to the request.
 *
 * Composes AFTER `requireAuth` (a resolved user is assumed; none falls through to sign-in).
 */
export async function requireAccountDeletionEnabled(ctx: GuardContext): Promise<GuardResult> {
  const { store } = ctx

  if (store.user === null) {
    return { name: 'auth.sign-in' }
  }

  if (store.user.attributes.features?.account_deletion === true) {
    return null
  }

  return { name: 'creator.dashboard' }
}

export const guards: Record<
  | 'requireAuth'
  | 'requireGuest'
  | 'requireMfaEnrolled'
  | 'requireMfaUnlessExempt'
  | 'requireAgencyAdmin'
  | 'requireOnboardingAccess'
  | 'requireAgencyUser'
  | 'requireAccountDeletionEnabled',
  (ctx: GuardContext) => Promise<GuardResult>
> = {
  requireAuth,
  requireGuest,
  requireMfaEnrolled,
  requireMfaUnlessExempt,
  requireAgencyAdmin,
  requireOnboardingAccess,
  requireAgencyUser,
  requireAccountDeletionEnabled,
}
