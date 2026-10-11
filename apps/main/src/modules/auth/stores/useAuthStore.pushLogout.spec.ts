/**
 * AH-102 (R-4) — the push cleanup on native sign-out: it runs BEFORE the token
 * is cleared (the `DELETE /me/push-tokens` needs the bearer), a failing cleanup
 * never blocks sign-out, and the web sign-out never touches it. Separate from
 * `useAuthStore.native.spec.ts`, which stays unedited as the proof that the rest
 * of native logout did not change.
 */

import type { AuthApi, User } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const platform = vi.hoisted(() => ({ native: true }))
const calls = vi.hoisted(() => ({ order: [] as string[] }))

const tokenStore = vi.hoisted(() => ({
  read: vi.fn<() => Promise<string | null>>(),
  write: vi.fn<(token: string, expiresAt: string | null) => Promise<void>>(),
  clear: vi.fn<() => Promise<void>>(),
  hasLapsed: vi.fn<() => boolean>(),
}))
const cleanup = vi.hoisted(() => ({ run: vi.fn<() => Promise<void>>() }))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'android' : null),
}))
vi.mock('@/core/auth/tokenStore', () => ({ nativeTokenStore: () => tokenStore }))
vi.mock('@/core/platform/push/pushLogoutCleanup', () => ({
  pushLogoutCleanup: () => cleanup.run(),
}))
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

beforeEach(() => {
  setActivePinia(createPinia())
  platform.native = true
  calls.order.length = 0
  for (const fn of Object.values(mocked)) {
    fn.mockReset()
  }
  tokenStore.clear.mockReset().mockImplementation(async () => void calls.order.push('token.clear'))
  tokenStore.hasLapsed.mockReset().mockReturnValue(false)
  mocked.logout.mockImplementation(async () => void calls.order.push('api.logout'))
  cleanup.run.mockReset().mockImplementation(async () => void calls.order.push('push.cleanup'))
})

describe('native sign-out and the push cleanup', () => {
  it('cleans up push BEFORE the revoke and BEFORE the token is cleared', async () => {
    const store = useAuthStore()
    store.setUser(makeUser())

    await store.logout()

    expect(calls.order).toEqual(['push.cleanup', 'api.logout', 'token.clear'])
    expect(store.user).toBeNull()
  })

  it('signs out normally when the cleanup throws (sign-out is never blocked)', async () => {
    cleanup.run.mockRejectedValue(new Error('push bridge exploded'))
    const store = useAuthStore()
    store.setUser(makeUser())

    await expect(store.logout()).resolves.toBeUndefined()

    expect(mocked.logout).toHaveBeenCalledTimes(1)
    expect(tokenStore.clear).toHaveBeenCalledTimes(1)
    expect(store.user).toBeNull()
  })

  it('web sign-out never touches push', async () => {
    platform.native = false
    mocked.logout.mockResolvedValue(undefined)
    const store = useAuthStore()
    store.setUser(makeUser())

    await store.logout()

    expect(cleanup.run).not.toHaveBeenCalled()
    expect(tokenStore.clear).not.toHaveBeenCalled()
  })
})
