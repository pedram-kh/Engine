/**
 * AH-100d (item 8) — `refreshSession()`: re-read `/me` for a signed-in user
 * (the app-resume refresh). Both polarities of every guard:
 *   - signed in → one `/me`, the held user replaced in place;
 *   - signed out / not bootstrapped → no request at all;
 *   - overlap → one request shared;
 *   - a sign-out or another user arriving mid-flight → the late answer is dropped;
 *   - 401 in the creator app → token cleared, user cleared, lapsed; any other
 *     failure → silent, the held user stays.
 */

import { ApiError } from '@catalyst/api-client'
import type { AuthApi, User } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const platform = vi.hoisted(() => ({ native: true }))

const tokenStore = vi.hoisted(() => ({
  read: vi.fn<() => Promise<string | null>>(),
  write: vi.fn<(token: string, expiresAt: string | null) => Promise<void>>(),
  clear: vi.fn<() => Promise<void>>(),
  hasLapsed: vi.fn<() => boolean>(),
}))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'ios' : null),
}))

vi.mock('@/core/auth/tokenStore', () => ({ nativeTokenStore: () => tokenStore }))

vi.mock('../api/auth.api', () => ({
  authApi: {
    me: vi.fn(),
    updateMe: vi.fn(),
    login: vi.fn(),
    tokenLogin: vi.fn(),
    requestAccountDeletion: vi.fn(),
    logout: vi.fn(),
    signUp: vi.fn(),
    verifyEmail: vi.fn(),
    resendVerification: vi.fn(),
    forgotPassword: vi.fn(),
    resetPassword: vi.fn(),
    enrollTotp: vi.fn(),
    verifyTotp: vi.fn(),
    disableTotp: vi.fn(),
    regenerateRecoveryCodes: vi.fn(),
  } satisfies AuthApi,
}))

import { authApi } from '../api/auth.api'
import { useAuthStore } from './useAuthStore'

const mocked = authApi as unknown as { [K in keyof AuthApi]: ReturnType<typeof vi.fn> }

function makeUser(id = '01HQVKWP0M4XKMJWR5J2PXKKKQ', name = 'Creator Account'): User {
  return {
    id,
    type: 'user',
    attributes: {
      email: 'creator@example.test',
      email_verified_at: '2026-04-30T12:00:00+00:00',
      name,
      last_name: null,
      user_type: 'creator',
      preferred_language: null,
      preferred_currency: 'USD',
      timezone: 'Europe/Lisbon',
      theme_preference: 'system',
      mfa_required: false,
      mfa_exempt: false,
      two_factor_enabled: false,
      last_login_at: null,
      created_at: '2026-04-15T09:00:00+00:00',
    },
  }
}

