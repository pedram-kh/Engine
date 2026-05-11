/**
 * Unit tests for the {@link useAdminAuthStore} Pinia store. The
 * api-client surface is mocked at the module level so the store can be
 * exercised without a live HTTP transport — every call to `authApi.*`
 * runs against `vi.fn()` doubles.
 *
 * Mirrors the main SPA's `useAuthStore.spec.ts` shape (chunk 6.2-6.4)
 * narrowed to the admin store's action surface. The out-of-admin-scope
 * actions (signUp / verifyEmail / resendVerification / forgotPassword
 * / resetPassword) are not exposed by the admin store and therefore
 * have no tests here. Their absence is enforced by typecheck.
 */

import { ApiError } from '@catalyst/api-client'
import type { User } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAdminAuthStore } from './useAdminAuthStore'

// The store imports `authApi` from `../api/admin-auth.api`. Mock that
// module so each test can drive the doubles directly. The mock shape
// covers every method the store reaches for — the admin store does NOT
// call signUp / verifyEmail / resendVerification / forgotPassword /
// resetPassword, but `satisfies` is intentionally NOT applied so the
// test surface stays narrow to what the admin store consumes.
vi.mock('../api/admin-auth.api', () => ({
  authApi: {
    me: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    enrollTotp: vi.fn(),
    verifyTotp: vi.fn(),
    disableTotp: vi.fn(),
    regenerateRecoveryCodes: vi.fn(),
  },
}))

import { authApi } from '../api/admin-auth.api'

type AdminAuthApiMock = {
  me: ReturnType<typeof vi.fn>
  login: ReturnType<typeof vi.fn>
  logout: ReturnType<typeof vi.fn>
  enrollTotp: ReturnType<typeof vi.fn>
  verifyTotp: ReturnType<typeof vi.fn>
  disableTotp: ReturnType<typeof vi.fn>
  regenerateRecoveryCodes: ReturnType<typeof vi.fn>
}

const mocked = authApi as unknown as AdminAuthApiMock

function makeAdminUser(overrides: Partial<User['attributes']> = {}): User {
  return {
    id: '01HQVKWP0M4XKMJWR5J2PXKKKQ',
    type: 'user',
    attributes: {
      email: 'admin@example.test',
      email_verified_at: '2026-04-30T12:00:00+00:00',
      name: 'Platform Admin',
      user_type: 'platform_admin',
      preferred_language: 'en',
      preferred_currency: 'USD',
      timezone: 'Europe/Lisbon',
      theme_preference: 'system',
      mfa_required: true,
      two_factor_enabled: true,
      last_login_at: '2026-05-01T08:14:21+00:00',
      created_at: '2026-04-15T09:00:00+00:00',
      ...overrides,
    },
  }
}

function envelopeError(status: number, code: string): ApiError {
  return ApiError.fromEnvelope(status, {
    errors: [{ status: String(status), code, title: code }],
  })
}

