/**
 * AH-104b-del B4 — `requestAccountDeletion` on the store.
 *
 * The server revokes every session and token when it accepts the request, so the success
 * path clears the local state DIRECTLY: no `authApi.logout()` (it would 401 into the
 * session-lapsed policy and sign the creator out with an error banner), and on the native
 * app no `DELETE /me/push-tokens` either (the bearer is gone). A failure clears nothing, so
 * the dialog can show the error and the creator is still signed in.
 */

import { ApiError, type AuthApi, type User } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const platform = vi.hoisted(() => ({ native: false }))
const calls = vi.hoisted(() => ({ order: [] as string[] }))

const tokenStore = vi.hoisted(() => ({
  read: vi.fn<() => Promise<string | null>>(),
  write: vi.fn<(token: string, expiresAt: string | null) => Promise<void>>(),
  clear: vi.fn<() => Promise<void>>(),
  hasLapsed: vi.fn<() => boolean>(),
}))
const push = vi.hoisted(() => ({
  cleanup: vi.fn<() => Promise<void>>(),
  forgetLocal: vi.fn<() => Promise<void>>(),
}))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'android' : null),
}))
vi.mock('@/core/auth/tokenStore', () => ({ nativeTokenStore: () => tokenStore }))
vi.mock('@/core/platform/push/pushLogoutCleanup', () => ({
  pushLogoutCleanup: () => push.cleanup(),
  pushForgetLocal: () => push.forgetLocal(),
}))
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

const RESULT = {
  requestedAt: '2026-10-21T10:00:00+00:00',
  scheduledFor: '2026-11-04T10:00:00+00:00',
}
const BODY = { password: 'Pa$$w0rd!12', acknowledged: true as const }

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
      features: { account_deletion: true },
    },
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  platform.native = false
  calls.order.length = 0
  for (const fn of Object.values(mocked)) {
    fn.mockReset()
  }
  tokenStore.clear.mockReset().mockImplementation(async () => void calls.order.push('token.clear'))
  push.cleanup.mockReset().mockImplementation(async () => void calls.order.push('push.cleanup'))
  push.forgetLocal
    .mockReset()
    .mockImplementation(async () => void calls.order.push('push.forgetLocal'))
  mocked.requestAccountDeletion.mockImplementation(async () => {
    calls.order.push('api.requestAccountDeletion')
    return RESULT
  })
  mocked.logout.mockImplementation(async () => void calls.order.push('api.logout'))
})

describe('requestAccountDeletion', () => {
  it('web: returns the dates, clears the user, and never calls logout', async () => {
    const store = useAuthStore()
    store.setUser(makeUser())

    const result = await store.requestAccountDeletion(BODY)

    expect(result).toEqual(RESULT)
    expect(store.user).toBeNull()
    expect(store.sessionLapsed).toBe(false)
    expect(mocked.logout).not.toHaveBeenCalled()
    expect(tokenStore.clear).not.toHaveBeenCalled()
    expect(push.forgetLocal).not.toHaveBeenCalled()
  })

  it('native: forgets the push record locally, clears the token, makes NO push or logout call', async () => {
    platform.native = true
    const store = useAuthStore()
    store.setUser(makeUser())

    await store.requestAccountDeletion(BODY)

    expect(calls.order).toEqual(['api.requestAccountDeletion', 'push.forgetLocal', 'token.clear'])
    expect(push.cleanup).not.toHaveBeenCalled() // the server-side DELETE would 401
    expect(mocked.logout).not.toHaveBeenCalled()
    expect(store.user).toBeNull()
  })

  it('native: a failing local cleanup still ends with the user cleared', async () => {
    platform.native = true
    push.forgetLocal.mockRejectedValue(new Error('push bridge exploded'))
    tokenStore.clear.mockRejectedValue(new Error('keystore'))
    const store = useAuthStore()
    store.setUser(makeUser())

    await expect(store.requestAccountDeletion(BODY)).resolves.toEqual(RESULT)
    expect(store.user).toBeNull()
  })

  it.each([
    ['wrong password', 403, 'auth.invalid_credentials'],
    ['rate limited', 429, 'rate_limit.exceeded'],
    ['blocked', 422, 'account_deletion.blocked'],
  ])('a failure (%s) rethrows and leaves the user signed in', async (_label, status, code) => {
    platform.native = true
    mocked.requestAccountDeletion.mockRejectedValue(new ApiError({ status, code, message: code }))
    const store = useAuthStore()
    store.setUser(makeUser())

    await expect(store.requestAccountDeletion(BODY)).rejects.toMatchObject({ code })

    expect(store.user).not.toBeNull()
    expect(tokenStore.clear).not.toHaveBeenCalled()
    expect(push.forgetLocal).not.toHaveBeenCalled()
  })
})
