/**
 * Unit tests for the guard-dispatcher (`runGuards`) and the router
 * factory (`createRouter`).
 *
 * Mirror of `apps/main/tests/unit/core/router/index.spec.ts` (chunk
 * 6.5) with admin-specific adaptations:
 *
 *   - The store mock points at `useAdminAuthStore`, not `useAuthStore`.
 *   - The `mfaEnrollmentRequired` chain-redirect case carries the
 *     `?redirect=<fullPath>` query (D7 deviation from main).
 *   - The unauth-deep-link route name asserted is admin's `app.dashboard`
 *     (same name as main; route table mirrors main's kebab-case
 *     `module.route-name` convention).
 *
 * The dispatcher lives in the same module as the production router
 * instance so the wiring stays in one file; the tests exercise it
 * directly without mounting components.
 */

import type { RouteLocationNormalized } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'

import { createRouter, runGuards } from '@/core/router'

import type { AuthStore } from '@/core/router/guards'

vi.mock('@/modules/auth/stores/useAdminAuthStore', () => ({
  useAdminAuthStore: vi.fn(),
}))

import { useAdminAuthStore } from '@/modules/auth/stores/useAdminAuthStore'

function makeRoute(
  name: string,
  guards: ReadonlyArray<'requireAuth' | 'requireGuest' | 'requireMfaEnrolled'> = [],
): RouteLocationNormalized {
  return {
    name,
    fullPath: `/${name}`,
    path: `/${name}`,
    params: {},
    query: {},
    hash: '',
    matched: [],
    meta: { guards },
    redirectedFrom: undefined,
  } as unknown as RouteLocationNormalized
}

function makeUser(twoFactorEnabled: boolean) {
  return {
    type: 'users',
    id: '01HQ',
    attributes: {
      email: 'admin@example.test',
      user_type: 'platform_admin',
      two_factor_enabled: twoFactorEnabled,
    },
  } as unknown as NonNullable<AuthStore['user']>
}

function makeStore(overrides: Partial<AuthStore> = {}): AuthStore {
  return {
    user: null,
    bootstrapStatus: 'ready',
    mfaEnrollmentRequired: false,
    isMfaEnrolled: false,
    bootstrap: vi.fn(async () => undefined),
    ...overrides,
  } as unknown as AuthStore
}

