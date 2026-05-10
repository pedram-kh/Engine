import { ApiError } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import VerifyTotpPage from './VerifyTotpPage.vue'

vi.mock('@/modules/auth/api/auth.api', () => ({
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
  },
}))

import { authApi } from '@/modules/auth/api/auth.api'

const USER = {
  type: 'user' as const,
  id: '01HQ',
  attributes: {
    email: 'a@b.c',
    email_verified_at: '2026-01-01T00:00:00Z',
    name: 'A',
    user_type: 'creator' as const,
    preferred_language: 'en' as const,
    preferred_currency: 'USD',
    timezone: 'Europe/Lisbon',
    theme_preference: 'system' as const,
    mfa_required: false,
    two_factor_enabled: true,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
}

describe('VerifyTotpPage', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the heading', async () => {
    const h = await mountAuthPage(VerifyTotpPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="verify-totp-heading"]').text()).toBe(
      'Enter your two-factor code',
    )
  })

  it('happy path: re-submits login with email + password + mfa_code, navigates to /', async () => {
    vi.mocked(authApi.login).mockResolvedValue(USER)
    const h = await mountAuthPage(VerifyTotpPage, {
      initialRoute: {
        path: '/auth/2fa/verify',
        query: { email: 'a@b.c', password: 'Pa$$w0rd!12' },
      },
    })
    teardown = h.unmount
    await flushPromises()
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="verify-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.login).toHaveBeenCalledWith({
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      mfa_code: '123456',
    })
    expect(pushSpy).toHaveBeenCalledWith('/')
  })

  it('happy path: navigates to ?redirect when present', async () => {
    vi.mocked(authApi.login).mockResolvedValue(USER)
    const h = await mountAuthPage(VerifyTotpPage, {
      initialRoute: {
        path: '/auth/2fa/verify',
        query: { email: 'a@b.c', password: 'Pa$$w0rd!12', redirect: '/dashboard' },
      },
    })
    teardown = h.unmount
    await flushPromises()
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="verify-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith('/dashboard')
  })

  it('renders missing-token error when email or password is absent from the route', async () => {
    const h = await mountAuthPage(VerifyTotpPage)
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="verify-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.login).not.toHaveBeenCalled()
    expect(h.wrapper.find('[data-test="verify-totp-error"]').text()).toContain(
      'missing required information',
    )
  })

  it('renders missing-token error when only email is present (covers password-falsy branch)', async () => {
    const h = await mountAuthPage(VerifyTotpPage, {
      initialRoute: {
        path: '/auth/2fa/verify',
        query: { email: 'a@b.c' },
      },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="verify-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.login).not.toHaveBeenCalled()
    expect(h.wrapper.find('[data-test="verify-totp-error"]').text()).toContain(
      'missing required information',
    )
  })

  it('on auth.mfa.invalid_code, renders the i18n string', async () => {
    vi.mocked(authApi.login).mockRejectedValue(
      new ApiError({ status: 422, code: 'auth.mfa.invalid_code', message: 'no.' }),
    )
    const h = await mountAuthPage(VerifyTotpPage, {
      initialRoute: {
        path: '/auth/2fa/verify',
        query: { email: 'a@b.c', password: 'Pa$$w0rd!12' },
      },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="verify-totp-code"] input').setValue('000000')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="verify-totp-error"]').text()).toContain(
      'two-factor code is invalid',
    )
  })

  it('on a non-ApiError, falls back to auth.ui.errors.unknown', async () => {
    vi.mocked(authApi.login).mockRejectedValue(new Error('boom'))
    const h = await mountAuthPage(VerifyTotpPage, {
      initialRoute: {
        path: '/auth/2fa/verify',
        query: { email: 'a@b.c', password: 'Pa$$w0rd!12' },
      },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="verify-totp-code"] input').setValue('000000')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="verify-totp-error"]').text()).toBe(
      'Something went wrong. Please try again.',
    )
  })

  it('error region uses aria-live="polite"', async () => {
    const h = await mountAuthPage(VerifyTotpPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="verify-totp-error"]').attributes('aria-live')).toBe('polite')
  })
})
