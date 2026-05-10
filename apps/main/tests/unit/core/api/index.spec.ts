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
  createUnauthorizedPolicy,
  shouldHandleUnauthorized,
  SESSION_EXPIRED_QUERY_REASON,
  http,
  authApi,
} from '@/core/api'

describe('shouldHandleUnauthorized', () => {
  it.each([
    ['/me', false],
    ['/admin/me', false],
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