describe('runGuards', () => {
  it('returns null when the route has no guards', async () => {
    const result = await runGuards(makeRoute('public'), makeRoute('start'), makeStore())
    expect(result).toBeNull()
  })

  it('returns null when meta.guards is undefined', async () => {
    const route = {
      name: 'public',
      fullPath: '/public',
      path: '/public',
      params: {},
      query: {},
      hash: '',
      matched: [],
      meta: {},
      redirectedFrom: undefined,
    } as unknown as RouteLocationNormalized
    const result = await runGuards(route, makeRoute('start'), makeStore())
    expect(result).toBeNull()
  })

  it('runs requireAuth and returns its redirect when user is null', async () => {
    const result = await runGuards(
      makeRoute('app.dashboard', ['requireAuth']),
      makeRoute('start'),
      makeStore({ user: null }),
    )
    expect(result).toEqual({
      name: 'auth.sign-in',
      query: { redirect: '/app.dashboard' },
    })
  })

  it("chains requireAuth → requireMfaEnrolled, returning the second guard's redirect", async () => {
    const result = await runGuards(
      makeRoute('app.dashboard', ['requireAuth', 'requireMfaEnrolled']),
      makeRoute('start'),
      makeStore({ user: makeUser(false), isMfaEnrolled: false }),
    )
    expect(result).toEqual({ name: 'auth.2fa.enable' })
  })

  it('returns null when every guard in the chain allows the route', async () => {
    const result = await runGuards(
      makeRoute('app.dashboard', ['requireAuth', 'requireMfaEnrolled']),
      makeRoute('start'),
      makeStore({ user: makeUser(true), isMfaEnrolled: true }),
    )
    expect(result).toBeNull()
  })

  it('short-circuits after the first redirect — does not run subsequent guards', async () => {
    const bootstrap = vi.fn(async () => undefined)
    // requireAuth will redirect because user is null. requireMfaEnrolled
    // would also redirect (defensive fall-through to sign-in), but we
    // want to assert the first guard wins and the second one is not
    // reached. We exercise this by checking the redirect target — if
    // requireMfaEnrolled had run, the target would still be
    // auth.sign-in (defensive branch) but WITHOUT the redirect query.
    // The presence of the redirect query proves requireAuth wins.
    const result = await runGuards(
      makeRoute('app.dashboard', ['requireAuth', 'requireMfaEnrolled']),
      makeRoute('start'),
      makeStore({ user: null, bootstrap }),
    )
    expect(result).toEqual({
      name: 'auth.sign-in',
      query: { redirect: '/app.dashboard' },
    })
    expect(bootstrap).toHaveBeenCalledTimes(1)
  })

  it('chains requireAuth → requireMfaEnrolled and preserves the mfaEnrollmentRequired redirect from requireAuth (D7)', async () => {
    // Confirms the admin-specific divergence end-to-end through the
    // dispatcher: with mfaEnrollmentRequired=true and the chain
    // [requireAuth, requireMfaEnrolled], the first guard returns the
    // 2fa.enable redirect carrying the intended destination.
    const result = await runGuards(
      makeRoute('app.dashboard', ['requireAuth', 'requireMfaEnrolled']),
      makeRoute('start'),
      makeStore({ user: null, mfaEnrollmentRequired: true }),
    )
    expect(result).toEqual({
      name: 'auth.2fa.enable',
      query: { redirect: '/app.dashboard' },
    })
  })
})

