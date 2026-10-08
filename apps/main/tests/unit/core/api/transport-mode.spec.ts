/**
 * AH-100 D1 — which transport `core/api` builds. On the web the
 * `createHttpClient` options are exactly the pre-AH-100 pair
 * (`baseUrl`, `onUnauthorized`) with no `auth` key, so the client stays
 * in cookie mode. Inside the Capacitor shell it is bearer mode, reading
 * the token from the native token store.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

const createHttpClient = vi.fn()
const read = vi.fn(async () => 'tok-native')
const clear = vi.fn(async () => undefined)
const nativeTokenStore = vi.fn(() => ({ read, write: vi.fn(), clear }))
const authStore = { isLoggingOut: false, user: null as unknown, clearUser: vi.fn() }
const routerPush = vi.fn()

async function loadCoreApi(native: boolean): Promise<void> {
  vi.resetModules()
  vi.doMock('@catalyst/api-client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@catalyst/api-client')>()
    return {
      ...actual,
      createHttpClient: (...args: Parameters<typeof actual.createHttpClient>) => {
        createHttpClient(...args)
        return actual.createHttpClient(...args)
      },
    }
  })
  vi.doMock('@/core/platform/isNativePlatform', () => ({
    isNativePlatform: () => native,
    nativePlatform: () => (native ? 'ios' : null),
  }))
  vi.doMock('@/core/auth/tokenStore', () => ({ nativeTokenStore }))
  vi.doMock('@/modules/auth/stores/useAuthStore', () => ({ useAuthStore: () => authStore }))
  vi.doMock('@/core/router', () => ({ router: { push: routerPush } }))
  await import('@/core/api')
}

afterEach(() => {
  vi.doUnmock('@catalyst/api-client')
  vi.doUnmock('@/core/platform/isNativePlatform')
  vi.doUnmock('@/core/auth/tokenStore')
  vi.doUnmock('@/modules/auth/stores/useAuthStore')
  vi.doUnmock('@/core/router')
  authStore.isLoggingOut = false
  authStore.user = null
  authStore.clearUser.mockClear()
  routerPush.mockClear()
  clear.mockClear()
  read.mockImplementation(async () => 'tok-native')
  createHttpClient.mockClear()
  nativeTokenStore.mockClear()
  read.mockClear()
})

describe('core/api transport selection', () => {
  it('web: passes only baseUrl + onUnauthorized (cookie mode) and never builds a token store', async () => {
    await loadCoreApi(false)

    expect(createHttpClient).toHaveBeenCalledTimes(1)
    const options = createHttpClient.mock.calls[0]?.[0] as Record<string, unknown>
    expect(Object.keys(options).sort()).toEqual(['baseUrl', 'onUnauthorized'])
    expect(nativeTokenStore).not.toHaveBeenCalled()
  })

  it('native: passes bearer mode whose getToken reads the native token store', async () => {
    await loadCoreApi(true)

    const options = createHttpClient.mock.calls[0]?.[0] as {
      auth: { mode: string; getToken: () => Promise<string | null> }
    }
    expect(options.auth.mode).toBe('bearer')
    await expect(options.auth.getToken()).resolves.toBe('tok-native')
    expect(read).toHaveBeenCalledTimes(1)
  })

  // AH-100b D3 — the guard only protects the app if the PRODUCTION policy
  // is built with it. The policy factories are unit-tested with fakes; this
  // drives the `onUnauthorized` that `core/api` really hands the HTTP client.
  describe('native production policy wiring (AH-100b D3)', () => {
    async function fire(path: string): Promise<void> {
      const options = createHttpClient.mock.calls[0]?.[0] as {
        onUnauthorized: (path: string) => void
      }
      options.onUnauthorized(path)
      await new Promise((resolve) => setTimeout(resolve, 20))
    }

    it('a 401 with no user and no token (a late reply after sign-out) does nothing', async () => {
      read.mockImplementation(async () => null as never)
      await loadCoreApi(true)

      await fire('/creators/me/jobs')

      expect(routerPush).not.toHaveBeenCalled()
      expect(authStore.clearUser).not.toHaveBeenCalled()
    })

    it('a 401 while the user is held still clears the session and routes to sign-in', async () => {
      authStore.user = { id: 'u1' }
      await loadCoreApi(true)

      await fire('/creators/me/jobs')

      expect(clear).toHaveBeenCalledTimes(1)
      expect(authStore.clearUser).toHaveBeenCalledTimes(1)
      expect(routerPush).toHaveBeenCalledWith({
        name: 'auth.sign-in',
        query: { reason: 'session_expired' },
      })
    })
  })
})
