import { ApiError } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import EmailVerificationPendingPage from './EmailVerificationPendingPage.vue'

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
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

// Unverified-creator fixture for the store-fallback tests (the sign-in
// bounce path lands here with an authenticated-but-unverified user).
const UNVERIFIED_USER = {
  type: 'user' as const,
  id: '01HQ',
  attributes: {
    email: 'store@user.example',
    email_verified_at: null,
    name: 'A',
    user_type: 'creator' as const,
    preferred_language: 'en' as const,
    preferred_currency: 'USD',
    timezone: 'Europe/Lisbon',
    theme_preference: 'system' as const,
    mfa_required: false,
    two_factor_enabled: false,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
}

describe('EmailVerificationPendingPage', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the heading', async () => {
    const h = await mountAuthPage(EmailVerificationPendingPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="email-verification-pending-heading"]').text()).toBe(
      'Check your email',
    )
  })

  it('interpolates the email from the route query into the description', async () => {
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      initialRoute: { path: '/verify-email/pending', query: { email: 'a@b.c' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="email-verification-pending-description"]').text()).toContain(
      'a@b.c',
    )
  })

  it('happy path: resend() calls resendVerification with the email and shows the success banner', async () => {
    vi.mocked(authApi.resendVerification).mockResolvedValue(undefined)
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      initialRoute: { path: '/verify-email/pending', query: { email: 'a@b.c' } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="email-verification-pending-resend"]').trigger('click')
    await flushPromises()
    expect(authApi.resendVerification).toHaveBeenCalledWith({ email: 'a@b.c' })
    expect(h.wrapper.find('[data-test="email-verification-pending-resent-banner"]').exists()).toBe(
      true,
    )
  })

  it('falls back to the signed-in user email when the route query is absent (sign-in bounce path)', async () => {
    vi.mocked(authApi.resendVerification).mockResolvedValue(undefined)
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      initialRoute: { path: '/verify-email/pending' },
      beforeMount: () => {
        useAuthStore().user = UNVERIFIED_USER
      },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="email-verification-pending-description"]').text()).toContain(
      'store@user.example',
    )
    await h.wrapper.find('[data-test="email-verification-pending-resend"]').trigger('click')
    await flushPromises()
    expect(authApi.resendVerification).toHaveBeenCalledWith({ email: 'store@user.example' })
    expect(h.wrapper.find('[data-test="email-verification-pending-resent-banner"]').exists()).toBe(
      true,
    )
  })

  it('renders the missing-token error when no email is in the query', async () => {
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      initialRoute: { path: '/verify-email/pending' },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="email-verification-pending-resend"]').trigger('click')
    await flushPromises()
    expect(authApi.resendVerification).not.toHaveBeenCalled()
    expect(h.wrapper.find('[data-test="email-verification-pending-error"]').text()).toContain(
      'missing required information',
    )
  })

  it('on a rate-limit error, renders the i18n string with the seconds value', async () => {
    vi.mocked(authApi.resendVerification).mockRejectedValue(
      new ApiError({
        status: 429,
        code: 'auth.login.rate_limited',
        message: 'no.',
        details: [{ code: 'auth.login.rate_limited', meta: { seconds: 30 } }],
      }),
    )
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      initialRoute: { path: '/verify-email/pending', query: { email: 'a@b.c' } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="email-verification-pending-resend"]').trigger('click')
    await flushPromises()
    expect(h.wrapper.find('[data-test="email-verification-pending-error"]').text()).toContain('30')
  })

  it('error region uses aria-live="polite" (a11y)', async () => {
    const h = await mountAuthPage(EmailVerificationPendingPage)
    teardown = h.unmount
    await flushPromises()
    expect(
      h.wrapper.find('[data-test="email-verification-pending-error"]').attributes('aria-live'),
    ).toBe('polite')
  })

  it('button shows the sending label while in flight', async () => {
    let resolveResend: () => void = () => undefined
    vi.mocked(authApi.resendVerification).mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          resolveResend = resolve
        }),
    )
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      initialRoute: { path: '/verify-email/pending', query: { email: 'a@b.c' } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="email-verification-pending-resend"]').trigger('click')
    await flushPromises()
    expect(h.wrapper.find('[data-test="email-verification-pending-resend"]').text()).toContain(
      'Sending',
    )
    resolveResend()
    await flushPromises()
  })
})
