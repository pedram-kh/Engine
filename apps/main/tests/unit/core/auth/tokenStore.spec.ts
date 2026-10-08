/**
 * AH-100 D2 — the token store's contract, run against an in-memory
 * backend (the secure-storage adapter is exercised on device).
 */

import { describe, expect, it, vi } from 'vitest'

import type { StoredToken, TokenBackend } from '@/core/auth/tokenStore'
import { createTokenStore, isStoredToken, nativeTokenStore } from '@/core/auth/tokenStore'

function memoryBackend(initial: StoredToken | null = null) {
  let value = initial
  const backend = {
    get: vi.fn(async () => value),
    set: vi.fn(async (next: StoredToken) => {
      value = next
    }),
    remove: vi.fn(async () => {
      value = null
    }),
  } satisfies TokenBackend
  return { backend, current: () => value }
}

const NOW = Date.parse('2026-10-06T12:00:00Z')
const now = () => NOW

describe('createTokenStore', () => {
  it('reads null when nothing is stored', async () => {
    const { backend } = memoryBackend()
    await expect(createTokenStore(backend, now).read()).resolves.toBeNull()
  })

  it('write() persists token + expiry and read() returns the token', async () => {
    const { backend, current } = memoryBackend()
    const store = createTokenStore(backend, now)

    await store.write('tok-1', '2027-01-01T00:00:00Z')

    expect(current()).toEqual({ token: 'tok-1', expiresAt: '2027-01-01T00:00:00Z' })
    await expect(store.read()).resolves.toBe('tok-1')
  })

  it('hits the backend once per app-open, then serves reads from memory', async () => {
    const { backend } = memoryBackend({ token: 'tok-1', expiresAt: null })
    const store = createTokenStore(backend, now)

    await Promise.all([store.read(), store.read()])
    await store.read()

    expect(backend.get).toHaveBeenCalledTimes(1)
  })

  it('a non-expiring token (expiresAt null) stays readable', async () => {
    const { backend } = memoryBackend({ token: 'tok-1', expiresAt: null })
    await expect(createTokenStore(backend, now).read()).resolves.toBe('tok-1')
  })

  it('an expired token reads as null and is removed from the backend', async () => {
    const { backend, current } = memoryBackend({
      token: 'tok-old',
      expiresAt: '2026-10-06T11:59:59Z',
    })
    const store = createTokenStore(backend, now)

    await expect(store.read()).resolves.toBeNull()
    expect(current()).toBeNull()
    expect(backend.remove).toHaveBeenCalledTimes(1)
  })

  it('clear() removes the token from memory and the backend', async () => {
    const { backend, current } = memoryBackend({ token: 'tok-1', expiresAt: null })
    const store = createTokenStore(backend, now)
    await store.read()

    await store.clear()

    expect(current()).toBeNull()
    await expect(store.read()).resolves.toBeNull()
    expect(backend.get).toHaveBeenCalledTimes(1)
  })

  it('an unreadable backend reads as "no token"', async () => {
    const { backend } = memoryBackend()
    backend.get.mockRejectedValueOnce(new Error('keystore key lost'))

    await expect(createTokenStore(backend, now).read()).resolves.toBeNull()
  })

  it('a failed write propagates and leaves the previous token in place', async () => {
    const { backend } = memoryBackend({ token: 'tok-1', expiresAt: null })
    backend.set.mockRejectedValueOnce(new Error('keychain write failed'))
    const store = createTokenStore(backend, now)

    await expect(store.write('tok-2', null)).rejects.toThrow('keychain write failed')
    await expect(store.read()).resolves.toBe('tok-1')
  })

  it('defaults the clock to Date.now', async () => {
    const { backend } = memoryBackend({ token: 'tok-1', expiresAt: '2000-01-01T00:00:00Z' })
    await expect(createTokenStore(backend).read()).resolves.toBeNull()
  })
})

describe('createTokenStore — hasLapsed (AH-100b D3)', () => {
  it('is false before anything is read, and when no token was ever stored', async () => {
    const { backend } = memoryBackend()
    const store = createTokenStore(backend, now)

    expect(store.hasLapsed?.()).toBe(false)
    await store.read()
    expect(store.hasLapsed?.()).toBe(false)
  })

  it('is true once an EXPIRED token has been discarded by read()', async () => {
    const { backend } = memoryBackend({ token: 'tok-1', expiresAt: '2026-10-06T11:59:59Z' })
    const store = createTokenStore(backend, now)

    await expect(store.read()).resolves.toBeNull()

    expect(store.hasLapsed?.()).toBe(true)
  })

  it('stays false while a live token is read', async () => {
    const { backend } = memoryBackend({ token: 'tok-1', expiresAt: '2027-01-01T00:00:00Z' })
    const store = createTokenStore(backend, now)

    await store.read()

    expect(store.hasLapsed?.()).toBe(false)
  })

  it('an explicit clear() (logout) resets it, so a deliberate sign-out is never a lapse', async () => {
    const { backend } = memoryBackend({ token: 'tok-1', expiresAt: '2026-10-06T11:59:59Z' })
    const store = createTokenStore(backend, now)
    await store.read()

    await store.clear()

    expect(store.hasLapsed?.()).toBe(false)
  })

  it('a fresh write() resets it', async () => {
    const { backend } = memoryBackend({ token: 'tok-1', expiresAt: '2026-10-06T11:59:59Z' })
    const store = createTokenStore(backend, now)
    await store.read()

    await store.write('tok-2', null)

    expect(store.hasLapsed?.()).toBe(false)
  })

  it('a deliberate logout then a COLD START (a new store over the same backend) is not a lapse', async () => {
    const { backend } = memoryBackend({ token: 'tok-1', expiresAt: '2027-01-01T00:00:00Z' })
    const session = createTokenStore(backend, now)
    await session.read()
    await session.clear()

    const coldStart = createTokenStore(backend, now)
    await expect(coldStart.read()).resolves.toBeNull()

    expect(coldStart.hasLapsed?.()).toBe(false)
  })
})

describe('isStoredToken', () => {
  it.each([
    [{ token: 't', expiresAt: null }, true],
    [{ token: 't', expiresAt: '2027-01-01T00:00:00Z' }, true],
    [{ token: 't' }, false],
    [{ token: 1, expiresAt: null }, false],
    [{ token: 't', expiresAt: 5 }, false],
    ['t', false],
    [null, false],
  ])('%j -> %s', (value, expected) => {
    expect(isStoredToken(value)).toBe(expected)
  })
})

describe('nativeTokenStore', () => {
  it('refuses to build off-native (the plugin web fallback is localStorage)', () => {
    expect(() => nativeTokenStore()).toThrow(/native-only/)
  })
})
