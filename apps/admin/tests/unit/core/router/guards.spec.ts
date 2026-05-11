/**
 * Unit tests for the route guards in
 * `apps/admin/src/core/router/guards.ts`.
 *
 * Mirror of `apps/main/tests/unit/core/router/guards.spec.ts` (chunk
 * 6.5) with two admin-specific cases:
 *
 *   1. `requireAuth`'s `mfaEnrollmentRequired` branch PRESERVES the
 *      intended destination as `?redirect=<fullPath>` on the
 *      `auth.2fa.enable` redirect. Main does NOT preserve it; admin
 *      diverges here per the kickoff's mandatory-MFA adaptation. The
 *      `redirects to auth.2fa.enable when mfaEnrollmentRequired is
 *      true` case asserts on the query shape.
 *
 *   2. `app.settings` is gated by `requireMfaEnrolled` in admin's
 *      route table (whereas main only gates `app.dashboard`). The
 *      guard tests are unaffected by this — the guard tests focus on
 *      branch coverage, not on which routes mount the guard. The
 *      routes-level decision lands in
 *      `apps/admin/src/modules/auth/routes.ts`.
 *
 * Each guard is tested against a hand-rolled minimal store stub — we
 * deliberately avoid spinning the real Pinia store here so the tests
 * stay focused on the guard's branching logic and not on the store's
 * bootstrap state machine (covered separately in
 * `useAdminAuthStore.spec.ts`).
 *
 * Coverage requirement: 100% lines / branches / functions / statements
 * (auth-flow gate per `docs/02-CONVENTIONS.md § 4.3`).
 */

import type { RouteLocationNormalized } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'

import {
  requireAuth,
  requireGuest,
  requireMfaEnrolled,
  guards,
  type AuthStore,
  type GuardContext,
} from '@/core/router/guards'

interface StoreOverrides {
  user?: AuthStore['user']
  bootstrapStatus?: AuthStore['bootstrapStatus']
  mfaEnrollmentRequired?: AuthStore['mfaEnrollmentRequired']
  isMfaEnrolled?: AuthStore['isMfaEnrolled']
  bootstrap?: AuthStore['bootstrap']
}

function makeStore(overrides: StoreOverrides = {}): AuthStore {
  return {
    user: overrides.user ?? null,
    bootstrapStatus: overrides.bootstrapStatus ?? 'ready',
    mfaEnrollmentRequired: overrides.mfaEnrollmentRequired ?? false,
    isMfaEnrolled: overrides.isMfaEnrolled ?? false,
    bootstrap: overrides.bootstrap ?? vi.fn(async () => undefined),
  } as unknown as AuthStore
}

function makeRoute(name: string, fullPath: string = `/${name}`): RouteLocationNormalized {
  return {
    name,
    fullPath,
    path: fullPath,
    params: {},
    query: {},
    hash: '',
    matched: [],
    meta: {},
    redirectedFrom: undefined,
  } as unknown as RouteLocationNormalized
}

function makeUser(overrides: Partial<{ two_factor_enabled: boolean }> = {}) {
  return {
    type: 'users',
    id: '01HQ',
    attributes: {
      email: 'admin@example.test',
      user_type: 'platform_admin',
      two_factor_enabled: overrides.two_factor_enabled ?? true,
    },
  } as unknown as NonNullable<AuthStore['user']>
}

function makeCtx(store: AuthStore, to: RouteLocationNormalized): GuardContext {
  return { to, from: makeRoute('start', '/'), store }
}

