/**
 * Unit tests for the chunk-6.5 401 policy in `apps/main/src/core/api/index.ts`.
 *
 * The decision matrix and the side-effect callback are exported as pure
 * functions so we can exercise them in isolation, without spinning the
 * actual http client. The end-to-end path through the http client is
 * covered by the api-client tests in
 * `packages/api-client/src/http.spec.ts`.
 */

import { describe, expect, it, vi } from 'vitest'

import {
  createNativeSessionStateReader,
  createNativeUnauthorizedPolicy,
  hasLiveNativeSession,
  createUnauthorizedPolicy,
  shouldHandleNativeUnauthorized,
  shouldHandleUnauthorized,
  SESSION_EXPIRED_QUERY_REASON,
  sessionExpiredLocation,
  http,
  authApi,
} from '@/core/api'

describe('shouldHandleUnauthorized', () => {
  it.each([
    ['/me', false],
    ['/admin/me', false],
    ['/auth/impersonation/status', false],
    ['/auth/login', false],
    ['/admin/auth/login', false],
  ])('exempts %s', (path, expected) => {
    expect(shouldHandleUnauthorized(path)).toBe(expected)
  })

  it.each([
    '/auth/logout',
    '/auth/2fa/enable',
    '/auth/2fa/confirm',
    '/auth/2fa/disable',
    '/users/01HQ',
    '/dashboard',
    '/some/other/path',
  ])('handles %s as session-expired', (path) => {
    expect(shouldHandleUnauthorized(path)).toBe(true)
  })
})

describe('createUnauthorizedPolicy', () => {
  function makeDeps() {
    const clearUser = vi.fn()
    const push = vi.fn(() => Promise.resolve())
    return {
      clearUser,
      push,
      deps: {
        getStore: vi.fn(async () => ({ clearUser })),
        getRouter: vi.fn(async () => ({ push })),
      },
    }
  }

  it('clears the user and pushes to auth.sign-in with session_expired reason on a non-exempt 401', async () => {
    const { clearUser, push, deps } = makeDeps()
    const policy = createUnauthorizedPolicy(deps)

    await policy('/auth/2fa/disable')

    expect(clearUser).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: { reason: SESSION_EXPIRED_QUERY_REASON },
    })
  })

  it('is a no-op on an exempt path (/auth/login wrong-password channel)', async () => {
    const { clearUser, push, deps } = makeDeps()
    const policy = createUnauthorizedPolicy(deps)

    await policy('/auth/login')

    expect(clearUser).not.toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
    expect(deps.getStore).not.toHaveBeenCalled()
    expect(deps.getRouter).not.toHaveBeenCalled()
  })

  it('is a no-op on the cold-load identity probes (/me, /admin/me)', async () => {
    const { clearUser, push, deps } = makeDeps()
    const policy = createUnauthorizedPolicy(deps)

    await policy('/me')
    await policy('/admin/me')

    expect(clearUser).not.toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
  })

  it('is a no-op on the cold-load impersonation-banner probe (anonymous visitors on public pages)', async () => {
    const { clearUser, push, deps } = makeDeps()
    const policy = createUnauthorizedPolicy(deps)

    await policy('/auth/impersonation/status')

    expect(clearUser).not.toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
    expect(deps.getStore).not.toHaveBeenCalled()
    expect(deps.getRouter).not.toHaveBeenCalled()
  })

  it('resolves both lazy loaders concurrently', async () => {
    const { clearUser, push } = makeDeps()
    let storeResolved = false
    let routerResolved = false
    const policy = createUnauthorizedPolicy({
      getStore: async () => {
        await new Promise((r) => setTimeout(r, 5))
        storeResolved = true
        return { clearUser }
      },
      getRouter: async () => {
        await new Promise((r) => setTimeout(r, 5))
        routerResolved = true
        return { push }
      },
    })

    await policy('/auth/2fa/disable')

    expect(storeResolved).toBe(true)
    expect(routerResolved).toBe(true)
    expect(clearUser).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledTimes(1)
  })
})

describe('shouldHandleNativeUnauthorized (AH-100)', () => {
  // AH-100b D3: `/auth/logout` moved from the handled list to here — a 401 on the
  // revoke means "already signed out", never "your session expired".
  it.each(['/me', '/auth/token', '/auth/impersonation/status', '/auth/logout'])(
    'exempts %s',
    (path) => {
      expect(shouldHandleNativeUnauthorized(path)).toBe(false)
    },
  )

  it.each(['/creators/me', '/creators/me/availability', '/auth/login'])(
    'handles %s as a dead token',
    (path) => {
      expect(shouldHandleNativeUnauthorized(path)).toBe(true)
    },
  )
})

