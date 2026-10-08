/**
 * AH-100b D3 — a deliberate sign-out never paints "Your session has
 * expired", and a genuine mid-session 401 still does.
 *
 * The walkthrough finding: logging out of the creator app landed on the
 * sign-in screen WITH the session-expired banner. Deliberate logout never
 * writes `?reason=session_expired` itself; the banner comes from the
 * native 401 policy firing for a 401 that lands while — or just after —
 * the user signs out (a poll or fetch still in flight with the token the
 * revoke just killed, or a second revoke from a double tap).
 *
 * Which request raced on the device is unknowable and does not matter:
 * these tests drive the REAL auth store and the REAL policy, hold a
 * request open across the logout, and pin that the policy acts only when
 * there is a session to expire. Both polarities are pinned.
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const platform = vi.hoisted(() => ({ native: true }))

const tokenStore = vi.hoisted(() => {
  let token: string | null = null
  return {
    set: (value: string | null) => {
      token = value
    },
    read: vi.fn(async () => token),
    write: vi.fn(async (value: string) => {
      token = value
    }),
    clear: vi.fn(async () => {
      token = null
    }),
  }
})

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'ios' : null),
}))
vi.mock('@/core/auth/tokenStore', () => ({ nativeTokenStore: () => tokenStore }))
vi.mock('@/modules/auth/api/auth.api', () => ({
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
  },
}))

import {
  createNativeSessionStateReader,
  createNativeUnauthorizedPolicy,
  SESSION_EXPIRED_QUERY_REASON,
} from '@/core/api'
import { authApi } from '@/modules/auth/api/auth.api'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

const logoutApi = (authApi as unknown as { logout: ReturnType<typeof vi.fn> }).logout

const CREATOR = {
  type: 'user' as const,
  id: '01HQ',
  attributes: {
    email: 'creator@example.test',
    email_verified_at: '2026-01-01T00:00:00Z',
    name: 'Creator',
    last_name: null,
    user_type: 'creator' as const,
    preferred_language: null,
    preferred_currency: 'USD',
    timezone: 'Europe/Lisbon',
    theme_preference: 'system' as const,
    mfa_required: false,
    mfa_exempt: false,
    two_factor_enabled: false,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
}

function wirePolicy() {
  const store = useAuthStore()
  const push = vi.fn(() => Promise.resolve())
  const policy = createNativeUnauthorizedPolicy({
    getStore: async () => store,
    getRouter: async () => ({ push }),
    clearToken: () => tokenStore.clear(),
    getSessionState: createNativeSessionStateReader({
      loadStore: async () => store,
      readToken: () => tokenStore.read(),
    }),
  })
  return { store, push, policy }
}

/** A promise the test settles by hand — the "request still in flight". */
function deferred() {
  let resolve!: () => void
  const promise = new Promise<void>((r) => {
    resolve = r
  })
  return { promise, resolve }
}

beforeEach(() => {
  setActivePinia(createPinia())
  platform.native = true
  tokenStore.set(null)
  tokenStore.read.mockClear()
  tokenStore.write.mockClear()
  tokenStore.clear.mockClear()
  logoutApi.mockReset()
})

describe('a 401 that races a deliberate sign-out is not "session expired"', () => {
  it('held-open request: a 401 landing WHILE the revoke is in flight acts on nothing', async () => {
    const { store, push, policy } = wirePolicy()
    store.setUser(CREATOR)
    tokenStore.set('tok-1')
    const revoke = deferred()
    logoutApi.mockReturnValue(revoke.promise)

    const signingOut = store.logout()
    // The user and the token are both still held; only the deliberate-logout flag says "leave it".
    expect(store.isLoggingOut).toBe(true)
    expect(store.user).not.toBeNull()

    await policy('/creators/me/notifications/unread-count')

    expect(push).not.toHaveBeenCalled()
    revoke.resolve()
    await signingOut
    expect(store.user).toBeNull()
  })

  it('held-open request: a 401 landing just AFTER the sign-out finished acts on nothing', async () => {
    const { store, push, policy } = wirePolicy()
    store.setUser(CREATOR)
    tokenStore.set('tok-1')
    logoutApi.mockResolvedValue(undefined)
    tokenStore.clear.mockClear()

    await store.logout()
    expect(store.user).toBeNull()
    expect(await tokenStore.read()).toBeNull()
    tokenStore.clear.mockClear()

    await policy('/creators/me/availability')

    expect(push).not.toHaveBeenCalled()
    expect(tokenStore.clear).not.toHaveBeenCalled()
  })

  it('a revoke that failed still ends with no session, so a late 401 stays quiet', async () => {
    const { store, push, policy } = wirePolicy()
    store.setUser(CREATOR)
    tokenStore.set('tok-1')
    logoutApi.mockRejectedValue(new Error('network down'))

    await store.logout()
    await policy('/creators/me')

    expect(push).not.toHaveBeenCalled()
  })

  it('double-tapped Sign out: the second revoke is refused with a 401 and that is not an expiry', async () => {
    const { store, push, policy } = wirePolicy()
    store.setUser(CREATOR)
    tokenStore.set('tok-1')

    // The 401 is for `/auth/logout` itself, so it is exempt even with a session still held.
    await policy('/auth/logout')

    expect(store.user).not.toBeNull()
    expect(push).not.toHaveBeenCalled()
  })
})

describe('a genuine involuntary termination still banners', () => {
  it('a mid-session 401 (token revoked server-side, user still signed in) clears and routes with the reason', async () => {
    const { store, push, policy } = wirePolicy()
    store.setUser(CREATOR)
    tokenStore.set('tok-revoked-elsewhere')

    await policy('/creators/me')

    expect(store.user).toBeNull()
    expect(tokenStore.clear).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: { reason: SESSION_EXPIRED_QUERY_REASON },
    })
  })

  it('a token still held with no user (the window around bootstrap) is a session to expire', async () => {
    const { push, policy } = wirePolicy()
    tokenStore.set('tok-dead')

    await policy('/creators/me')

    expect(push).toHaveBeenCalledTimes(1)
  })

  it('a user still held with the token already unreadable is a session to expire', async () => {
    const { store, push, policy } = wirePolicy()
    store.setUser(CREATOR)

    await policy('/creators/me')

    expect(push).toHaveBeenCalledTimes(1)
  })

  it('a token store that throws on read reads as "no token" and does not crash the policy', async () => {
    const { store, push, policy } = wirePolicy()
    store.setUser(CREATOR)
    tokenStore.read.mockRejectedValueOnce(new Error('keychain unavailable'))

    await policy('/creators/me')

    expect(push).toHaveBeenCalledTimes(1)
  })
})