describe('requireAuth', () => {
  it('calls bootstrap() exactly once and waits on it', async () => {
    const bootstrap = vi.fn(async () => undefined)
    const store = makeStore({ user: makeUser({ two_factor_enabled: true }), bootstrap })
    await requireAuth(makeCtx(store, makeRoute('app.dashboard', '/')))
    expect(bootstrap).toHaveBeenCalledTimes(1)
  })

  it('returns null (allow) when bootstrap resolves with a user (200 path)', async () => {
    const store = makeStore({ user: makeUser({ two_factor_enabled: true }) })
    const result = await requireAuth(makeCtx(store, makeRoute('app.dashboard', '/')))
    expect(result).toBeNull()
  })

  it('redirects to auth.sign-in with `redirect` query when user is null (401 path)', async () => {
    const store = makeStore({ user: null })
    const result = await requireAuth(makeCtx(store, makeRoute('app.dashboard', '/dashboard?x=1')))
    expect(result).toEqual({
      name: 'auth.sign-in',
      query: { redirect: '/dashboard?x=1' },
    })
  })

  it('redirects to auth.2fa.enable WITH redirect query preserved when mfaEnrollmentRequired is true', async () => {
    // Admin-specific divergence from main: the intended destination is
    // carried through the MFA-enrollment redirect so the post-enrolment
    // page can deep-link back to /dashboard/users (kickoff review
    // priority #3 — D7 deviation).
    const store = makeStore({ user: null, mfaEnrollmentRequired: true })
    const result = await requireAuth(
      makeCtx(store, makeRoute('app.dashboard', '/dashboard/users?from=signin')),
    )
    expect(result).toEqual({
      name: 'auth.2fa.enable',
      query: { redirect: '/dashboard/users?from=signin' },
    })
  })

  it('does NOT redirect when mfaEnrollmentRequired is true but already navigating to auth.2fa.enable', async () => {
    const store = makeStore({ user: null, mfaEnrollmentRequired: true })
    const result = await requireAuth(
      makeCtx(store, makeRoute('auth.2fa.enable', '/auth/2fa/enable')),
    )
    expect(result).toBeNull()
  })

  it('redirects to error.auth-bootstrap when bootstrapStatus is "error"', async () => {
    const store = makeStore({ user: null, bootstrapStatus: 'error' })
    const result = await requireAuth(makeCtx(store, makeRoute('app.dashboard', '/dashboard')))
    expect(result).toEqual({
      name: 'error.auth-bootstrap',
      query: { attempted: '/dashboard' },
    })
  })

  it('prioritises bootstrap-error over mfaEnrollmentRequired', async () => {
    const store = makeStore({
      user: null,
      bootstrapStatus: 'error',
      mfaEnrollmentRequired: true,
    })
    const result = await requireAuth(makeCtx(store, makeRoute('app.dashboard', '/d')))
    expect(result).toEqual({
      name: 'error.auth-bootstrap',
      query: { attempted: '/d' },
    })
  })

  it('prioritises mfaEnrollmentRequired over user-null sign-in redirect', async () => {
    // Belt-and-suspenders: the branch ordering matters because a
    // 403-mfa response from /me leaves `user` as null AND sets
    // mfaEnrollmentRequired. The guard must route to 2fa.enable, not
    // sign-in.
    const store = makeStore({ user: null, mfaEnrollmentRequired: true })
    const result = await requireAuth(makeCtx(store, makeRoute('app.dashboard', '/d')))
    expect(result).toEqual({
      name: 'auth.2fa.enable',
      query: { redirect: '/d' },
    })
  })
})

describe('requireGuest', () => {
  it('returns null when no admin is signed in', async () => {
    const store = makeStore({ user: null })
    const result = await requireGuest(makeCtx(store, makeRoute('auth.sign-in', '/sign-in')))
    expect(result).toBeNull()
  })

  it('redirects authenticated admins to the dashboard', async () => {
    const store = makeStore({ user: makeUser({ two_factor_enabled: true }) })
    const result = await requireGuest(makeCtx(store, makeRoute('auth.sign-in', '/sign-in')))
    expect(result).toEqual({ name: 'app.dashboard' })
  })

  it('does not call bootstrap()', async () => {
    const bootstrap = vi.fn(async () => undefined)
    const store = makeStore({ user: null, bootstrap })
    await requireGuest(makeCtx(store, makeRoute('auth.sign-in', '/sign-in')))
    expect(bootstrap).not.toHaveBeenCalled()
  })
})

describe('requireMfaEnrolled', () => {
  it('returns null when the admin is signed in AND has MFA enrolled', async () => {
    const store = makeStore({
      user: makeUser({ two_factor_enabled: true }),
      isMfaEnrolled: true,
    })
    const result = await requireMfaEnrolled(makeCtx(store, makeRoute('app.dashboard', '/')))
    expect(result).toBeNull()
  })

  it('redirects to auth.2fa.enable when the admin is signed in but has not enrolled MFA', async () => {
    const store = makeStore({
      user: makeUser({ two_factor_enabled: false }),
      isMfaEnrolled: false,
    })
    const result = await requireMfaEnrolled(makeCtx(store, makeRoute('app.dashboard', '/')))
    expect(result).toEqual({ name: 'auth.2fa.enable' })
  })

  it('redirects to auth.sign-in when no admin is signed in (defensive fall-through)', async () => {
    const store = makeStore({ user: null, isMfaEnrolled: false })
    const result = await requireMfaEnrolled(makeCtx(store, makeRoute('app.dashboard', '/')))
    expect(result).toEqual({ name: 'auth.sign-in' })
  })
})

describe('guards registry', () => {
  it('maps each symbolic name to the correct function reference', () => {
    expect(guards.requireAuth).toBe(requireAuth)
    expect(guards.requireGuest).toBe(requireGuest)
    expect(guards.requireMfaEnrolled).toBe(requireMfaEnrolled)
  })
})
