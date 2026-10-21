/**
 * AH-103d — the reinstall marker as the auth store sees it: the two gates
 * (`bootstrap()` before it reads the token, `loginWithToken()` before it writes
 * one), the wipe going through the REAL native sign-out, and the web polarity.
 * The token store is a small stateful fake so "wiped, then read" is observable.
 * `useAuthStore.native.spec.ts` stays unedited as proof the rest did not change.
 */

import type { AuthApi, User } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import {
  INSTALL_MARKER_KEY,
  localStorageMarker,
  resetInstallReconcileForTests,
} from '@/core/auth/installMarker'

const platform = vi.hoisted(() => ({ native: true }))
const calls = vi.hoisted(() => ({ order: [] as string[] }))
const held = vi.hoisted(() => ({ token: null as string | null }))

const tokenStore = vi.hoisted(() => ({
  read: vi.fn<() => Promise<string | null>>(),
  write: vi.fn<(token: string, expiresAt: string | null) => Promise<void>>(),
  clear: vi.fn<() => Promise<void>>(),
  hasLapsed: vi.fn<() => boolean>(),
}))
const cleanup = vi.hoisted(() => ({ run: vi.fn<() => Promise<void>>() }))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'ios' : null),
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

/** The persisted marker, read through the adapter (the architecture guard forbids direct storage calls in specs). */
function markerValue(): string | null {
  const read = localStorageMarker.read()
  return read.ok ? read.value : null
}

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
  resetInstallReconcileForTests()
  window.localStorage.clear() // the global setup sets the marker; these specs start as a reinstall
  platform.native = true
  calls.order.length = 0
  held.token = null
  for (const fn of Object.values(mocked)) {
    fn.mockReset()
  }
  tokenStore.read.mockReset().mockImplementation(async () => {
    calls.order.push('token.read')
    return held.token
  })
  tokenStore.write.mockReset().mockImplementation(async (token) => {
    calls.order.push('token.write')
    held.token = token
  })
  tokenStore.clear.mockReset().mockImplementation(async () => {
    calls.order.push('token.clear')
    held.token = null
  })
  tokenStore.hasLapsed.mockReset().mockReturnValue(false)
  cleanup.run.mockReset().mockImplementation(async () => {
    calls.order.push('push.cleanup')
  })
  mocked.logout.mockImplementation(async () => {
    calls.order.push('authApi.logout')
  })
})

describe('bootstrap() waits for the reconcile before it reads the token', () => {
  it('a reinstall (marker absent, token held): the REAL sign-out runs first, then bootstrap reads null and never calls /me', async () => {
    held.token = 'previous-owner-token'
    const store = useAuthStore()

    await store.bootstrap()

    // push cleanup (needs the bearer) → revoke → clear (the nativeLogout order), and
    // only then bootstrap's own read, which now finds nothing.
    expect(calls.order.slice(0, 5)).toEqual([
      'token.read', // the reconcile's decision read
      'push.cleanup',
      'authApi.logout',
      'token.clear',
      'token.read', // bootstrap's read, AFTER the wipe
    ])
    expect(mocked.me).not.toHaveBeenCalled()
    expect(store.user).toBeNull()
    expect(store.bootstrapStatus).toBe('ready')
    expect(markerValue()).not.toBeNull()
  })

  it('the marker is written only after the sign-out completed', async () => {
    held.token = 'previous-owner-token'
    let markerAtRevoke: string | null = 'unset'
    mocked.logout.mockImplementation(async () => {
      markerAtRevoke = markerValue()
    })
    await useAuthStore().bootstrap()
    expect(markerAtRevoke).toBeNull()
    expect(markerValue()).not.toBeNull()
  })

  it('a slow reconcile HOLDS bootstrap: the token is not read until the decision is made', async () => {
    held.token = 'previous-owner-token'
    let releaseRevoke: () => void = () => undefined
    mocked.logout.mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          releaseRevoke = resolve
        }),
    )
    const store = useAuthStore()
    const boot = store.bootstrap()

    await vi.waitFor(() => expect(mocked.logout).toHaveBeenCalled())
    expect(tokenStore.read).toHaveBeenCalledTimes(1) // only the reconcile's read so far
    expect(store.bootstrapStatus).not.toBe('ready')

    releaseRevoke()
    await boot
    expect(tokenStore.read).toHaveBeenCalledTimes(2)
    expect(store.user).toBeNull()
  })

  it('marker present (every normal launch): nothing is signed out and the token is read once', async () => {
    localStorageMarker.write('2026-10-01T00:00:00.000Z')
    held.token = 'my-token'
    mocked.me.mockResolvedValue(makeUser())

    await useAuthStore().bootstrap()

    expect(mocked.logout).not.toHaveBeenCalled()
    expect(cleanup.run).not.toHaveBeenCalled()
    expect(tokenStore.clear).not.toHaveBeenCalled()
    expect(tokenStore.read).toHaveBeenCalledTimes(1)
    expect(mocked.me).toHaveBeenCalledTimes(1)
  })

  it('a fresh install (no marker, no token): the marker is written and NO request is made', async () => {
    await useAuthStore().bootstrap()

    expect(mocked.logout).not.toHaveBeenCalled()
    expect(mocked.me).not.toHaveBeenCalled()
    expect(markerValue()).not.toBeNull()
  })
})

