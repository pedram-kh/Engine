/**
 * Unit tests for the route guards in `apps/main/src/core/router/guards.ts`.
 *
 * Each guard is tested in isolation against a hand-rolled minimal store
 * stub — we deliberately avoid spinning the real Pinia store here so the
 * tests stay focused on the guard's branching logic and not on the store's
 * bootstrap state machine (which has its own coverage in
 * `useAuthStore.spec.ts`).
 *
 * Coverage requirement: 100% lines / branches / functions / statements
 * (auth-flow gate per docs/02-CONVENTIONS.md § 4.3).
 */

import type { RouteLocationNormalized } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'

// Module-level mock for useAgencyStore — hoisted, safe to reference in tests via vi.mocked().
vi.mock('@/core/stores/useAgencyStore', () => ({
  useAgencyStore: vi.fn(() => ({ isAdmin: false })),
}))

import { useAgencyStore } from '@/core/stores/useAgencyStore'

import {
  requireAuth,
  requireGuest,
  requireMfaEnrolled,
  requireAgencyAdmin,
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
      email: 'a@b.c',
      user_type: 'creator',
      two_factor_enabled: overrides.two_factor_enabled ?? false,
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

  it('redirects to auth.2fa.enable when mfaEnrollmentRequired is true', async () => {
    const store = makeStore({ user: null, mfaEnrollmentRequired: true })
    const result = await requireAuth(makeCtx(store, makeRoute('app.dashboard', '/')))
    expect(result).toEqual({ name: 'auth.2fa.enable' })
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
})

describe('requireGuest', () => {
  it('returns null when no user is signed in', async () => {
    const store = makeStore({ user: null })
    const result = await requireGuest(makeCtx(store, makeRoute('auth.sign-in', '/sign-in')))
    expect(result).toBeNull()
  })

  it('redirects authenticated users to the dashboard', async () => {
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
  it('returns null when the user is signed in AND has MFA enrolled', async () => {
    const store = makeStore({
      user: makeUser({ two_factor_enabled: true }),
      isMfaEnrolled: true,
    })
    const result = await requireMfaEnrolled(makeCtx(store, makeRoute('app.dashboard', '/')))
    expect(result).toBeNull()
  })

  it('redirects to auth.2fa.enable when the user is signed in but has not enrolled MFA', async () => {
    const store = makeStore({
      user: makeUser({ two_factor_enabled: false }),
      isMfaEnrolled: false,
    })
    const result = await requireMfaEnrolled(makeCtx(store, makeRoute('app.dashboard', '/')))
    expect(result).toEqual({ name: 'auth.2fa.enable' })
  })

  it('redirects to auth.sign-in when no user is signed in (defensive fall-through)', async () => {
    const store = makeStore({ user: null, isMfaEnrolled: false })
    const result = await requireMfaEnrolled(makeCtx(store, makeRoute('app.dashboard', '/')))
    expect(result).toEqual({ name: 'auth.sign-in' })
  })
})

describe('requireAgencyAdmin', () => {
  it('returns null (allow) when the user has agency_admin role', async () => {
    vi.mocked(useAgencyStore).mockReturnValue({ isAdmin: true } as ReturnType<
      typeof useAgencyStore
    >)
    const store = makeStore({ user: makeUser() })
    const result = await requireAgencyAdmin(
      makeCtx(store, makeRoute('agency-users', '/agency-users')),
    )
    expect(result).toBeNull()
  })

  it('redirects to brands.list when the user is NOT agency_admin', async () => {
    vi.mocked(useAgencyStore).mockReturnValue({ isAdmin: false } as ReturnType<
      typeof useAgencyStore
    >)
    const store = makeStore({ user: makeUser() })
    const result = await requireAgencyAdmin(
      makeCtx(store, makeRoute('agency-users', '/agency-users')),
    )
    expect(result).toEqual({ name: 'brands.list' })
  })

  it('is registered in the guards registry', () => {
    expect(guards.requireAgencyAdmin).toBe(requireAgencyAdmin)
  })
})

describe('guards registry', () => {
  it('maps each symbolic name to the correct function reference', () => {
    expect(guards.requireAuth).toBe(requireAuth)
    expect(guards.requireGuest).toBe(requireGuest)
    expect(guards.requireMfaEnrolled).toBe(requireMfaEnrolled)
    expect(guards.requireAgencyAdmin).toBe(requireAgencyAdmin)
  })
})
