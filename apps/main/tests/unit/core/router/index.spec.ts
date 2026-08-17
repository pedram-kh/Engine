/**
 * Unit tests for the guard-dispatcher (`runGuards`) and the router
 * factory (`createRouter`).
 *
 * The dispatcher lives in the same module as the production router
 * instance so the wiring stays in one file; the tests exercise it
 * directly without mounting components.
 */

import type { RouteLocationNormalized } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'

import { createRouter, runGuards, scrollBehavior } from '@/core/router'

import type { AuthStore } from '@/core/router/guards'

vi.mock('@/modules/auth/stores/useAuthStore', () => ({
  useAuthStore: vi.fn(),
}))

import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

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
      email: 'a@b.c',
      user_type: 'creator',
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
    // would also redirect, but we want to assert the first guard wins
    // and the second one is not reached. We exercise this by checking
    // the redirect target — if requireMfaEnrolled had run, the target
    // would be auth.2fa.enable, not auth.sign-in.
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
})

/**
 * Scroll restoration. The list pages this exists for paint a short skeleton
 * and then fetch, so restoring at nextTick would land at the bottom of the
 * skeleton — the wait for the real content is the behaviour under test.
 */
describe('scrollBehavior', () => {
  function setDocumentHeight(scrollHeight: number, clientHeight = 800): void {
    Object.defineProperty(document.documentElement, 'scrollHeight', {
      configurable: true,
      value: scrollHeight,
    })
    Object.defineProperty(document.documentElement, 'clientHeight', {
      configurable: true,
      value: clientHeight,
    })
  }

  function callScrollBehavior(savedPosition: { top: number; left: number } | null) {
    return scrollBehavior.call(
      undefined as never,
      makeRoute('discover.list'),
      makeRoute('discover.detail'),
      savedPosition,
    )
  }

  it('starts a fresh navigation at the top', async () => {
    await expect(callScrollBehavior(null)).resolves.toEqual({ top: 0 })
  })

  it('returns the saved position immediately when the page is already tall enough', async () => {
    setDocumentHeight(4000)
    await expect(callScrollBehavior({ top: 1200, left: 0 })).resolves.toEqual({
      top: 1200,
      left: 0,
    })
  })

  it('waits for the async list to render before restoring the offset', async () => {
    setDocumentHeight(0)
    const pending = Promise.resolve(callScrollBehavior({ top: 1200, left: 0 }))

    let settled = false
    void pending.then(() => {
      settled = true
    })
    await new Promise((resolve) => setTimeout(resolve, 60))
    expect(settled).toBe(false)

    setDocumentHeight(4000)
    await expect(pending).resolves.toEqual({ top: 1200, left: 0 })
  })

  it('gives up rather than hanging the navigation when the content never arrives', async () => {
    setDocumentHeight(0)
    vi.useFakeTimers()
    try {
      const pending = callScrollBehavior({ top: 5000, left: 0 })
      await vi.advanceTimersByTimeAsync(1500)
      await expect(pending).resolves.toEqual({ top: 5000, left: 0 })
    } finally {
      vi.useRealTimers()
    }
  })
})

describe('createRouter', () => {
  it('builds a router whose beforeEach resolves the store and dispatches guards', async () => {
    const store = makeStore({ user: null })
    vi.mocked(useAuthStore).mockReturnValue(store as unknown as ReturnType<typeof useAuthStore>)

    // Inject a memory-history factory so we can drive navigation without
    // touching window.location.
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
    vi.mocked(useAuthStore).mockReturnValue(store as unknown as ReturnType<typeof useAuthStore>)
    const r = createRouter()
    expect(r).toBeDefined()
    // Sanity: the route table includes the sign-in record.
    expect(r.hasRoute('auth.sign-in')).toBe(true)
  })

  it('redirects an unauthenticated visit to the dashboard back to /sign-in', async () => {
    const store = makeStore({ user: null })
    vi.mocked(useAuthStore).mockReturnValue(store as unknown as ReturnType<typeof useAuthStore>)
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
})
