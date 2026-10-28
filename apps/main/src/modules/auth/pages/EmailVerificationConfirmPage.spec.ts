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
      initialRoute: { path: '/auth/verify-email', query: { token: 'tok' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(authApi.verifyEmail).toHaveBeenCalledWith({ token: 'tok' })
    expect(h.wrapper.find('[data-test="email-verification-confirm-success"]').exists()).toBe(true)
  })

  // AH-104d S3 (Q3a, C3): the page opens in the phone's browser, where the app
  // cannot be detected, so the app line is shown to every visitor on success only.
  describe('app hint', () => {
    it('shows the "return to the app" line on success, for everyone', async () => {
      vi.mocked(authApi.verifyEmail).mockResolvedValue(undefined)
      const h = await mountAuthPage(EmailVerificationConfirmPage, {
        initialRoute: { path: '/auth/verify-email', query: { token: 'tok' } },
      })
      teardown = h.unmount
      await flushPromises()
      expect(h.wrapper.find('[data-test="email-verification-confirm-app-hint"]').text()).toBe(
        'Using the Catalyst app? Return to it and sign in.',
      )
      // the existing way back stays
      expect(h.wrapper.find('[data-test="email-verification-confirm-back"]').exists()).toBe(true)
    })

    it('is absent when the token is missing', async () => {
      const h = await mountAuthPage(EmailVerificationConfirmPage)
      teardown = h.unmount
      await flushPromises()
      expect(h.wrapper.find('[data-test="email-verification-confirm-app-hint"]').exists()).toBe(
        false,
      )
    })

    it('shows on the "already verified" answer too (a link used twice), with the error text and the way back', async () => {
      vi.mocked(authApi.verifyEmail).mockRejectedValue(
        new ApiError({ status: 409, code: 'auth.email.already_verified', message: 'no.' }),
      )
      const h = await mountAuthPage(EmailVerificationConfirmPage, {
        initialRoute: { path: '/auth/verify-email', query: { token: 'tok' } },
      })
      teardown = h.unmount
      await flushPromises()
      expect(h.wrapper.find('[data-test="email-verification-confirm-app-hint"]').text()).toBe(
        'Using the Catalyst app? Return to it and sign in.',
      )
      expect(h.wrapper.find('[data-test="email-verification-confirm-error"]').text()).not.toBe('')
      expect(h.wrapper.find('[data-test="email-verification-confirm-success"]').exists()).toBe(
        false,
      )
      expect(h.wrapper.find('[data-test="email-verification-confirm-back"]').exists()).toBe(true)
    })

    it('is absent on an invalid link (not verified, so nothing to return to)', async () => {
      vi.mocked(authApi.verifyEmail).mockRejectedValue(
        new ApiError({ status: 422, code: 'auth.email.verification_invalid', message: 'no.' }),
      )
      const h = await mountAuthPage(EmailVerificationConfirmPage, {
        initialRoute: { path: '/auth/verify-email', query: { token: 'bad' } },
      })
      teardown = h.unmount
      await flushPromises()
      expect(h.wrapper.find('[data-test="email-verification-confirm-app-hint"]').exists()).toBe(
        false,
      )
    })

    it('is absent when verification fails (expired link)', async () => {
      vi.mocked(authApi.verifyEmail).mockRejectedValue(
        new ApiError({ status: 410, code: 'auth.email.verification_expired', message: 'no.' }),
      )
      const h = await mountAuthPage(EmailVerificationConfirmPage, {
        initialRoute: { path: '/auth/verify-email', query: { token: 'tok' } },
      })
      teardown = h.unmount
      await flushPromises()
      expect(h.wrapper.find('[data-test="email-verification-confirm-app-hint"]').exists()).toBe(
        false,
      )
    })

    it('is absent while verification is in flight', async () => {
      let resolveVerify: () => void = () => undefined
      vi.mocked(authApi.verifyEmail).mockImplementation(
        () =>
          new Promise<void>((resolve) => {
            resolveVerify = resolve
          }),
      )
      const h = await mountAuthPage(EmailVerificationConfirmPage, {
        initialRoute: { path: '/auth/verify-email', query: { token: 'tok' } },
      })
      teardown = h.unmount
      await flushPromises()
      await h.wrapper.vm.$nextTick()
      expect(h.wrapper.find('[data-test="email-verification-confirm-app-hint"]').exists()).toBe(
        false,
      )
      resolveVerify()
      await flushPromises()
    })

    it('renders localised in Portuguese', async () => {
      vi.mocked(authApi.verifyEmail).mockResolvedValue(undefined)
      const h = await mountAuthPage(EmailVerificationConfirmPage, {
        locale: 'pt',
        initialRoute: { path: '/auth/verify-email', query: { token: 'tok' } },
      })
      teardown = h.unmount
      await flushPromises()
      const text = h.wrapper.find('[data-test="email-verification-confirm-app-hint"]').text()
      expect(text).toContain('Catalyst')
      expect(text).not.toContain('Using the Catalyst app')
    })
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
      initialRoute: { path: '/auth/verify-email', query: { token: 'tok' } },
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
      initialRoute: { path: '/auth/verify-email', query: { token: 'tok' } },
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
      initialRoute: { path: '/auth/verify-email', query: { token: 'tok' } },
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
