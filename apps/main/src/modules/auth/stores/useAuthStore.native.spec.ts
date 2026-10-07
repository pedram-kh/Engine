/**
 * AH-100 D2/D5 — the auth store inside the Capacitor creator app, and
 * the web polarity of the same branch points (the cookie paths never
 * touch the token store). The platform probe and the token store are
 * mocked; `useAuthStore.spec.ts` covers the web behaviour in depth.
 */

import { ApiError } from '@catalyst/api-client'
import type { AuthApi, User } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const platform = vi.hoisted(() => ({ native: true, os: 'ios' as 'ios' | 'android' }))

const tokenStore = vi.hoisted(() => ({
  read: vi.fn<() => Promise<string | null>>(),
  write: vi.fn<(token: string, expiresAt: string | null) => Promise<void>>(),
  clear: vi.fn<() => Promise<void>>(),
}))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? platform.os : null),
}))

vi.mock('@/core/auth/tokenStore', () => ({ nativeTokenStore: () => tokenStore }))

vi.mock('../api/auth.api', () => ({
  authApi: {
    me: vi.fn(),
    updateMe: vi.fn(),
    login: vi.fn(),
    tokenLogin: vi.fn(),
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

function makeUser(): User {
  return {
    id: '01HQVKWP0M4XKMJWR5J2PXKKKQ',
    type: 'user',
    attributes: {
      email: 'creator@example.test',
      email_verified_at: '2026-04-30T12:00:00+00:00',
      name: 'Creator Account',
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

function envelopeError(status: number, code: string): ApiError {
  return ApiError.fromEnvelope(status, { errors: [{ status: String(status), code, title: code }] })
}

beforeEach(() => {
  setActivePinia(createPinia())
  platform.native = true
  platform.os = 'ios'
  for (const fn of Object.values(mocked)) {
    fn.mockReset()
  }
  tokenStore.read.mockReset().mockResolvedValue(null)
  tokenStore.write.mockReset().mockResolvedValue(undefined)
  tokenStore.clear.mockReset().mockResolvedValue(undefined)
})

describe('native login', () => {
  it.each(['ios', 'android'] as const)(
    'POSTs /auth/token with platform %s, stores the token, then sets the user',
    async (os) => {
      platform.os = os
      const user = makeUser()
      const order: string[] = []
      mocked.tokenLogin.mockResolvedValue({ user, token: 'tok-1', expiresAt: '2027-01-01' })
      tokenStore.write.mockImplementation(async () => {
        order.push('write')
      })
      const store = useAuthStore()

      await store.login('a@b.c', 'pw')

      expect(mocked.tokenLogin).toHaveBeenCalledWith({
        email: 'a@b.c',
        password: 'pw',
        platform: os,
      })
      expect(mocked.login).not.toHaveBeenCalled()
      expect(tokenStore.write).toHaveBeenCalledWith('tok-1', '2027-01-01')
      expect(order).toEqual(['write'])
      expect(store.user).toEqual(user)
      expect(store.isLoggingIn).toBe(false)
    },
  )

  it('forwards the TOTP code as mfa_code', async () => {
    mocked.tokenLogin.mockResolvedValue({ user: makeUser(), token: 't', expiresAt: null })

    await useAuthStore().login('a@b.c', 'pw', '123456')

    expect(mocked.tokenLogin).toHaveBeenCalledWith(
      expect.objectContaining({ mfa_code: '123456', platform: 'ios' }),
    )
  })

  it.each([
    [403, 'auth.wrong_spa'],
    [401, 'auth.mfa_required'],
    [423, 'auth.account_locked.suspended'],
  ])('a %i %s envelope surfaces unchanged and stores nothing', async (status, code) => {
    mocked.tokenLogin.mockRejectedValue(envelopeError(status, code))
    const store = useAuthStore()

    await expect(store.login('a@b.c', 'pw')).rejects.toMatchObject({ status, code })
    expect(tokenStore.write).not.toHaveBeenCalled()
    expect(store.user).toBeNull()
    expect(store.isLoggingIn).toBe(false)
  })

  it('a failed token write fails the login and leaves the user signed out', async () => {
    mocked.tokenLogin.mockResolvedValue({ user: makeUser(), token: 't', expiresAt: null })
    tokenStore.write.mockRejectedValue(new Error('keychain write failed'))
    const store = useAuthStore()

    await expect(store.login('a@b.c', 'pw')).rejects.toThrow('keychain write failed')
    expect(store.user).toBeNull()
  })

  it('loginWithToken() refuses to run off-native', async () => {
    platform.native = false

    await expect(useAuthStore().loginWithToken('a@b.c', 'pw')).rejects.toThrow(/native-only/)
    expect(mocked.tokenLogin).not.toHaveBeenCalled()
  })
})

describe('native logout', () => {
  it('revokes, then clears the token and the user', async () => {
    mocked.logout.mockResolvedValue(undefined)
    const store = useAuthStore()
    store.setUser(makeUser())

    await store.logout()

    expect(mocked.logout).toHaveBeenCalledTimes(1)
    expect(tokenStore.clear).toHaveBeenCalledTimes(1)
    expect(store.user).toBeNull()
    expect(store.isLoggingOut).toBe(false)
  })

  it('still clears the token and the user when the revoke fails, without throwing', async () => {
    mocked.logout.mockRejectedValue(envelopeError(500, 'server.error'))
    tokenStore.clear.mockRejectedValue(new Error('keychain unavailable'))
    const store = useAuthStore()
    store.setUser(makeUser())

    await expect(store.logout()).resolves.toBeUndefined()
    expect(tokenStore.clear).toHaveBeenCalledTimes(1)
    expect(store.user).toBeNull()
  })
})

describe('native bootstrap', () => {
  it('with no stored token resolves anonymous without calling /me', async () => {
    const store = useAuthStore()

    await store.bootstrap()

    expect(mocked.me).not.toHaveBeenCalled()
    expect(store.user).toBeNull()
    expect(store.bootstrapStatus).toBe('ready')
  })

  it('with a stored token resolves the user from /me', async () => {
    tokenStore.read.mockResolvedValue('tok-1')
    mocked.me.mockResolvedValue(makeUser())
    const store = useAuthStore()

    await store.bootstrap()

    expect(store.user).not.toBeNull()
    expect(tokenStore.clear).not.toHaveBeenCalled()
  })

  it('a 401 from /me drops the dead token and resolves anonymous', async () => {
    tokenStore.read.mockResolvedValue('tok-dead')
    tokenStore.clear.mockRejectedValue(new Error('keychain unavailable'))
    mocked.me.mockRejectedValue(envelopeError(401, 'auth.unauthenticated'))
    const store = useAuthStore()

    await store.bootstrap()

    expect(tokenStore.clear).toHaveBeenCalledTimes(1)
    expect(store.user).toBeNull()
    expect(store.bootstrapStatus).toBe('ready')
  })
})

describe('web polarity — the cookie paths never touch the token store', () => {
  beforeEach(() => {
    platform.native = false
  })

  it('login() posts /auth/login, not /auth/token', async () => {
    mocked.login.mockResolvedValue(makeUser())

    await useAuthStore().login('a@b.c', 'pw')

    expect(mocked.login).toHaveBeenCalledTimes(1)
    expect(mocked.tokenLogin).not.toHaveBeenCalled()
    expect(tokenStore.write).not.toHaveBeenCalled()
  })

  it('logout() keeps the web contract: a non-401 failure rethrows and nothing is cleared from storage', async () => {
    mocked.logout.mockRejectedValue(envelopeError(500, 'server.error'))

    await expect(useAuthStore().logout()).rejects.toMatchObject({ status: 500 })
    expect(tokenStore.clear).not.toHaveBeenCalled()
  })

  it('bootstrap() asks /me without consulting a token, and a 401 clears nothing from storage', async () => {
    mocked.me.mockRejectedValue(envelopeError(401, 'auth.unauthenticated'))

    await useAuthStore().bootstrap()

    expect(mocked.me).toHaveBeenCalledTimes(1)
    expect(tokenStore.read).not.toHaveBeenCalled()
    expect(tokenStore.clear).not.toHaveBeenCalled()
  })
})
