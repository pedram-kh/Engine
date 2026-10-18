/**
 * AH-108 (4b) — every end of a session wipes the home-screen badge, on iOS only.
 *
 * The REAL `iconBadge` module runs here; only the Capacitor bridge is faked
 * (`registerPlugin` hands back a recording stub), so these specs prove the whole
 * chain: store → `clearIconBadge` → platform gate → plugin. Separate from
 * `useAuthStore.native.spec.ts`, which stays unedited.
 */

import { ApiError } from '@catalyst/api-client'
import type { AuthApi, User } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const platform = vi.hoisted(() => ({ kind: 'ios' as 'ios' | 'android' | null }))
const bridge = vi.hoisted(() => ({ set: vi.fn() }))

const tokenStore = vi.hoisted(() => ({
  read: vi.fn<() => Promise<string | null>>(),
  write: vi.fn<(token: string, expiresAt: string | null) => Promise<void>>(),
  clear: vi.fn<() => Promise<void>>(),
  hasLapsed: vi.fn<() => boolean>(),
}))

vi.mock('@capacitor/core', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@capacitor/core')>()),
  registerPlugin: () => bridge,
}))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.kind !== null,
  nativePlatform: () => platform.kind,
}))
vi.mock('@/core/platform/push/pushLogoutCleanup', () => ({
  pushLogoutCleanup: () => Promise.resolve(),
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
import { iconBadge } from '@/core/platform/badge/iconBadge'
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

async function signedInStore() {
  const store = useAuthStore()
  mocked.me.mockResolvedValue(makeUser())
  await store.bootstrap()
  expect(store.user).not.toBeNull()
  bridge.set.mockClear()
  return store
}

beforeEach(() => {
  setActivePinia(createPinia())
  platform.kind = 'ios'
  bridge.set.mockReset().mockResolvedValue({ applied: true, count: 0 })
  for (const fn of Object.values(mocked)) {
    fn.mockReset()
  }
  tokenStore.read.mockReset().mockResolvedValue('tok')
  tokenStore.clear.mockReset().mockResolvedValue(undefined)
  tokenStore.hasLapsed.mockReset().mockReturnValue(false)
  mocked.logout.mockResolvedValue(undefined)
  // The module singleton survives between tests; a fresh session re-enables it.
  iconBadge.enable()
})

describe('the icon badge and the end of a session (AH-108 4b)', () => {
  it('iOS sign-out writes 0 to the plugin', async () => {
    const store = await signedInStore()

    await store.logout()

    expect(bridge.set).toHaveBeenCalledWith({ count: 0 })
  })

  it('iOS: a lapsed session (401 on /me) also wipes the badge', async () => {
    const store = await signedInStore()
    mocked.me.mockRejectedValue(
      ApiError.fromEnvelope(401, { errors: [{ status: '401', code: 'x', title: 'x' }] }),
    )

    await store.refreshSession()

    expect(store.sessionLapsed).toBe(true)
    expect(bridge.set).toHaveBeenCalledWith({ count: 0 })
  })

  it('Android sign-out never calls the plugin', async () => {
    platform.kind = 'android'
    const store = await signedInStore()

    await store.logout()

    expect(bridge.set).not.toHaveBeenCalled()
  })

  it('web sign-out never calls the plugin', async () => {
    platform.kind = null
    const store = await signedInStore()

    await store.logout()

    expect(bridge.set).not.toHaveBeenCalled()
  })

  it('a poll response that lands after sign-out cannot revive the badge', async () => {
    const store = await signedInStore()
    await store.logout()
    bridge.set.mockClear()

    await iconBadge.set(5)

    expect(bridge.set).not.toHaveBeenCalled()
  })
})