/** A signed-in, bootstrapped store (the state the app is in when it resumes). */
async function signedInStore(user: User = makeUser()) {
  tokenStore.read.mockResolvedValue('tok')
  mocked.me.mockResolvedValueOnce(user)
  const store = useAuthStore()
  await store.bootstrap()
  mocked.me.mockClear()
  return store
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

beforeEach(() => {
  setActivePinia(createPinia())
  platform.native = true
  for (const fn of Object.values(mocked)) {
    fn.mockReset()
  }
  tokenStore.read.mockReset().mockResolvedValue(null)
  tokenStore.write.mockReset().mockResolvedValue(undefined)
  tokenStore.clear.mockReset().mockResolvedValue(undefined)
  tokenStore.hasLapsed.mockReset().mockReturnValue(false)
})

describe('refreshSession() — signed in', () => {
  it('re-reads /me once and replaces the held user in place', async () => {
    const store = await signedInStore()
    mocked.me.mockResolvedValue(makeUser(undefined, 'Renamed Creator'))

    await store.refreshSession()

    expect(mocked.me).toHaveBeenCalledTimes(1)
    expect(store.user?.attributes.name).toBe('Renamed Creator')
    expect(store.bootstrapStatus).toBe('ready')
  })

  it('shares one request between overlapping calls', async () => {
    const store = await signedInStore()
    const pending = deferred<User>()
    mocked.me.mockReturnValue(pending.promise)

    const first = store.refreshSession()
    const second = store.refreshSession()
    pending.resolve(makeUser(undefined, 'Once'))
    await Promise.all([first, second])

    expect(mocked.me).toHaveBeenCalledTimes(1)
    expect(store.user?.attributes.name).toBe('Once')
  })

  it('allows a fresh request once the previous one settled', async () => {
    const store = await signedInStore()
    mocked.me.mockResolvedValue(makeUser())

    await store.refreshSession()
    await store.refreshSession()

    expect(mocked.me).toHaveBeenCalledTimes(2)
  })
})

describe('refreshSession() — not signed in', () => {
  it('does not call /me before the first bootstrap', async () => {
    const store = useAuthStore()

    await store.refreshSession()

    expect(mocked.me).not.toHaveBeenCalled()
  })

  it('does not call /me for an anonymous (bootstrapped, user-less) session', async () => {
    tokenStore.read.mockResolvedValue(null)
    const store = useAuthStore()
    await store.bootstrap()

    await store.refreshSession()

    expect(store.user).toBeNull()
    expect(mocked.me).not.toHaveBeenCalled()
  })
})

describe('refreshSession() — answers that arrive too late', () => {
  it('drops the answer when the user signed out while it was in flight', async () => {
    const store = await signedInStore()
    const pending = deferred<User>()
    mocked.me.mockReturnValue(pending.promise)

    const refreshing = store.refreshSession()
    store.clearUser()
    pending.resolve(makeUser())
    await refreshing

    expect(store.user).toBeNull()
  })

  it('drops the answer when a different user signed in while it was in flight', async () => {
    const store = await signedInStore()
    const pending = deferred<User>()
    mocked.me.mockReturnValue(pending.promise)

    const refreshing = store.refreshSession()
    store.setUser(makeUser('01OTHERUSER0000000000000000', 'Someone Else'))
    pending.resolve(makeUser(undefined, 'Stale First User'))
    await refreshing

    expect(store.user?.attributes.name).toBe('Someone Else')
  })
})

describe('refreshSession() — failures', () => {
  it('a 401 in the creator app drops the token and the user and marks the session lapsed', async () => {
    const store = await signedInStore()
    mocked.me.mockRejectedValue(
      ApiError.fromEnvelope(401, { errors: [{ status: '401', code: 'x', title: 'x' }] }),
    )

    await store.refreshSession()

    expect(tokenStore.clear).toHaveBeenCalledTimes(1)
    expect(store.user).toBeNull()
    expect(store.sessionLapsed).toBe(true)
  })

  it('a 401 on the web leaves the user alone (the web policy owns expiry)', async () => {
    platform.native = false
    const store = await signedInStore()
    mocked.me.mockRejectedValue(
      ApiError.fromEnvelope(401, { errors: [{ status: '401', code: 'x', title: 'x' }] }),
    )

    await store.refreshSession()

    expect(tokenStore.clear).not.toHaveBeenCalled()
    expect(store.user).not.toBeNull()
  })

  it('a network failure is silent and keeps the held user', async () => {
    const store = await signedInStore()
    mocked.me.mockRejectedValue(new TypeError('Network request failed'))

    await expect(store.refreshSession()).resolves.toBeUndefined()

    expect(store.user?.attributes.name).toBe('Creator Account')
    expect(tokenStore.clear).not.toHaveBeenCalled()
    expect(store.sessionLapsed).toBe(false)
  })

  it('a failed refresh does not wedge the next one', async () => {
    const store = await signedInStore()
    mocked.me.mockRejectedValueOnce(new TypeError('offline'))
    await store.refreshSession()
    mocked.me.mockResolvedValue(makeUser(undefined, 'Back Online'))

    await store.refreshSession()

    expect(store.user?.attributes.name).toBe('Back Online')
  })
})