describe('createRouter', () => {
  it('builds a router whose beforeEach resolves the store and dispatches guards', async () => {
    const store = makeStore({ user: null })
    vi.mocked(useAdminAuthStore).mockReturnValue(
      store as unknown as ReturnType<typeof useAdminAuthStore>,
    )

    // Inject a memory-history factory so we can drive navigation
    // without touching window.location.
    const { createMemoryHistory } = await import('vue-router')
    const r = createRouter(
      () =>
        createMemoryHistory() as unknown as ReturnType<
          typeof import('vue-router').createWebHistory
        >,
    )

    await r.push('/sign-in')
    expect(r.currentRoute.value.name).toBe('auth.sign-in')
  })

  it('uses the default web-history factory when called without arguments', () => {
    const store = makeStore({ user: null })
    vi.mocked(useAdminAuthStore).mockReturnValue(
      store as unknown as ReturnType<typeof useAdminAuthStore>,
    )
    const r = createRouter()
    expect(r).toBeDefined()
    // Sanity: the route table includes the sign-in record.
    expect(r.hasRoute('auth.sign-in')).toBe(true)
  })

  it('redirects an unauthenticated admin visit to the dashboard back to /sign-in', async () => {
    const store = makeStore({ user: null })
    vi.mocked(useAdminAuthStore).mockReturnValue(
      store as unknown as ReturnType<typeof useAdminAuthStore>,
    )
    const { createMemoryHistory } = await import('vue-router')
    const r = createRouter(
      () =>
        createMemoryHistory() as unknown as ReturnType<
          typeof import('vue-router').createWebHistory
        >,
    )

    await r.push('/')
    expect(r.currentRoute.value.name).toBe('auth.sign-in')
    expect(r.currentRoute.value.query.redirect).toBe('/')
  })

  it('redirects an unauthenticated admin with mfaEnrollmentRequired to /auth/2fa/enable preserving the intended destination (D7)', async () => {
    const store = makeStore({ user: null, mfaEnrollmentRequired: true })
    vi.mocked(useAdminAuthStore).mockReturnValue(
      store as unknown as ReturnType<typeof useAdminAuthStore>,
    )
    const { createMemoryHistory } = await import('vue-router')
    const r = createRouter(
      () =>
        createMemoryHistory() as unknown as ReturnType<
          typeof import('vue-router').createWebHistory
        >,
    )

    await r.push('/settings')
    expect(r.currentRoute.value.name).toBe('auth.2fa.enable')
    expect(r.currentRoute.value.query.redirect).toBe('/settings')
  })

  it('full chained D7 flow: deep-link /settings → /sign-in?redirect=/settings → /auth/2fa/enable?redirect=/settings → /settings', async () => {
    // End-to-end trace through the dispatcher of the scenario claude
    // raised in spot-check 1:
    //
    //   Step 1: anonymous admin deep-links /settings → bounced to
    //           /sign-in with `?redirect=/settings`.
    //   Step 2: sign-in completes; backend's /me returns 403
    //           auth.mfa.enrollment_required so the store flips
    //           `mfaEnrollmentRequired` to true. The (future, 7.5)
    //           SignInPage reads `route.query.redirect` and calls
    //           `router.push('/settings')`. The guard re-evaluates:
    //           mfaEnrollmentRequired branch → /auth/2fa/enable
    //           carrying the intended destination forward.
    //   Step 3: enrolment completes; the store sets
    //           `mfaEnrollmentRequired = false`, populates `user`, and
    //           flips `isMfaEnrolled` to true. The (future, 7.5)
    //           EnableTotpPage reads `route.query.redirect` and calls
    //           `router.push('/settings')`. Guards now allow.
    //
    // The page-level extract-and-push hops in Steps 2 and 3 are
    // exercised explicitly here by `r.push('/settings')` after
    // mutating the store state. The store state transitions match the
    // chunk-7.2 contract (deviation #4 of Group 1's review): sign-in
    // sets mfaEnrollmentRequired without setting user; enrolment
    // clears mfaEnrollmentRequired and populates both user and
    // isMfaEnrolled.
    const store = makeStore({ user: null, mfaEnrollmentRequired: false })
    vi.mocked(useAdminAuthStore).mockReturnValue(
      store as unknown as ReturnType<typeof useAdminAuthStore>,
    )
    const { createMemoryHistory } = await import('vue-router')
    const r = createRouter(
      () =>
        createMemoryHistory() as unknown as ReturnType<
          typeof import('vue-router').createWebHistory
        >,
    )

    // Step 1: anonymous deep-link.
    await r.push('/settings')
    expect(r.currentRoute.value.name).toBe('auth.sign-in')
    expect(r.currentRoute.value.query.redirect).toBe('/settings')

    // Step 2: sign-in completes → mfaEnrollmentRequired flips true.
    // The SignInPage re-pushes the preserved destination.
    const preservedAfterSignIn = r.currentRoute.value.query.redirect as string
    ;(store as unknown as { mfaEnrollmentRequired: boolean }).mfaEnrollmentRequired = true
    await r.push(preservedAfterSignIn)
    expect(r.currentRoute.value.name).toBe('auth.2fa.enable')
    expect(r.currentRoute.value.query.redirect).toBe('/settings')

    // Step 3: enrolment completes → mfaEnrollmentRequired clears, user
    // populated, isMfaEnrolled true. EnableTotpPage re-pushes the
    // preserved destination.
    const preservedAfterEnrolment = r.currentRoute.value.query.redirect as string
    ;(store as unknown as { mfaEnrollmentRequired: boolean }).mfaEnrollmentRequired = false
    ;(store as unknown as { user: AuthStore['user'] }).user = makeUser(true)
    ;(store as unknown as { isMfaEnrolled: boolean }).isMfaEnrolled = true
    await r.push(preservedAfterEnrolment)
    expect(r.currentRoute.value.name).toBe('app.settings')
    expect(r.currentRoute.value.path).toBe('/settings')
  })
})