describe('loginWithToken() waits for the reconcile before it writes a token', () => {
  it('the sign-in page can be reached without a bootstrap: a pending reconcile must not wipe the NEW session', async () => {
    held.token = 'previous-owner-token'
    let releaseRevoke: () => void = () => undefined
    mocked.logout.mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          releaseRevoke = resolve
        }),
    )
    mocked.tokenLogin.mockResolvedValue({
      user: makeUser(),
      token: 'new-token',
      expiresAt: '2027-01-01',
    })
    const store = useAuthStore()

    void store.reconcileInstall() // what main.ts starts at boot
    await vi.waitFor(() => expect(mocked.logout).toHaveBeenCalled())

    const login = store.loginWithToken('a@b.c', 'pw')
    await vi.waitFor(() => expect(mocked.tokenLogin).toHaveBeenCalled())
    expect(tokenStore.write).not.toHaveBeenCalled() // held behind the reconcile

    releaseRevoke()
    await login

    expect(calls.order.indexOf('token.clear')).toBeLessThan(calls.order.indexOf('token.write'))
    expect(held.token).toBe('new-token') // the new session survived
    expect(store.user).not.toBeNull()
  })

  it('with the marker present the write is not delayed and nothing is signed out', async () => {
    localStorageMarker.write('x')
    mocked.tokenLogin.mockResolvedValue({
      user: makeUser(),
      token: 'new-token',
      expiresAt: '2027-01-01',
    })
    await useAuthStore().loginWithToken('a@b.c', 'pw')
    expect(mocked.logout).not.toHaveBeenCalled()
    expect(held.token).toBe('new-token')
  })
})

describe('the reconcile is shared, lazy and never a second wipe', () => {
  it('bootstrap + login + the eager start share ONE run: the sign-out happens once', async () => {
    held.token = 'previous-owner-token'
    mocked.tokenLogin.mockResolvedValue({
      user: makeUser(),
      token: 'new-token',
      expiresAt: '2027-01-01',
    })
    const store = useAuthStore()

    await Promise.all([store.reconcileInstall(), store.bootstrap()])
    await store.loginWithToken('a@b.c', 'pw')
    await store.reconcileInstall()

    expect(mocked.logout).toHaveBeenCalledTimes(1)
    expect(held.token).toBe('new-token')
  })

  it('is lazy: with no eager start, the first gate starts it (deleting the main.ts line cannot silently disable the protection)', async () => {
    held.token = 'previous-owner-token'
    await useAuthStore().bootstrap() // reconcileInstall() was never called by the test
    expect(mocked.logout).toHaveBeenCalledTimes(1)
  })
})

describe('the web SPA is untouched', () => {
  it('reconcileInstall() resolves skipped-web and touches no storage, no token store, no request', async () => {
    platform.native = false
    const getItem = vi.spyOn(Storage.prototype, 'getItem')
    const setItem = vi.spyOn(Storage.prototype, 'setItem')

    await expect(useAuthStore().reconcileInstall()).resolves.toBe('skipped-web')

    expect(getItem).not.toHaveBeenCalledWith(INSTALL_MARKER_KEY)
    expect(setItem).not.toHaveBeenCalledWith(INSTALL_MARKER_KEY, expect.anything())
    expect(tokenStore.read).not.toHaveBeenCalled()
    expect(mocked.logout).not.toHaveBeenCalled()
    getItem.mockRestore()
    setItem.mockRestore()
  })
})