describe('createNativeUnauthorizedPolicy (AH-100)', () => {
  function makeNativeDeps(clearToken: () => Promise<void> = vi.fn(async () => undefined)) {
    const clearUser = vi.fn()
    const push = vi.fn(() => Promise.resolve())
    return {
      clearUser,
      push,
      clearToken,
      deps: {
        getStore: vi.fn(async () => ({ clearUser })),
        getRouter: vi.fn(async () => ({ push })),
        clearToken,
      },
    }
  }

  it('clears the token and the user, then routes to sign-in, on a non-exempt 401', async () => {
    const { clearUser, push, clearToken, deps } = makeNativeDeps()

    await createNativeUnauthorizedPolicy(deps)('/creators/me')

    expect(clearToken).toHaveBeenCalledTimes(1)
    expect(clearUser).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: { reason: SESSION_EXPIRED_QUERY_REASON },
    })
  })

  it.each(['/auth/token', '/me', '/auth/impersonation/status', '/auth/logout'])(
    'touches nothing on the exempt path %s',
    async (path) => {
      const { clearUser, push, clearToken, deps } = makeNativeDeps()

      await createNativeUnauthorizedPolicy(deps)(path)

      expect(clearToken).not.toHaveBeenCalled()
      expect(clearUser).not.toHaveBeenCalled()
      expect(push).not.toHaveBeenCalled()
    },
  )

  it('still signs the user out when clearing storage throws', async () => {
    const { clearUser, push, deps } = makeNativeDeps(
      vi.fn(async () => {
        throw new Error('keychain unavailable')
      }),
    )

    await createNativeUnauthorizedPolicy(deps)('/creators/me')

    expect(clearUser).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledTimes(1)
  })
})

describe('sessionExpiredLocation (AH-103 — redirect= through the native lapse redirects)', () => {
  it('carries the current screen as redirect=, with the expiry reason', () => {
    expect(
      sessionExpiredLocation({
        fullPath: '/creator/assignments/01J9ZK3Q8M5T7WXYABCDEFGHJK',
        name: 'creator.assignment.detail',
      }),
    ).toEqual({
      name: 'auth.sign-in',
      query: {
        reason: SESSION_EXPIRED_QUERY_REASON,
        redirect: '/creator/assignments/01J9ZK3Q8M5T7WXYABCDEFGHJK',
      },
    })
  })

  it('keeps the query string of the screen it returns to (a campaign tab)', () => {
    expect(
      sessionExpiredLocation({
        fullPath: '/campaigns/01J9ZK3Q8M5T7WXYCAMPAGNAAA?tab=drafts',
        name: 'campaigns.detail',
      }).query.redirect,
    ).toBe('/campaigns/01J9ZK3Q8M5T7WXYCAMPAGNAAA?tab=drafts')
  })

  it.each([
    ['the root', { fullPath: '/', name: 'root' }],
    ['an empty path', { fullPath: '', name: null }],
    [
      'sign-in itself (a loop)',
      { fullPath: '/sign-in?reason=session_expired', name: 'auth.sign-in' },
    ],
    ['another auth screen', { fullPath: '/forgot-password', name: 'auth.forgot-password' }],
    [
      'the bootstrap-error screen',
      { fullPath: '/error/auth-bootstrap', name: 'error.auth-bootstrap' },
    ],
  ])('OMITS redirect on %s', (_label, current) => {
    expect(sessionExpiredLocation(current)).toEqual({
      name: 'auth.sign-in',
      query: { reason: SESSION_EXPIRED_QUERY_REASON },
    })
  })

  it('with no current route (a router that does not expose one) it is the pre-AH-103 location', () => {
    expect(sessionExpiredLocation()).toEqual({
      name: 'auth.sign-in',
      query: { reason: SESSION_EXPIRED_QUERY_REASON },
    })
    expect(sessionExpiredLocation(null).query).toEqual({ reason: SESSION_EXPIRED_QUERY_REASON })
  })

  it('the NATIVE 401 policy sends the user back to where they were; the web policy is unchanged', async () => {
    const clearUser = vi.fn()
    const push = vi.fn(() => Promise.resolve())
    const currentRoute = {
      value: { fullPath: '/creator/jobs/01J9ZK3Q8M5T7WXYCAMPAGNAAA', name: 'creator.job.detail' },
    }
    const router = { push, currentRoute }

    await createNativeUnauthorizedPolicy({
      getStore: async () => ({ clearUser }),
      getRouter: async () => router,
      clearToken: async () => undefined,
    })('/creators/me')

    expect(push).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: {
        reason: SESSION_EXPIRED_QUERY_REASON,
        redirect: '/creator/jobs/01J9ZK3Q8M5T7WXYCAMPAGNAAA',
      },
    })

    push.mockClear()
    await createUnauthorizedPolicy({
      getStore: async () => ({ clearUser }),
      getRouter: async () => router,
    })('/creators/me')

    expect(push).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: { reason: SESSION_EXPIRED_QUERY_REASON },
    })
  })
})

