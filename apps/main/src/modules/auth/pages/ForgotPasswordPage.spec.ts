import { ApiError } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import ForgotPasswordPage from './ForgotPasswordPage.vue'

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

describe('ForgotPasswordPage', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the heading', async () => {
    const h = await mountAuthPage(ForgotPasswordPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="forgot-password-heading"]').text()).toBe(
      'Reset your password',
    )
  })

  it('happy path: calls forgotPassword and shows the generic confirmation banner', async () => {
    vi.mocked(authApi.forgotPassword).mockResolvedValue(undefined)
    const h = await mountAuthPage(ForgotPasswordPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="forgot-password-email"] input').setValue('a@b.c')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.forgotPassword).toHaveBeenCalledWith({ email: 'a@b.c' })
    expect(h.wrapper.find('[data-test="forgot-password-sent-banner"]').exists()).toBe(true)
  })

  it('on a server error, renders the i18n message inline', async () => {
    vi.mocked(authApi.forgotPassword).mockRejectedValue(
      new ApiError({
        status: 429,
        code: 'auth.login.rate_limited',
        message: 'no.',
        details: [{ code: 'auth.login.rate_limited', meta: { seconds: 60 } }],
      }),
    )
    const h = await mountAuthPage(ForgotPasswordPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="forgot-password-email"] input').setValue('a@b.c')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="forgot-password-error"]').text()).toContain('60 seconds')
  })

  it('error region uses aria-live="polite"', async () => {
    const h = await mountAuthPage(ForgotPasswordPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="forgot-password-error"]').attributes('aria-live')).toBe(
      'polite',
    )
  })

  it('button shows the sending label while in flight', async () => {
    let resolveForgot: () => void = () => undefined
    vi.mocked(authApi.forgotPassword).mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          resolveForgot = resolve
        }),
    )
    const h = await mountAuthPage(ForgotPasswordPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="forgot-password-email"] input').setValue('a@b.c')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="forgot-password-submit"]').text()).toContain('Sending')
    resolveForgot()
    await flushPromises()
  })
})
