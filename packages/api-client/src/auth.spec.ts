import { beforeEach, describe, expect, it, vi } from 'vitest'

import { createAuthApi, type AuthApi } from './auth'
import { ApiError } from './errors'
import type { HttpClient } from './http'
import type { UserEnvelope } from './types/user'

interface FakeHttp {
  get: ReturnType<typeof vi.fn>
  post: ReturnType<typeof vi.fn>
  patch: ReturnType<typeof vi.fn>
  delete: ReturnType<typeof vi.fn>
}

function makeFakeHttp(): FakeHttp {
  return {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  }
}

function userEnvelope(): UserEnvelope {
  return {
    data: {
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
      },
    },
  }
}

function envelopeError(status: number, code: string, meta?: Record<string, unknown>): ApiError {
  return ApiError.fromEnvelope(status, {
    errors: [{ status: String(status), code, title: code, ...(meta ? { meta } : {}) }],
  })
}

describe('createAuthApi', () => {
  let http: FakeHttp
  let api: AuthApi

  beforeEach(() => {
    http = makeFakeHttp()
    api = createAuthApi(http as unknown as HttpClient)
  })

  describe('me()', () => {
    it('GETs /me on the main variant and unwraps data', async () => {
      const envelope = userEnvelope()
      http.get.mockResolvedValueOnce(envelope)

      const user = await api.me()

      expect(http.get).toHaveBeenCalledWith('/me')
      expect(user).toBe(envelope.data)
      expect(user.attributes.user_type).toBe('creator')
    })

    it('GETs /admin/me on the admin variant', async () => {
      const adminApi = createAuthApi(http as unknown as HttpClient, { variant: 'admin' })
      const envelope = userEnvelope()
      http.get.mockResolvedValueOnce(envelope)

      await adminApi.me()

      expect(http.get).toHaveBeenCalledWith('/admin/me')
    })

    it('rethrows the 401 ApiError on a cold-load anonymous request', async () => {
      const err = envelopeError(401, 'auth.unauthenticated')
      http.get.mockRejectedValueOnce(err)

      await expect(api.me()).rejects.toBe(err)
    })

    it('rethrows the 403 auth.mfa.enrollment_required ApiError verbatim (admin variant)', async () => {
      // The api-client must already handle this shape even though the
      // main SPA never sees it — chunk 7 will use it.
      const adminApi = createAuthApi(http as unknown as HttpClient, { variant: 'admin' })
      const err = envelopeError(403, 'auth.mfa.enrollment_required')
      http.get.mockRejectedValueOnce(err)

      await expect(adminApi.me()).rejects.toMatchObject({
        status: 403,
        code: 'auth.mfa.enrollment_required',
      })
    })
  })

  describe('login()', () => {
    it('POSTs to /auth/login with the typed body and returns the user', async () => {
      const envelope = userEnvelope()
      http.post.mockResolvedValueOnce(envelope)

      const user = await api.login({ email: 'creator@example.test', password: 'pw' })

      expect(http.post).toHaveBeenCalledWith('/auth/login', {
        email: 'creator@example.test',
        password: 'pw',
      })
      expect(user).toBe(envelope.data)
    })

    it('forwards the optional mfa_code without modification', async () => {
      http.post.mockResolvedValueOnce(userEnvelope())

      await api.login({ email: 'a@b.c', password: 'pw', mfa_code: '123456' })

      expect(http.post).toHaveBeenCalledWith('/auth/login', {
        email: 'a@b.c',
        password: 'pw',
        mfa_code: '123456',
      })
    })

    it('POSTs to /admin/auth/login on the admin variant', async () => {
      const adminApi = createAuthApi(http as unknown as HttpClient, { variant: 'admin' })
      http.post.mockResolvedValueOnce(userEnvelope())

      await adminApi.login({ email: 'admin@example.test', password: 'pw' })

      expect(http.post).toHaveBeenCalledWith('/admin/auth/login', {
        email: 'admin@example.test',
        password: 'pw',
      })
    })

    it('surfaces 401 auth.invalid_credentials verbatim', async () => {
      const err = envelopeError(401, 'auth.invalid_credentials')
      http.post.mockRejectedValueOnce(err)

      await expect(api.login({ email: 'x', password: 'x' })).rejects.toMatchObject({
        status: 401,
        code: 'auth.invalid_credentials',
      })
    })

    it('surfaces 401 auth.mfa_required with mfa_required meta', async () => {
      const err = envelopeError(401, 'auth.mfa_required', { mfa_required: true })
      http.post.mockRejectedValueOnce(err)

      try {
        await api.login({ email: 'a@b.c', password: 'pw' })
        throw new Error('expected to throw')
      } catch (caught) {
        const e = caught as ApiError
        expect(e.code).toBe('auth.mfa_required')
        expect(e.details[0]?.meta).toEqual({ mfa_required: true })
      }
    })

    it('surfaces 423 auth.account_locked.temporary verbatim', async () => {
      const err = envelopeError(423, 'auth.account_locked.temporary')
      http.post.mockRejectedValueOnce(err)

      await expect(api.login({ email: 'a@b.c', password: 'pw' })).rejects.toMatchObject({
        status: 423,
        code: 'auth.account_locked.temporary',
      })
    })

    it('surfaces 419 CSRF mismatch verbatim', async () => {
      const err = envelopeError(419, 'auth.csrf_mismatch')
      http.post.mockRejectedValueOnce(err)

      await expect(api.login({ email: 'a@b.c', password: 'pw' })).rejects.toMatchObject({
        status: 419,
        code: 'auth.csrf_mismatch',
      })
    })

    it('surfaces network errors with status 0', async () => {
      const err = ApiError.fromNetworkError(new Error('ECONNREFUSED'))
      http.post.mockRejectedValueOnce(err)

      await expect(api.login({ email: 'a@b.c', password: 'pw' })).rejects.toMatchObject({
        status: 0,
        code: 'network.error',
      })
    })
  })

  describe('logout()', () => {
    it('POSTs to /auth/logout with no body', async () => {
      http.post.mockResolvedValueOnce(undefined)

      await api.logout()

      expect(http.post).toHaveBeenCalledWith('/auth/logout')
    })

    it('POSTs to /admin/auth/logout on the admin variant', async () => {
      const adminApi = createAuthApi(http as unknown as HttpClient, { variant: 'admin' })
      http.post.mockResolvedValueOnce(undefined)

      await adminApi.logout()

      expect(http.post).toHaveBeenCalledWith('/admin/auth/logout')
    })

    it('rethrows ApiError if the server hits an unexpected failure', async () => {
      const err = envelopeError(500, 'server.error')
      http.post.mockRejectedValueOnce(err)

      await expect(api.logout()).rejects.toBe(err)
    })
  })

  describe('signUp()', () => {
    it('POSTs to /auth/sign-up with the typed body and unwraps the new user', async () => {
      const envelope = userEnvelope()
      http.post.mockResolvedValueOnce(envelope)

      const user = await api.signUp({
        name: 'Creator Account',
        email: 'creator@example.test',
        password: 'pw',
        password_confirmation: 'pw',
        preferred_language: 'pt',
      })

      expect(http.post).toHaveBeenCalledWith('/auth/sign-up', {
        name: 'Creator Account',
        email: 'creator@example.test',
        password: 'pw',
        password_confirmation: 'pw',
        preferred_language: 'pt',
      })
      expect(user).toBe(envelope.data)
    })

    it('surfaces 422 validation errors with field-level pointers', async () => {
      const err = ApiError.fromEnvelope(422, {
        errors: [
          {
            status: '422',
            code: 'validation.unique',
            title: 'Email already taken',
            source: { pointer: '/data/attributes/email' },
          },
        ],
      })
      http.post.mockRejectedValueOnce(err)

      try {
        await api.signUp({
          name: 'a',
          email: 'taken@example.test',
          password: 'pw',
          password_confirmation: 'pw',
        })
        throw new Error('expected to throw')
      } catch (caught) {
        const e = caught as ApiError
        expect(e.status).toBe(422)
        expect(e.code).toBe('validation.unique')
        expect(e.details[0]?.source?.pointer).toBe('/data/attributes/email')
      }
    })
  })

  describe('verifyEmail()', () => {
    it('POSTs to /auth/verify-email with the token', async () => {
      http.post.mockResolvedValueOnce(undefined)

      await api.verifyEmail({ token: 'opaque-token' })

      expect(http.post).toHaveBeenCalledWith('/auth/verify-email', { token: 'opaque-token' })
    })

    it('surfaces auth.email.verification_invalid verbatim (single collapsed code)', async () => {
      // Chunk-4 standard 5.4: invalid / expired / already-used tokens
      // collapse into one code. The api-client must NOT re-expand.
      const err = envelopeError(400, 'auth.email.verification_invalid')
      http.post.mockRejectedValueOnce(err)

      await expect(api.verifyEmail({ token: 'bad' })).rejects.toMatchObject({
        code: 'auth.email.verification_invalid',
      })
    })
  })

  describe('resendVerification()', () => {
    it('POSTs to /auth/resend-verification', async () => {
      http.post.mockResolvedValueOnce(undefined)

      await api.resendVerification({ email: 'a@b.c' })

      expect(http.post).toHaveBeenCalledWith('/auth/resend-verification', { email: 'a@b.c' })
    })

    it('does not throw on the documented 204 — resolves to undefined', async () => {
      http.post.mockResolvedValueOnce(undefined)
      await expect(api.resendVerification({ email: 'a@b.c' })).resolves.toBeUndefined()
    })
  })

  describe('forgotPassword()', () => {
    it('POSTs to /auth/forgot-password and resolves on 204', async () => {
      http.post.mockResolvedValueOnce(undefined)

      await api.forgotPassword({ email: 'a@b.c' })

      expect(http.post).toHaveBeenCalledWith('/auth/forgot-password', { email: 'a@b.c' })
    })
  })

  describe('resetPassword()', () => {
    it('POSTs to /auth/reset-password with the typed body', async () => {
      http.post.mockResolvedValueOnce(undefined)

      await api.resetPassword({
        email: 'a@b.c',
        token: 'reset-token',
        password: 'pw',
        password_confirmation: 'pw',
      })

      expect(http.post).toHaveBeenCalledWith('/auth/reset-password', {
        email: 'a@b.c',
        token: 'reset-token',
        password: 'pw',
        password_confirmation: 'pw',
      })
    })

    it('surfaces auth.password.invalid_token verbatim', async () => {
      const err = envelopeError(400, 'auth.password.invalid_token')
      http.post.mockRejectedValueOnce(err)

      await expect(
        api.resetPassword({
          email: 'a@b.c',
          token: 'bad',
          password: 'pw',
          password_confirmation: 'pw',
        }),
      ).rejects.toMatchObject({ code: 'auth.password.invalid_token' })
    })
  })

  describe('enrollTotp()', () => {
    it('POSTs to /auth/2fa/enable and unwraps the provisional payload', async () => {
      http.post.mockResolvedValueOnce({
        data: {
          provisional_token: 'prov-tok',
          otpauth_url: 'otpauth://totp/Catalyst:creator?secret=ABCDEFGH',
          qr_code_svg: '<svg/>',
          manual_entry_key: 'ABCD EFGH IJKL MNOP',
          expires_in_seconds: 600,
        },
      })

      const result = await api.enrollTotp()

      expect(http.post).toHaveBeenCalledWith('/auth/2fa/enable')
      expect(result.provisional_token).toBe('prov-tok')
      expect(result.expires_in_seconds).toBe(600)
    })

    it('POSTs to /admin/auth/2fa/enable on the admin variant', async () => {
      const adminApi = createAuthApi(http as unknown as HttpClient, { variant: 'admin' })
      http.post.mockResolvedValueOnce({
        data: {
          provisional_token: 'prov-tok',
          otpauth_url: 'otpauth://totp/Catalyst:admin?secret=ABCDEFGH',
          qr_code_svg: '<svg/>',
          manual_entry_key: 'ABCD EFGH IJKL MNOP',
          expires_in_seconds: 600,
        },
      })

      await adminApi.enrollTotp()
      expect(http.post).toHaveBeenCalledWith('/admin/auth/2fa/enable')
    })

    it('surfaces auth.mfa.already_enabled verbatim on 409', async () => {
      const err = envelopeError(409, 'auth.mfa.already_enabled')
      http.post.mockRejectedValueOnce(err)

      await expect(api.enrollTotp()).rejects.toMatchObject({
        status: 409,
        code: 'auth.mfa.already_enabled',
      })
    })
  })

  describe('verifyTotp()', () => {
    it('POSTs to /auth/2fa/confirm and returns the recovery codes verbatim', async () => {
      http.post.mockResolvedValueOnce({
        data: { recovery_codes: ['aaaa-aaaa-aaaa-aaaa', 'bbbb-bbbb-bbbb-bbbb'] },
      })

      const result = await api.verifyTotp({ provisional_token: 'prov-tok', code: '123456' })

      expect(http.post).toHaveBeenCalledWith('/auth/2fa/confirm', {
        provisional_token: 'prov-tok',
        code: '123456',
      })
      expect(result.recovery_codes).toEqual(['aaaa-aaaa-aaaa-aaaa', 'bbbb-bbbb-bbbb-bbbb'])
    })

    it('surfaces auth.mfa.invalid_code verbatim on 400', async () => {
      const err = envelopeError(400, 'auth.mfa.invalid_code')
      http.post.mockRejectedValueOnce(err)

      await expect(
        api.verifyTotp({ provisional_token: 'prov-tok', code: 'wrong' }),
      ).rejects.toMatchObject({ code: 'auth.mfa.invalid_code' })
    })

    it('surfaces auth.mfa.provisional_expired verbatim on 410', async () => {
      const err = envelopeError(410, 'auth.mfa.provisional_expired')
      http.post.mockRejectedValueOnce(err)

      await expect(
        api.verifyTotp({ provisional_token: 'expired', code: '123456' }),
      ).rejects.toMatchObject({ code: 'auth.mfa.provisional_expired' })
    })
  })

  describe('disableTotp()', () => {
    it('POSTs to /auth/2fa/disable with the typed body', async () => {
      http.post.mockResolvedValueOnce(undefined)

      await api.disableTotp({ password: 'pw', mfa_code: '123456' })

      expect(http.post).toHaveBeenCalledWith('/auth/2fa/disable', {
        password: 'pw',
        mfa_code: '123456',
      })
    })

    it('surfaces auth.mfa.invalid_code verbatim on 401', async () => {
      const err = envelopeError(401, 'auth.mfa.invalid_code')
      http.post.mockRejectedValueOnce(err)

      await expect(api.disableTotp({ password: 'pw', mfa_code: 'wrong' })).rejects.toMatchObject({
        code: 'auth.mfa.invalid_code',
      })
    })

    it('surfaces auth.mfa.not_enabled verbatim on 409', async () => {
      const err = envelopeError(409, 'auth.mfa.not_enabled')
      http.post.mockRejectedValueOnce(err)

      await expect(api.disableTotp({ password: 'pw', mfa_code: '123456' })).rejects.toMatchObject({
        code: 'auth.mfa.not_enabled',
      })
    })
  })

  describe('regenerateRecoveryCodes()', () => {
    it('POSTs to /auth/2fa/recovery-codes and returns the new code list', async () => {
      http.post.mockResolvedValueOnce({
        data: { recovery_codes: ['1111-1111-1111-1111', '2222-2222-2222-2222'] },
      })

      const result = await api.regenerateRecoveryCodes({ mfa_code: '123456' })

      expect(http.post).toHaveBeenCalledWith('/auth/2fa/recovery-codes', { mfa_code: '123456' })
      expect(result.recovery_codes).toHaveLength(2)
    })

    it('POSTs to the admin variant when configured', async () => {
      const adminApi = createAuthApi(http as unknown as HttpClient, { variant: 'admin' })
      http.post.mockResolvedValueOnce({ data: { recovery_codes: [] } })

      await adminApi.regenerateRecoveryCodes({ mfa_code: '123456' })

      expect(http.post).toHaveBeenCalledWith('/admin/auth/2fa/recovery-codes', {
        mfa_code: '123456',
      })
    })

    it('surfaces auth.mfa.invalid_code verbatim on 401', async () => {
      const err = envelopeError(401, 'auth.mfa.invalid_code')
      http.post.mockRejectedValueOnce(err)

      await expect(api.regenerateRecoveryCodes({ mfa_code: 'wrong' })).rejects.toMatchObject({
        code: 'auth.mfa.invalid_code',
      })
    })
  })
})
