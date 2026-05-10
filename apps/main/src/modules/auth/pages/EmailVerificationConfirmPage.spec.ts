import { ApiError } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import EmailVerificationConfirmPage from './EmailVerificationConfirmPage.vue'

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

describe('EmailVerificationConfirmPage', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('on mount, calls verifyEmail with the token from the route query', async () => {
    vi.mocked(authApi.verifyEmail).mockResolvedValue(undefined)
    const h = await mountAuthPage(EmailVerificationConfirmPage, {
      initialRoute: { path: '/verify-email/confirm', query: { token: 'tok' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(authApi.verifyEmail).toHaveBeenCalledWith({ token: 'tok' })
    expect(h.wrapper.find('[data-test="email-verification-confirm-success"]').exists()).toBe(true)
  })

  it('renders the missing-token error when no token is in the query', async () => {
    const h = await mountAuthPage(EmailVerificationConfirmPage)
    teardown = h.unmount
    await flushPromises()
    expect(authApi.verifyEmail).not.toHaveBeenCalled()
    expect(h.wrapper.find('[data-test="email-verification-confirm-error"]').text()).toContain(
      'missing required information',
    )
  })

  it('on auth.email.verification_expired, renders the i18n string', async () => {
    vi.mocked(authApi.verifyEmail).mockRejectedValue(
      new ApiError({
        status: 410,
        code: 'auth.email.verification_expired',
        message: 'no.',
      }),
    )
    const h = await mountAuthPage(EmailVerificationConfirmPage, {
      initialRoute: { path: '/verify-email/confirm', query: { token: 'tok' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="email-verification-confirm-error"]').text()).toContain(
      'verification link has expired',
    )
  })

  it('on auth.email.verification_invalid, renders the i18n string', async () => {
    vi.mocked(authApi.verifyEmail).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'auth.email.verification_invalid',
        message: 'no.',
      }),
    )
    const h = await mountAuthPage(EmailVerificationConfirmPage, {
      initialRoute: { path: '/verify-email/confirm', query: { token: 'tok' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="email-verification-confirm-error"]').text()).toContain(
      'verification link is invalid',
    )
  })

  it('shows the loading state while verifyEmail is in flight', async () => {
    let resolveVerify: () => void = () => undefined
    vi.mocked(authApi.verifyEmail).mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          resolveVerify = resolve
        }),
    )
    const h = await mountAuthPage(EmailVerificationConfirmPage, {
      initialRoute: { path: '/verify-email/confirm', query: { token: 'tok' } },
    })
    teardown = h.unmount
    // Pump pending microtasks then wait for Vue to flush its reactive
    // queue — onMounted fires verifyEmail() which sets isVerifyingEmail
    // to true; Vue schedules a re-render that the next tick runs.
    await flushPromises()
    await h.wrapper.vm.$nextTick()
    expect(h.wrapper.find('[data-test="email-verification-confirm-loading"]').exists()).toBe(true)
    resolveVerify()
    await flushPromises()
  })

  it('error region uses aria-live="polite" (a11y)', async () => {
    const h = await mountAuthPage(EmailVerificationConfirmPage)
    teardown = h.unmount
    await flushPromises()
    expect(
      h.wrapper.find('[data-test="email-verification-confirm-error"]').attributes('aria-live'),
    ).toBe('polite')
  })
})