describe('hasLiveNativeSession (AH-100b D3)', () => {
  it.each([
    [{ isLoggingOut: false, hasUser: true, hasToken: true }, true],
    [{ isLoggingOut: false, hasUser: true, hasToken: false }, true],
    [{ isLoggingOut: false, hasUser: false, hasToken: true }, true],
    [{ isLoggingOut: false, hasUser: false, hasToken: false }, false],
    [{ isLoggingOut: true, hasUser: true, hasToken: true }, false],
    [{ isLoggingOut: true, hasUser: false, hasToken: false }, false],
  ])('%j → %s', (state, expected) => {
    expect(hasLiveNativeSession(state)).toBe(expected)
  })
})

describe('createNativeUnauthorizedPolicy session gate (AH-100b D3)', () => {
  function gated(state: { isLoggingOut: boolean; hasUser: boolean; hasToken: boolean }) {
    const clearUser = vi.fn()
    const push = vi.fn(() => Promise.resolve())
    const clearToken = vi.fn(async () => undefined)
    const policy = createNativeUnauthorizedPolicy({
      getStore: vi.fn(async () => ({ clearUser })),
      getRouter: vi.fn(async () => ({ push })),
      clearToken,
      getSessionState: vi.fn(async () => state),
    })
    return { policy, clearUser, push, clearToken }
  }

  it('acts when a session is held', async () => {
    const { policy, push, clearUser, clearToken } = gated({
      isLoggingOut: false,
      hasUser: true,
      hasToken: true,
    })

    await policy('/creators/me')

    expect(clearToken).toHaveBeenCalledTimes(1)
    expect(clearUser).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledTimes(1)
  })

  it.each([
    ['no session at all', { isLoggingOut: false, hasUser: false, hasToken: false }],
    ['a deliberate sign-out in flight', { isLoggingOut: true, hasUser: true, hasToken: true }],
  ])('touches nothing with %s', async (_label, state) => {
    const { policy, push, clearUser, clearToken } = gated(state)

    await policy('/creators/me')

    expect(clearToken).not.toHaveBeenCalled()
    expect(clearUser).not.toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
  })
})

describe('createNativeSessionStateReader (AH-100b D3)', () => {
  it('reads the logout flag and user from the store and the token from the token store', async () => {
    const read = createNativeSessionStateReader({
      loadStore: async () => ({ isLoggingOut: true, user: { id: 'u' } }),
      readToken: async () => 'tok',
    })
    await expect(read()).resolves.toEqual({ isLoggingOut: true, hasUser: true, hasToken: true })
  })

  it('reads "no user" and "no token" as false, and a throwing token store as no token', async () => {
    const read = createNativeSessionStateReader({
      loadStore: async () => ({ isLoggingOut: false, user: null }),
      readToken: async () => {
        throw new Error('keychain unavailable')
      },
    })
    await expect(read()).resolves.toEqual({ isLoggingOut: false, hasUser: false, hasToken: false })
  })
})

describe('module-level singletons', () => {
  it('exposes `http` and `authApi`', () => {
    expect(typeof http.get).toBe('function')
    expect(typeof http.post).toBe('function')
    expect(typeof authApi.me).toBe('function')
    expect(typeof authApi.login).toBe('function')
  })

  it('exports the session-expired reason constant', () => {
    expect(SESSION_EXPIRED_QUERY_REASON).toBe('session_expired')
  })
})