describe('useAdminAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    for (const fn of Object.values(mocked)) {
      fn.mockReset()
    }
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  describe('initial state', () => {
    it('starts with idle bootstrap status, no user, and clean loading flags', () => {
      const store = useAdminAuthStore()
      expect(store.user).toBeNull()
      expect(store.bootstrapStatus).toBe('idle')
      expect(store.mfaEnrollmentRequired).toBe(false)
      expect(store.isLoggingIn).toBe(false)
      expect(store.isLoggingOut).toBe(false)
      expect(store.isEnrollingTotp).toBe(false)
      expect(store.isVerifyingTotp).toBe(false)
      expect(store.isDisablingTotp).toBe(false)
      expect(store.isRegeneratingRecoveryCodes).toBe(false)
      expect(store.isAuthenticated).toBe(false)
      expect(store.userType).toBeNull()
      expect(store.isMfaEnrolled).toBe(false)
    })
  })

  describe('setUser() / clearUser()', () => {
    it('setUser(user) populates user, flips isAuthenticated, exposes user_type', () => {
      const store = useAdminAuthStore()
      const user = makeAdminUser()

      store.setUser(user)

      expect(store.user).toEqual(user)
      expect(store.isAuthenticated).toBe(true)
      expect(store.userType).toBe('platform_admin')
      expect(store.mfaEnrollmentRequired).toBe(false)
    })

    it('setUser() clears any prior mfaEnrollmentRequired flag', () => {
      const store = useAdminAuthStore()
      store.mfaEnrollmentRequired = true

      store.setUser(makeAdminUser())

      expect(store.mfaEnrollmentRequired).toBe(false)
    })

    it('isMfaEnrolled reflects user.attributes.two_factor_enabled', () => {
      const store = useAdminAuthStore()
      store.setUser(makeAdminUser({ two_factor_enabled: false }))
      expect(store.isMfaEnrolled).toBe(false)
      store.setUser(makeAdminUser({ two_factor_enabled: true }))
      expect(store.isMfaEnrolled).toBe(true)
    })

    it('clearUser() resets the user and the mfaEnrollmentRequired flag', () => {
      const store = useAdminAuthStore()
      store.setUser(makeAdminUser())
      store.mfaEnrollmentRequired = true

      store.clearUser()

      expect(store.user).toBeNull()
      expect(store.isAuthenticated).toBe(false)
      expect(store.mfaEnrollmentRequired).toBe(false)
    })
  })

  describe('bootstrap()', () => {
    it('on 200 stores the user and ends with status=ready', async () => {
      const store = useAdminAuthStore()
      const user = makeAdminUser()
      mocked.me.mockResolvedValueOnce(user)

      await store.bootstrap()

      expect(store.user).toEqual(user)
      expect(store.bootstrapStatus).toBe('ready')
      expect(store.mfaEnrollmentRequired).toBe(false)
    })

    it('on 401 clears the user and ends with status=ready (NOT error)', async () => {
      const store = useAdminAuthStore()
      mocked.me.mockRejectedValueOnce(envelopeError(401, 'auth.unauthenticated'))

      await store.bootstrap()

      expect(store.user).toBeNull()
      expect(store.bootstrapStatus).toBe('ready')
      expect(store.mfaEnrollmentRequired).toBe(false)
    })

    it('on 403 auth.mfa.enrollment_required exposes the derived flag and ends with status=ready', async () => {
      const store = useAdminAuthStore()
      mocked.me.mockRejectedValueOnce(envelopeError(403, 'auth.mfa.enrollment_required'))

      await store.bootstrap()

      expect(store.bootstrapStatus).toBe('ready')
      expect(store.mfaEnrollmentRequired).toBe(true)
      // No user payload on this branch — backend does NOT include one
      // when an admin needs to enrol 2FA.
      expect(store.user).toBeNull()
    })

    it('on 403 with an unrelated code rethrows as error (NOT enrollment-required)', async () => {
      const store = useAdminAuthStore()
      const err = envelopeError(403, 'auth.forbidden')
      mocked.me.mockRejectedValueOnce(err)

      await expect(store.bootstrap()).rejects.toBe(err)
      expect(store.bootstrapStatus).toBe('error')
      expect(store.mfaEnrollmentRequired).toBe(false)
    })

    it('on a 500 sets status=error and rethrows', async () => {
      const store = useAdminAuthStore()
      const err = envelopeError(500, 'server.error')
      mocked.me.mockRejectedValueOnce(err)

      await expect(store.bootstrap()).rejects.toBe(err)
      expect(store.bootstrapStatus).toBe('error')
    })

    it('on a network error sets status=error and rethrows', async () => {
      const store = useAdminAuthStore()
      const err = ApiError.fromNetworkError(new Error('ECONNREFUSED'))
      mocked.me.mockRejectedValueOnce(err)

      await expect(store.bootstrap()).rejects.toBe(err)
      expect(store.bootstrapStatus).toBe('error')
    })

    it('dedupes two concurrent calls to a single me() invocation', async () => {
      const store = useAdminAuthStore()
      const user = makeAdminUser()
      let resolve: (value: User) => void = () => {}
      mocked.me.mockReturnValueOnce(
        new Promise<User>((r) => {
          resolve = r
        }),
      )

      const first = store.bootstrap()
      const second = store.bootstrap()
      expect(mocked.me).toHaveBeenCalledTimes(1)
      resolve(user)
      await Promise.all([first, second])

      expect(mocked.me).toHaveBeenCalledTimes(1)
      expect(store.user).toEqual(user)
    })

    it('reissues a fresh me() call after the first promise settles', async () => {
      const store = useAdminAuthStore()
      mocked.me.mockResolvedValueOnce(makeAdminUser())
      mocked.me.mockResolvedValueOnce(makeAdminUser({ name: 'Renamed Admin' }))

      await store.bootstrap()
      await store.bootstrap()

      expect(mocked.me).toHaveBeenCalledTimes(2)
    })

    it('clears the in-flight cache even when the call rejects, so a retry is possible', async () => {
      const store = useAdminAuthStore()
      mocked.me.mockRejectedValueOnce(envelopeError(500, 'server.error'))
      mocked.me.mockResolvedValueOnce(makeAdminUser())

      await expect(store.bootstrap()).rejects.toBeInstanceOf(ApiError)
      await store.bootstrap()

      expect(mocked.me).toHaveBeenCalledTimes(2)
      expect(store.user).not.toBeNull()
    })
  })

  describe('login()', () => {
    it('forwards email + password and calls setUser on success', async () => {
      const store = useAdminAuthStore()
      const user = makeAdminUser()
      mocked.login.mockResolvedValueOnce(user)

      await store.login('admin@example.test', 'pw')

      expect(mocked.login).toHaveBeenCalledWith({
        email: 'admin@example.test',
        password: 'pw',
      })
      expect(store.user).toEqual(user)
      expect(store.isAuthenticated).toBe(true)
      expect(store.isLoggingIn).toBe(false)
    })

    it('forwards an optional totpCode as mfa_code', async () => {
      const store = useAdminAuthStore()
      mocked.login.mockResolvedValueOnce(makeAdminUser())

      await store.login('admin@example.test', 'pw', '123456')

      expect(mocked.login).toHaveBeenCalledWith({
        email: 'admin@example.test',
        password: 'pw',
        mfa_code: '123456',
      })
    })

    it('toggles isLoggingIn during the call', async () => {
      const store = useAdminAuthStore()
      let resolve: (value: User) => void = () => {}
      mocked.login.mockReturnValueOnce(
        new Promise<User>((r) => {
          resolve = r
        }),
      )

      const promise = store.login('admin@example.test', 'pw')
      expect(store.isLoggingIn).toBe(true)
      resolve(makeAdminUser())
      await promise
      expect(store.isLoggingIn).toBe(false)
    })

    it('rethrows ApiError without setting the user', async () => {
      const store = useAdminAuthStore()
      mocked.login.mockRejectedValueOnce(envelopeError(401, 'auth.invalid_credentials'))

      await expect(store.login('admin@example.test', 'wrong')).rejects.toMatchObject({
        code: 'auth.invalid_credentials',
      })
      expect(store.user).toBeNull()
      expect(store.isLoggingIn).toBe(false)
    })

    it('surfaces auth.mfa_required so the caller can re-submit with a code', async () => {
      const store = useAdminAuthStore()
      mocked.login.mockRejectedValueOnce(envelopeError(401, 'auth.mfa_required'))

      await expect(store.login('admin@example.test', 'pw')).rejects.toMatchObject({
        code: 'auth.mfa_required',
      })
      expect(store.user).toBeNull()
    })
  })

  describe('logout()', () => {
    it('POSTs logout, clears the user, and clears mfaEnrollmentRequired', async () => {
      const store = useAdminAuthStore()
      store.setUser(makeAdminUser())
      store.mfaEnrollmentRequired = true
      mocked.logout.mockResolvedValueOnce(undefined)

      await store.logout()

      expect(mocked.logout).toHaveBeenCalledTimes(1)
      expect(store.user).toBeNull()
      expect(store.mfaEnrollmentRequired).toBe(false)
      expect(store.isLoggingOut).toBe(false)
    })

    it('treats a 401 from /admin/auth/logout as success (session already gone)', async () => {
      const store = useAdminAuthStore()
      store.setUser(makeAdminUser())
      mocked.logout.mockRejectedValueOnce(envelopeError(401, 'auth.unauthenticated'))

      await store.logout()

      expect(store.user).toBeNull()
    })

    it('rethrows non-401 ApiError without clearing the user', async () => {
      const store = useAdminAuthStore()
      const user = makeAdminUser()
      store.setUser(user)
      mocked.logout.mockRejectedValueOnce(envelopeError(500, 'server.error'))

      await expect(store.logout()).rejects.toMatchObject({ status: 500 })
      expect(store.user).toEqual(user)
      expect(store.isLoggingOut).toBe(false)
    })

    it('rethrows an unrelated thrown value as-is (defensive non-ApiError path)', async () => {
      const store = useAdminAuthStore()
      store.setUser(makeAdminUser())
      const odd = new TypeError('not an api error')
      mocked.logout.mockRejectedValueOnce(odd)

      await expect(store.logout()).rejects.toBe(odd)
      expect(store.user).not.toBeNull()
    })
  })

  describe('TOTP enrollment', () => {
    it('enrollTotp() returns the provisional payload directly to the caller', async () => {
      const store = useAdminAuthStore()
      const payload = {
        provisional_token: 'prov-tok',
        otpauth_url: 'otpauth://totp/Catalyst-Admin:admin?secret=ABCDEFGH',
        qr_code_svg: '<svg/>',
        manual_entry_key: 'ABCD EFGH IJKL MNOP',
        expires_in_seconds: 600,
      }
      mocked.enrollTotp.mockResolvedValueOnce(payload)

      const result = await store.enrollTotp()

      expect(result).toBe(payload)
    })

    it('enrollTotp() rethrows auth.mfa.already_enabled verbatim', async () => {
      const store = useAdminAuthStore()
      mocked.enrollTotp.mockRejectedValueOnce(envelopeError(409, 'auth.mfa.already_enabled'))

      await expect(store.enrollTotp()).rejects.toMatchObject({
        code: 'auth.mfa.already_enabled',
      })
    })

    it('verifyTotp() returns recovery codes and refreshes the user via me()', async () => {
      const store = useAdminAuthStore()
      const initial = makeAdminUser({ two_factor_enabled: false })
      const refreshed = makeAdminUser({ two_factor_enabled: true })
      store.setUser(initial)
      mocked.verifyTotp.mockResolvedValueOnce({
        recovery_codes: ['aaaa-aaaa-aaaa-aaaa', 'bbbb-bbbb-bbbb-bbbb'],
      })
      mocked.me.mockResolvedValueOnce(refreshed)

      const result = await store.verifyTotp({ provisional_token: 'prov-tok', code: '123456' })

      expect(result.recovery_codes).toHaveLength(2)
      expect(store.user).toEqual(refreshed)
      expect(store.isMfaEnrolled).toBe(true)
    })

    it('verifyTotp() still resolves the recovery codes if the follow-up me() fails', async () => {
      const store = useAdminAuthStore()
      store.setUser(makeAdminUser({ two_factor_enabled: false }))
      mocked.verifyTotp.mockResolvedValueOnce({ recovery_codes: ['aaaa-aaaa-aaaa-aaaa'] })
      mocked.me.mockRejectedValueOnce(envelopeError(401, 'auth.unauthenticated'))

      const result = await store.verifyTotp({ provisional_token: 'p', code: '1' })

      expect(result.recovery_codes).toEqual(['aaaa-aaaa-aaaa-aaaa'])
      // Recovery codes must NEVER enter Pinia state. The
      // architecture test in
      // tests/unit/architecture/no-recovery-codes-in-store.spec.ts
      // enforces this at the source-inspection level; here we check
      // that no recovery-code VALUE leaked into the serialized
      // state.
      const stateRepr = JSON.stringify(store.$state)
      expect(stateRepr).not.toContain('aaaa-aaaa-aaaa-aaaa')
    })

    it('verifyTotp() optimistically flips two_factor_enabled to true even when the follow-up me() fails', async () => {
      const store = useAdminAuthStore()
      const initial = makeAdminUser({ two_factor_enabled: false })
      store.setUser(initial)
      mocked.verifyTotp.mockResolvedValueOnce({ recovery_codes: ['aaaa-aaaa-aaaa-aaaa'] })
      mocked.me.mockRejectedValueOnce(envelopeError(503, 'server.error'))

      await store.verifyTotp({ provisional_token: 'p', code: '1' })

      expect(store.user?.attributes.two_factor_enabled).toBe(true)
      expect(store.isMfaEnrolled).toBe(true)
    })

    it('verifyTotp() does NOT mutate the user if user is null (e.g. mid-enrollment after a 401)', async () => {
      const store = useAdminAuthStore()
      // user stays null — represents an unusual state where verify is
      // called outside the enrollment flow (the router guard normally
      // gates this).
      mocked.verifyTotp.mockResolvedValueOnce({ recovery_codes: ['aaaa-aaaa-aaaa-aaaa'] })
      mocked.me.mockResolvedValueOnce(makeAdminUser({ two_factor_enabled: true }))

      const result = await store.verifyTotp({ provisional_token: 'p', code: '1' })

      expect(result.recovery_codes).toEqual(['aaaa-aaaa-aaaa-aaaa'])
      // me() refreshed the user — that path runs regardless.
      expect(store.user?.attributes.two_factor_enabled).toBe(true)
    })

    it('verifyTotp() rethrows auth.mfa.invalid_code without clearing the user', async () => {
      const store = useAdminAuthStore()
      const user = makeAdminUser({ two_factor_enabled: false })
      store.setUser(user)
      mocked.verifyTotp.mockRejectedValueOnce(envelopeError(400, 'auth.mfa.invalid_code'))

      await expect(
        store.verifyTotp({ provisional_token: 'p', code: 'wrong' }),
      ).rejects.toMatchObject({ code: 'auth.mfa.invalid_code' })
      expect(store.user).toEqual(user)
    })

    it('disableTotp() POSTs and refreshes the user', async () => {
      const store = useAdminAuthStore()
      store.setUser(makeAdminUser({ two_factor_enabled: true }))
      const refreshed = makeAdminUser({ two_factor_enabled: false })
      mocked.disableTotp.mockResolvedValueOnce(undefined)
      mocked.me.mockResolvedValueOnce(refreshed)

      await store.disableTotp({ password: 'pw', mfa_code: '123456' })

      expect(mocked.disableTotp).toHaveBeenCalledWith({ password: 'pw', mfa_code: '123456' })
      expect(store.user).toEqual(refreshed)
      expect(store.isMfaEnrolled).toBe(false)
    })

    it('disableTotp() still resolves if the follow-up me() fails', async () => {
      const store = useAdminAuthStore()
      store.setUser(makeAdminUser({ two_factor_enabled: true }))
      mocked.disableTotp.mockResolvedValueOnce(undefined)
      mocked.me.mockRejectedValueOnce(envelopeError(401, 'auth.unauthenticated'))

      await expect(
        store.disableTotp({ password: 'pw', mfa_code: '123456' }),
      ).resolves.toBeUndefined()
    })

    it('disableTotp() optimistically flips two_factor_enabled to false even when the follow-up me() fails', async () => {
      const store = useAdminAuthStore()
      const initial = makeAdminUser({ two_factor_enabled: true })
      store.setUser(initial)
      mocked.disableTotp.mockResolvedValueOnce(undefined)
      mocked.me.mockRejectedValueOnce(envelopeError(503, 'server.error'))

      await store.disableTotp({ password: 'pw', mfa_code: '123456' })

      expect(store.user?.attributes.two_factor_enabled).toBe(false)
      expect(store.isMfaEnrolled).toBe(false)
    })

    it('disableTotp() does NOT mutate when user is null (defensive branch)', async () => {
      const store = useAdminAuthStore()
      // user stays null.
      mocked.disableTotp.mockResolvedValueOnce(undefined)
      mocked.me.mockResolvedValueOnce(makeAdminUser({ two_factor_enabled: false }))

      await store.disableTotp({ password: 'pw', mfa_code: '123456' })

      expect(store.user?.attributes.two_factor_enabled).toBe(false)
    })

    it('disableTotp() rethrows auth.mfa.not_enabled verbatim', async () => {
      const store = useAdminAuthStore()
      mocked.disableTotp.mockRejectedValueOnce(envelopeError(409, 'auth.mfa.not_enabled'))

      await expect(store.disableTotp({ password: 'pw', mfa_code: '123456' })).rejects.toMatchObject(
        { code: 'auth.mfa.not_enabled' },
      )
    })

    it('regenerateRecoveryCodes() returns the codes and stores nothing', async () => {
      const store = useAdminAuthStore()
      mocked.regenerateRecoveryCodes.mockResolvedValueOnce({
        recovery_codes: ['1111-1111-1111-1111', '2222-2222-2222-2222'],
      })

      const result = await store.regenerateRecoveryCodes({ mfa_code: '123456' })

      expect(result.recovery_codes).toHaveLength(2)
      const stateRepr = JSON.stringify(store.$state)
      expect(stateRepr).not.toContain('1111-1111-1111-1111')
      expect(stateRepr).not.toContain('2222-2222-2222-2222')
    })

    it('regenerateRecoveryCodes() rethrows auth.mfa.invalid_code verbatim', async () => {
      const store = useAdminAuthStore()
      mocked.regenerateRecoveryCodes.mockRejectedValueOnce(
        envelopeError(401, 'auth.mfa.invalid_code'),
      )

      await expect(store.regenerateRecoveryCodes({ mfa_code: 'wrong' })).rejects.toMatchObject({
        code: 'auth.mfa.invalid_code',
      })
    })
  })

  describe('loading-flag toggle (per-action)', () => {
    it.each([
      ['disableTotp', 'isDisablingTotp', { password: 'pw', mfa_code: '123456' }],
      ['regenerateRecoveryCodes', 'isRegeneratingRecoveryCodes', { mfa_code: '123456' }],
    ] as const)('toggles %s loading flag during the call', async (action, flag, payload) => {
      const store = useAdminAuthStore()
      let resolve: (value: unknown) => void = () => {}
      mocked[action].mockReturnValueOnce(
        new Promise((r) => {
          resolve = r
        }),
      )
      // For TOTP-disable the follow-up me() also needs to be cleared.
      mocked.me.mockResolvedValue(makeAdminUser({ two_factor_enabled: false }))

      const promise = (store[action] as (input: typeof payload) => Promise<unknown>)(payload)
      expect(store[flag]).toBe(true)
      resolve(action === 'regenerateRecoveryCodes' ? { recovery_codes: [] } : undefined)
      await promise
      expect(store[flag]).toBe(false)
    })

    it('toggles isEnrollingTotp during enrollTotp()', async () => {
      const store = useAdminAuthStore()
      let resolve: (value: unknown) => void = () => {}
      mocked.enrollTotp.mockReturnValueOnce(
        new Promise((r) => {
          resolve = r
        }),
      )

      const promise = store.enrollTotp()
      expect(store.isEnrollingTotp).toBe(true)
      resolve({
        provisional_token: 'p',
        otpauth_url: 'otpauth://totp/Catalyst-Admin',
        qr_code_svg: '<svg/>',
        manual_entry_key: 'ABCD',
        expires_in_seconds: 600,
      })
      await promise
      expect(store.isEnrollingTotp).toBe(false)
    })

    it('toggles isVerifyingTotp during verifyTotp()', async () => {
      const store = useAdminAuthStore()
      mocked.me.mockResolvedValue(makeAdminUser())
      let resolve: (value: unknown) => void = () => {}
      mocked.verifyTotp.mockReturnValueOnce(
        new Promise((r) => {
          resolve = r
        }),
      )

      const promise = store.verifyTotp({ provisional_token: 'p', code: '1' })
      expect(store.isVerifyingTotp).toBe(true)
      resolve({ recovery_codes: ['aaaa'] })
      await promise
      expect(store.isVerifyingTotp).toBe(false)
    })

    it('clears isVerifyingTotp on a failed call', async () => {
      const store = useAdminAuthStore()
      mocked.verifyTotp.mockRejectedValueOnce(envelopeError(400, 'auth.mfa.invalid_code'))

      await expect(
        store.verifyTotp({ provisional_token: 'p', code: 'wrong' }),
      ).rejects.toBeInstanceOf(ApiError)
      expect(store.isVerifyingTotp).toBe(false)
    })

    it('clears isLoggingOut on a failed non-401 logout', async () => {
      const store = useAdminAuthStore()
      store.setUser(makeAdminUser())
      mocked.logout.mockRejectedValueOnce(envelopeError(500, 'server.error'))

      await expect(store.logout()).rejects.toBeInstanceOf(ApiError)
      expect(store.isLoggingOut).toBe(false)
    })
  })
})
