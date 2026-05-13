/**
 * Unit tests for the {@link useAuthStore} Pinia store. The auth API is
 * mocked at the module level so the store can be exercised without a
 * live HTTP transport — every call to `authApi.*` runs against
 * `vi.fn()` doubles.
 */

import { ApiError } from '@catalyst/api-client'
import type { AuthApi, User } from '@catalyst/api-client'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from './useAuthStore'

// The store imports `authApi` from `../api/auth.api`. Mock that module
// so each test can drive the doubles directly.
vi.mock('../api/auth.api', () => ({
  authApi: {
    me: vi.fn(),
    login: vi.fn(),
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

const mocked = authApi as unknown as {
  [K in keyof AuthApi]: ReturnType<typeof vi.fn>
}

function makeUser(overrides: Partial<User['attributes']> = {}): User {
  return {
    id: '01HQVKWP0M4XKMJWR5J2PXKKKQ',
    type: 'user',
    attributes: {
      email: 'creator@example.test',
      email_verified_at: '2026-04-30T12:00:00+00:00',
      name: 'Creator Account',
      user_type: 'creator',
      preferred_language: 'en',
      preferred_currency: 'USD',
      timezone: 'Europe/Lisbon',
      theme_preference: 'system',
      mfa_required: false,
      two_factor_enabled: false,
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

describe('useAuthStore', () => {
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
      const store = useAuthStore()
      expect(store.user).toBeNull()
      expect(store.bootstrapStatus).toBe('idle')
      expect(store.mfaEnrollmentRequired).toBe(false)
      expect(store.isLoggingIn).toBe(false)
      expect(store.isAuthenticated).toBe(false)
      expect(store.userType).toBeNull()
      expect(store.isMfaEnrolled).toBe(false)
    })
  })

  describe('setUser() / clearUser()', () => {
    it('setUser(user) populates user, flips isAuthenticated, exposes user_type', () => {
      const store = useAuthStore()
      const user = makeUser({ user_type: 'agency_user' })

      store.setUser(user)

      expect(store.user).toEqual(user)
      expect(store.isAuthenticated).toBe(true)
      expect(store.userType).toBe('agency_user')
      expect(store.mfaEnrollmentRequired).toBe(false)
    })

    it('isMfaEnrolled reflects the user_attributes.two_factor_enabled flag', () => {
      const store = useAuthStore()
      store.setUser(makeUser({ two_factor_enabled: false }))
      expect(store.isMfaEnrolled).toBe(false)
      store.setUser(makeUser({ two_factor_enabled: true }))
      expect(store.isMfaEnrolled).toBe(true)
    })

    it('clearUser() resets the user and the mfaEnrollmentRequired flag', () => {
      const store = useAuthStore()
      store.setUser(makeUser())
      store.mfaEnrollmentRequired = true

      store.clearUser()

      expect(store.user).toBeNull()
      expect(store.isAuthenticated).toBe(false)
      expect(store.mfaEnrollmentRequired).toBe(false)
    })
  })

  describe('bootstrap()', () => {
    it('on 200 stores the user and ends with status=ready', async () => {
      const store = useAuthStore()
      const user = makeUser()
      mocked.me.mockResolvedValueOnce(user)

      await store.bootstrap()

      expect(store.user).toEqual(user)
      expect(store.bootstrapStatus).toBe('ready')
      expect(store.mfaEnrollmentRequired).toBe(false)
    })

    it('on 401 clears the user and ends with status=ready (NOT error)', async () => {
      const store = useAuthStore()
      mocked.me.mockRejectedValueOnce(envelopeError(401, 'auth.unauthenticated'))

      await store.bootstrap()

      expect(store.user).toBeNull()
      expect(store.bootstrapStatus).toBe('ready')
      expect(store.mfaEnrollmentRequired).toBe(false)
    })

    it('on 403 auth.mfa.enrollment_required exposes the derived flag and ends with status=ready', async () => {
      const store = useAuthStore()
      mocked.me.mockRejectedValueOnce(envelopeError(403, 'auth.mfa.enrollment_required'))

      await store.bootstrap()

      expect(store.bootstrapStatus).toBe('ready')
      expect(store.mfaEnrollmentRequired).toBe(true)
      // No user payload on this branch — it's admin territory.
      expect(store.user).toBeNull()
    })

    it('on a 500 sets status=error and rethrows', async () => {
      const store = useAuthStore()
      const err = envelopeError(500, 'server.error')
      mocked.me.mockRejectedValueOnce(err)

      await expect(store.bootstrap()).rejects.toBe(err)
      expect(store.bootstrapStatus).toBe('error')
    })

    it('on a network error sets status=error and rethrows', async () => {
      const store = useAuthStore()
      const err = ApiError.fromNetworkError(new Error('ECONNREFUSED'))
      mocked.me.mockRejectedValueOnce(err)

      await expect(store.bootstrap()).rejects.toBe(err)
      expect(store.bootstrapStatus).toBe('error')
    })

    it('dedupes two concurrent calls to a single me() invocation', async () => {
      const store = useAuthStore()
      const user = makeUser()
      // Defer resolution until both bootstraps are in-flight so the
      // second one sees the cached promise.
      let resolve: (value: User) => void = () => {}
      mocked.me.mockReturnValueOnce(
        new Promise<User>((r) => {
          resolve = r
        }),
      )

      const first = store.bootstrap()
      const second = store.bootstrap()
      // Both promises must reference the same in-flight call.
      expect(mocked.me).toHaveBeenCalledTimes(1)
      resolve(user)
      await Promise.all([first, second])

      expect(mocked.me).toHaveBeenCalledTimes(1)
      expect(store.user).toEqual(user)
    })

    it('dedupes three concurrent calls under the same in-flight promise', async () => {
      const store = useAuthStore()
      const user = makeUser()
      let resolve: (value: User) => void = () => {}
      mocked.me.mockReturnValueOnce(
        new Promise<User>((r) => {
          resolve = r
        }),
      )

      const calls = [store.bootstrap(), store.bootstrap(), store.bootstrap()]
      expect(mocked.me).toHaveBeenCalledTimes(1)
      resolve(user)
      await Promise.all(calls)

      expect(mocked.me).toHaveBeenCalledTimes(1)
    })

    it('is a no-op once bootstrapped — subsequent sequential calls skip the me() round-trip', async () => {
      const store = useAuthStore()
      mocked.me.mockResolvedValueOnce(makeUser())

      await store.bootstrap()
      await store.bootstrap() // second call — status is 'ready', should be skipped

      expect(mocked.me).toHaveBeenCalledTimes(1)
    })

    it('reissues a fresh me() call after clearUser() resets bootstrapStatus', async () => {
      const store = useAuthStore()
      mocked.me.mockResolvedValueOnce(makeUser())
      mocked.me.mockResolvedValueOnce(makeUser({ name: 'Changed' }))

      await store.bootstrap()
      store.clearUser() // resets bootstrapStatus to 'idle'
      await store.bootstrap() // should run again

      expect(mocked.me).toHaveBeenCalledTimes(2)
      expect(store.user?.attributes.name).toBe('Changed')
    })

    it('clears the in-flight cache even when the call rejects, so a retry is possible', async () => {
      const store = useAuthStore()
      mocked.me.mockRejectedValueOnce(envelopeError(500, 'server.error'))
      mocked.me.mockResolvedValueOnce(makeUser())

      await expect(store.bootstrap()).rejects.toBeInstanceOf(ApiError)
      await store.bootstrap()

      expect(mocked.me).toHaveBeenCalledTimes(2)
      expect(store.user).not.toBeNull()
    })
  })

  describe('login()', () => {
    it('forwards email + password and calls setUser on success', async () => {
      const store = useAuthStore()
      const user = makeUser()
      mocked.login.mockResolvedValueOnce(user)

      await store.login('a@b.c', 'pw')

      expect(mocked.login).toHaveBeenCalledWith({ email: 'a@b.c', password: 'pw' })
      expect(store.user).toEqual(user)
      expect(store.isAuthenticated).toBe(true)
      expect(store.isLoggingIn).toBe(false)
    })

    it('forwards an optional totpCode as mfa_code', async () => {
      const store = useAuthStore()
      mocked.login.mockResolvedValueOnce(makeUser())

      await store.login('a@b.c', 'pw', '123456')

      expect(mocked.login).toHaveBeenCalledWith({
        email: 'a@b.c',
        password: 'pw',
        mfa_code: '123456',
      })
    })

    it('toggles isLoggingIn during the call', async () => {
      const store = useAuthStore()
      let resolve: (value: User) => void = () => {}
      mocked.login.mockReturnValueOnce(
        new Promise<User>((r) => {
          resolve = r
        }),
      )

      const promise = store.login('a@b.c', 'pw')
      expect(store.isLoggingIn).toBe(true)
      resolve(makeUser())
      await promise
      expect(store.isLoggingIn).toBe(false)
    })

    it('rethrows ApiError without setting the user', async () => {
      const store = useAuthStore()
      mocked.login.mockRejectedValueOnce(envelopeError(401, 'auth.invalid_credentials'))

      await expect(store.login('a@b.c', 'wrong')).rejects.toMatchObject({
        code: 'auth.invalid_credentials',
      })
      expect(store.user).toBeNull()
      expect(store.isLoggingIn).toBe(false)
    })

    it('surfaces auth.mfa_required so the caller can re-submit with a code', async () => {
      const store = useAuthStore()
      mocked.login.mockRejectedValueOnce(envelopeError(401, 'auth.mfa_required'))

      await expect(store.login('a@b.c', 'pw')).rejects.toMatchObject({
        code: 'auth.mfa_required',
      })
      expect(store.user).toBeNull()
    })
  })

  describe('logout()', () => {
    it('POSTs logout, clears the user, and clears mfaEnrollmentRequired', async () => {
      const store = useAuthStore()
      store.setUser(makeUser())
      store.mfaEnrollmentRequired = true
      mocked.logout.mockResolvedValueOnce(undefined)

      await store.logout()

      expect(mocked.logout).toHaveBeenCalledTimes(1)
      expect(store.user).toBeNull()
      expect(store.mfaEnrollmentRequired).toBe(false)
      expect(store.isLoggingOut).toBe(false)
    })

    it('treats a 401 from /auth/logout as success (session already gone)', async () => {
      const store = useAuthStore()
      store.setUser(makeUser())
      mocked.logout.mockRejectedValueOnce(envelopeError(401, 'auth.unauthenticated'))

      await store.logout()

      expect(store.user).toBeNull()
    })

    it('rethrows non-401 ApiError without clearing the user', async () => {
      const store = useAuthStore()
      const user = makeUser()
      store.setUser(user)
      mocked.logout.mockRejectedValueOnce(envelopeError(500, 'server.error'))

      await expect(store.logout()).rejects.toMatchObject({ status: 500 })
      // User stays — the server rejected the logout, the SPA must
      // not silently invalidate the in-memory session.
      expect(store.user).toEqual(user)
      expect(store.isLoggingOut).toBe(false)
    })

    it('rethrows an unrelated thrown value as-is (defensive non-ApiError path)', async () => {
      const store = useAuthStore()
      store.setUser(makeUser())
      const odd = new TypeError('not an api error')
      mocked.logout.mockRejectedValueOnce(odd)

      await expect(store.logout()).rejects.toBe(odd)
      // Non-ApiError should not silently clear the user either.
      expect(store.user).not.toBeNull()
    })
  })

  describe('signUp() / verifyEmail() / resendVerification()', () => {
    it('signUp() returns the new user and does NOT auto-set it (sign-up is non-authenticating)', async () => {
      const store = useAuthStore()
      const newUser = makeUser({ email: 'new@example.test' })
      mocked.signUp.mockResolvedValueOnce(newUser)

      const result = await store.signUp({
        name: 'New Account',
        email: 'new@example.test',
        password: 'pw',
        password_confirmation: 'pw',
      })

      expect(result).toBe(newUser)
      expect(store.user).toBeNull()
    })

    it('signUp() rethrows 422 validation errors verbatim', async () => {
      const store = useAuthStore()
      mocked.signUp.mockRejectedValueOnce(envelopeError(422, 'validation.unique'))

      await expect(
        store.signUp({
          name: 'a',
          email: 'taken@example.test',
          password: 'pw',
          password_confirmation: 'pw',
        }),
      ).rejects.toMatchObject({ code: 'validation.unique' })
    })

    it('verifyEmail() forwards the token', async () => {
      const store = useAuthStore()
      mocked.verifyEmail.mockResolvedValueOnce(undefined)

      await store.verifyEmail({ token: 'opaque-token' })

      expect(mocked.verifyEmail).toHaveBeenCalledWith({ token: 'opaque-token' })
    })

    it('verifyEmail() rethrows auth.email.verification_invalid verbatim', async () => {
      const store = useAuthStore()
      mocked.verifyEmail.mockRejectedValueOnce(
        envelopeError(400, 'auth.email.verification_invalid'),
      )

      await expect(store.verifyEmail({ token: 'bad' })).rejects.toMatchObject({
        code: 'auth.email.verification_invalid',
      })
    })

    it('resendVerification() forwards the email', async () => {
      const store = useAuthStore()
      mocked.resendVerification.mockResolvedValueOnce(undefined)

      await store.resendVerification({ email: 'a@b.c' })

      expect(mocked.resendVerification).toHaveBeenCalledWith({ email: 'a@b.c' })
    })

    it('resendVerification() resolves on the documented 204 (treated as success)', async () => {
      const store = useAuthStore()
      mocked.resendVerification.mockResolvedValueOnce(undefined)

      await expect(store.resendVerification({ email: 'a@b.c' })).resolves.toBeUndefined()
    })
  })

  describe('forgotPassword() / resetPassword()', () => {
    it('forgotPassword() forwards the email', async () => {
      const store = useAuthStore()
      mocked.forgotPassword.mockResolvedValueOnce(undefined)

      await store.forgotPassword({ email: 'a@b.c' })

      expect(mocked.forgotPassword).toHaveBeenCalledWith({ email: 'a@b.c' })
      expect(store.isRequestingPasswordReset).toBe(false)
    })

    it('resetPassword() forwards the typed body', async () => {
      const store = useAuthStore()
      mocked.resetPassword.mockResolvedValueOnce(undefined)

      await store.resetPassword({
        email: 'a@b.c',
        token: 'reset-token',
        password: 'pw',
        password_confirmation: 'pw',
      })

      expect(mocked.resetPassword).toHaveBeenCalledWith({
        email: 'a@b.c',
        token: 'reset-token',
        password: 'pw',
        password_confirmation: 'pw',
      })
    })

    it('resetPassword() rethrows auth.password.invalid_token verbatim', async () => {
      const store = useAuthStore()
      mocked.resetPassword.mockRejectedValueOnce(envelopeError(400, 'auth.password.invalid_token'))

      await expect(
        store.resetPassword({
          email: 'a@b.c',
          token: 'bad',
          password: 'pw',
          password_confirmation: 'pw',
        }),
      ).rejects.toMatchObject({ code: 'auth.password.invalid_token' })
    })
  })

  describe('TOTP enrollment', () => {
    it('enrollTotp() returns the provisional payload directly to the caller', async () => {
      const store = useAuthStore()
      const payload = {
        provisional_token: 'prov-tok',
        otpauth_url: 'otpauth://totp/Catalyst:creator?secret=ABCDEFGH',
        qr_code_svg: '<svg/>',
        manual_entry_key: 'ABCD EFGH IJKL MNOP',
        expires_in_seconds: 600,
      }
      mocked.enrollTotp.mockResolvedValueOnce(payload)

      const result = await store.enrollTotp()

      expect(result).toBe(payload)
    })

    it('enrollTotp() rethrows auth.mfa.already_enabled verbatim', async () => {
      const store = useAuthStore()
      mocked.enrollTotp.mockRejectedValueOnce(envelopeError(409, 'auth.mfa.already_enabled'))

      await expect(store.enrollTotp()).rejects.toMatchObject({
        code: 'auth.mfa.already_enabled',
      })
    })

    it('verifyTotp() returns recovery codes and refreshes the user via me()', async () => {
      const store = useAuthStore()
      const initial = makeUser({ two_factor_enabled: false })
      const refreshed = makeUser({ two_factor_enabled: true })
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
      const store = useAuthStore()
      store.setUser(makeUser({ two_factor_enabled: false }))
      mocked.verifyTotp.mockResolvedValueOnce({ recovery_codes: ['aaaa-aaaa-aaaa-aaaa'] })
      mocked.me.mockRejectedValueOnce(envelopeError(401, 'auth.unauthenticated'))

      const result = await store.verifyTotp({ provisional_token: 'p', code: '1' })

      expect(result.recovery_codes).toEqual(['aaaa-aaaa-aaaa-aaaa'])
      // Recovery codes are NOT routed into store state — the
      // source-inspection regression test enforces this at the
      // architecture level; the assertion below documents the
      // contract at runtime by checking that no recovery-code
      // VALUE leaked into the serialized state.
      const stateRepr = JSON.stringify(store.$state)
      expect(stateRepr).not.toContain('aaaa-aaaa-aaaa-aaaa')
    })

    it('verifyTotp() optimistically flips two_factor_enabled to true even when the follow-up me() fails', async () => {
      // Pins the chunk-6.2-6.4 change-request #2 contract: the
      // primary-call success is canonical for the
      // `two_factor_enabled` field. A follow-up me() failure is
      // invisible — the optimistic update has already left the
      // stored user in the correct shape for the chunk-6.5 router
      // guard to read.
      const store = useAuthStore()
      const initial = makeUser({ two_factor_enabled: false })
      store.setUser(initial)
      mocked.verifyTotp.mockResolvedValueOnce({ recovery_codes: ['aaaa-aaaa-aaaa-aaaa'] })
      mocked.me.mockRejectedValueOnce(envelopeError(503, 'server.error'))

      await store.verifyTotp({ provisional_token: 'p', code: '1' })

      expect(store.user?.attributes.two_factor_enabled).toBe(true)
      expect(store.isMfaEnrolled).toBe(true)
    })

    it('verifyTotp() rethrows auth.mfa.invalid_code without clearing the user', async () => {
      const store = useAuthStore()
      const user = makeUser()
      store.setUser(user)
      mocked.verifyTotp.mockRejectedValueOnce(envelopeError(400, 'auth.mfa.invalid_code'))

      await expect(
        store.verifyTotp({ provisional_token: 'p', code: 'wrong' }),
      ).rejects.toMatchObject({ code: 'auth.mfa.invalid_code' })
      expect(store.user).toEqual(user)
    })

    it('disableTotp() POSTs and refreshes the user', async () => {
      const store = useAuthStore()
      store.setUser(makeUser({ two_factor_enabled: true }))
      const refreshed = makeUser({ two_factor_enabled: false })
      mocked.disableTotp.mockResolvedValueOnce(undefined)
      mocked.me.mockResolvedValueOnce(refreshed)

      await store.disableTotp({ password: 'pw', mfa_code: '123456' })

      expect(mocked.disableTotp).toHaveBeenCalledWith({ password: 'pw', mfa_code: '123456' })
      expect(store.user).toEqual(refreshed)
      expect(store.isMfaEnrolled).toBe(false)
    })

    it('disableTotp() still resolves if the follow-up me() fails', async () => {
      const store = useAuthStore()
      store.setUser(makeUser({ two_factor_enabled: true }))
      mocked.disableTotp.mockResolvedValueOnce(undefined)
      mocked.me.mockRejectedValueOnce(envelopeError(401, 'auth.unauthenticated'))

      await expect(
        store.disableTotp({ password: 'pw', mfa_code: '123456' }),
      ).resolves.toBeUndefined()
    })

    it('disableTotp() optimistically flips two_factor_enabled to false even when the follow-up me() fails', async () => {
      // Mirror of the verifyTotp optimistic-update test (chunk-6.2-6.4
      // change-request #2). Disabling 2FA is the inverse transition;
      // the same canonical-on-primary-success contract applies.
      const store = useAuthStore()
      const initial = makeUser({ two_factor_enabled: true })
      store.setUser(initial)
      mocked.disableTotp.mockResolvedValueOnce(undefined)
      mocked.me.mockRejectedValueOnce(envelopeError(503, 'server.error'))

      await store.disableTotp({ password: 'pw', mfa_code: '123456' })

      expect(store.user?.attributes.two_factor_enabled).toBe(false)
      expect(store.isMfaEnrolled).toBe(false)
    })

    it('disableTotp() rethrows auth.mfa.not_enabled verbatim', async () => {
      const store = useAuthStore()
      mocked.disableTotp.mockRejectedValueOnce(envelopeError(409, 'auth.mfa.not_enabled'))

      await expect(store.disableTotp({ password: 'pw', mfa_code: '123456' })).rejects.toMatchObject(
        { code: 'auth.mfa.not_enabled' },
      )
    })

    it('regenerateRecoveryCodes() returns the codes and stores nothing', async () => {
      const store = useAuthStore()
      mocked.regenerateRecoveryCodes.mockResolvedValueOnce({
        recovery_codes: ['1111-1111-1111-1111', '2222-2222-2222-2222'],
      })

      const result = await store.regenerateRecoveryCodes({ mfa_code: '123456' })

      expect(result.recovery_codes).toHaveLength(2)
      // Architecture-level enforcement lives in the
      // no-recovery-codes-in-store source-inspection test; here we
      // check that no code VALUE leaks into the serialized store
      // state at runtime.
      const stateRepr = JSON.stringify(store.$state)
      expect(stateRepr).not.toContain('1111-1111-1111-1111')
      expect(stateRepr).not.toContain('2222-2222-2222-2222')
    })

    it('regenerateRecoveryCodes() rethrows auth.mfa.invalid_code verbatim', async () => {
      const store = useAuthStore()
      mocked.regenerateRecoveryCodes.mockRejectedValueOnce(
        envelopeError(401, 'auth.mfa.invalid_code'),
      )

      await expect(store.regenerateRecoveryCodes({ mfa_code: 'wrong' })).rejects.toMatchObject({
        code: 'auth.mfa.invalid_code',
      })
    })
  })

  describe('loading flag toggle (per-action)', () => {
    it.each([
      [
        'signUp',
        'isSigningUp',
        { name: 'a', email: 'a@b.c', password: 'pw', password_confirmation: 'pw' },
      ],
      ['verifyEmail', 'isVerifyingEmail', { token: 'tok' }],
      ['resendVerification', 'isResendingVerification', { email: 'a@b.c' }],
      ['forgotPassword', 'isRequestingPasswordReset', { email: 'a@b.c' }],
      [
        'resetPassword',
        'isResettingPassword',
        {
          email: 'a@b.c',
          token: 'tok',
          password: 'pw',
          password_confirmation: 'pw',
        },
      ],
      ['disableTotp', 'isDisablingTotp', { password: 'pw', mfa_code: '123456' }],
      ['regenerateRecoveryCodes', 'isRegeneratingRecoveryCodes', { mfa_code: '123456' }],
    ] as const)('toggles %s loading flag during the call', async (action, flag, payload) => {
      const store = useAuthStore()
      let resolve: (value: unknown) => void = () => {}
      mocked[action].mockReturnValueOnce(
        new Promise((r) => {
          resolve = r
        }),
      )
      // For TOTP-disable / regenerate paths the follow-up me() also
      // needs to be cleared.
      mocked.me.mockResolvedValue(makeUser())

      const promise = (store[action] as (input: typeof payload) => Promise<unknown>)(payload)
      expect(store[flag]).toBe(true)
      resolve(action === 'regenerateRecoveryCodes' ? { recovery_codes: [] } : undefined)
      await promise
      expect(store[flag]).toBe(false)
    })

    it('toggles isEnrollingTotp during enrollTotp()', async () => {
      const store = useAuthStore()
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
        otpauth_url: 'otpauth://totp/Catalyst',
        qr_code_svg: '<svg/>',
        manual_entry_key: 'ABCD',
        expires_in_seconds: 600,
      })
      await promise
      expect(store.isEnrollingTotp).toBe(false)
    })

    it('toggles isVerifyingTotp during verifyTotp()', async () => {
      const store = useAuthStore()
      mocked.me.mockResolvedValue(makeUser())
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
      const store = useAuthStore()
      mocked.verifyTotp.mockRejectedValueOnce(envelopeError(400, 'auth.mfa.invalid_code'))

      await expect(
        store.verifyTotp({ provisional_token: 'p', code: 'wrong' }),
      ).rejects.toBeInstanceOf(ApiError)
      expect(store.isVerifyingTotp).toBe(false)
    })

    it('clears isLoggingOut on a failed non-401 logout', async () => {
      const store = useAuthStore()
      store.setUser(makeUser())
      mocked.logout.mockRejectedValueOnce(envelopeError(500, 'server.error'))

      await expect(store.logout()).rejects.toBeInstanceOf(ApiError)
      expect(store.isLoggingOut).toBe(false)
    })
  })
})
