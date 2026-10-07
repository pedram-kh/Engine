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
const nativeTokenStore = vi.fn(() => ({ read, write: vi.fn(), clear: vi.fn() }))

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
  await import('@/core/api')
}

afterEach(() => {
  vi.doUnmock('@catalyst/api-client')
  vi.doUnmock('@/core/platform/isNativePlatform')
  vi.doUnmock('@/core/auth/tokenStore')
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
})
