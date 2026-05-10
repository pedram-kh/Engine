import { ApiError } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import ResetPasswordPage from './ResetPasswordPage.vue'

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

describe('ResetPasswordPage', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the heading', async () => {
    const h = await mountAuthPage(ResetPasswordPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="reset-password-heading"]').text()).toBe(
      'Choose a new password',
    )
  })

  it('happy path: calls resetPassword with token + email + passwords and shows success', async () => {
    vi.mocked(authApi.resetPassword).mockResolvedValue(undefined)
    const h = await mountAuthPage(ResetPasswordPage, {
      initialRoute: { path: '/reset-password', query: { token: 't', email: 'a@b.c' } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="reset-password-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper
      .find('[data-test="reset-password-password-confirmation"] input')
      .setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.resetPassword).toHaveBeenCalledWith({
      email: 'a@b.c',
      token: 't',
      password: 'Pa$$w0rd!12',
      password_confirmation: 'Pa$$w0rd!12',
    })
    expect(h.wrapper.find('[data-test="reset-password-success"]').exists()).toBe(true)
    expect(h.wrapper.find('form').exists()).toBe(false)
  })

  it('renders missing-token error when token or email is absent', async () => {
    const h = await mountAuthPage(ResetPasswordPage, {
      initialRoute: { path: '/reset-password' },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="reset-password-password"] input').setValue('whatever')
    await h.wrapper
      .find('[data-test="reset-password-password-confirmation"] input')
      .setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.resetPassword).not.toHaveBeenCalled()
    expect(h.wrapper.find('[data-test="reset-password-error"]').text()).toContain(
      'missing required information',
    )
  })

  it('renders missing-token error when only email is present', async () => {
    const h = await mountAuthPage(ResetPasswordPage, {
      initialRoute: { path: '/reset-password', query: { email: 'a@b.c' } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="reset-password-password"] input').setValue('whatever')
    await h.wrapper
      .find('[data-test="reset-password-password-confirmation"] input')
      .setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="reset-password-error"]').text()).toContain(
      'missing required information',
    )
  })

  it('renders missing-token error when only token is present (covers email-falsy branch)', async () => {
    const h = await mountAuthPage(ResetPasswordPage, {
      initialRoute: { path: '/reset-password', query: { token: 't' } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="reset-password-password"] input').setValue('whatever')
    await h.wrapper
      .find('[data-test="reset-password-password-confirmation"] input')
      .setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="reset-password-error"]').text()).toContain(
      'missing required information',
    )
  })

  it('on auth.password.invalid_token, renders the i18n string', async () => {
    vi.mocked(authApi.resetPassword).mockRejectedValue(
      new ApiError({ status: 410, code: 'auth.password.invalid_token', message: 'no.' }),
    )
    const h = await mountAuthPage(ResetPasswordPage, {
      initialRoute: { path: '/reset-password', query: { token: 't', email: 'a@b.c' } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="reset-password-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper
      .find('[data-test="reset-password-password-confirmation"] input')
      .setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="reset-password-error"]').text()).toContain(
      'invalid or has expired',
    )
  })

  it('error region uses aria-live="polite"', async () => {
    const h = await mountAuthPage(ResetPasswordPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="reset-password-error"]').attributes('aria-live')).toBe(
      'polite',
    )
  })

  it('button shows the submitting label while in flight', async () => {
    let resolveReset: () => void = () => undefined
    vi.mocked(authApi.resetPassword).mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          resolveReset = resolve
        }),
    )
    const h = await mountAuthPage(ResetPasswordPage, {
      initialRoute: { path: '/reset-password', query: { token: 't', email: 'a@b.c' } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.find('[data-test="reset-password-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper
      .find('[data-test="reset-password-password-confirmation"] input')
      .setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="reset-password-submit"]').text()).toContain('Submitting')
    resolveReset()
    await flushPromises()
  })
})
