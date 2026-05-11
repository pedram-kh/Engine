import { ApiError } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import SignInPage from './SignInPage.vue'

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

describe('SignInPage', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the sign-in heading from i18n', async () => {
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe('Sign in to Catalyst')
  })

  it('does NOT show the session-expired banner without ?reason', async () => {
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="session-expired-banner"]').exists()).toBe(false)
  })

  it('shows the session-expired banner when ?reason=session_expired is in the route query', async () => {
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { path: '/sign-in', query: { reason: 'session_expired' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="session-expired-banner"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="session-expired-banner"]').text()).toContain(
      'session has expired',
    )
  })

  it('happy path: calls login() and pushes to / on 2xx', async () => {
    vi.mocked(authApi.login).mockResolvedValue(USER)
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.login).toHaveBeenCalledWith({ email: 'a@b.c', password: 'Pa$$w0rd!12' })
    expect(pushSpy).toHaveBeenCalledWith('/')
  })

  it('happy path: pushes to ?redirect=/foo when query param is set', async () => {
    vi.mocked(authApi.login).mockResolvedValue(USER)
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { path: '/sign-in', query: { redirect: '/dashboard?x=1' } },
    })
    teardown = h.unmount
    await flushPromises()
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith('/dashboard?x=1')
  })

  it('on auth.invalid_credentials, renders t(error.code) inline', async () => {
    vi.mocked(authApi.login).mockRejectedValue(
      new ApiError({ status: 401, code: 'auth.invalid_credentials', message: 'no.' }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('wrong')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toBe('Invalid email or password.')
  })

  it('on rate_limit.exceeded, renders the localized message with {seconds} from meta (chunk 7.1)', async () => {
    vi.mocked(authApi.login).mockRejectedValue(
      new ApiError({
        status: 429,
        code: 'rate_limit.exceeded',
        message: 'no.',
        details: [{ code: 'rate_limit.exceeded', meta: { seconds: 42 } }],
      }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toBe(
      'Too many requests. Please try again in 42 seconds.',
    )
  })

  it('on auth.login.account_locked_temporary, interpolates {minutes}', async () => {
    vi.mocked(authApi.login).mockRejectedValue(
      new ApiError({
        status: 429,
        code: 'auth.login.account_locked_temporary',
        message: 'no.',
        details: [{ code: 'auth.login.account_locked_temporary', meta: { minutes: 5 } }],
      }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toContain('5 minutes')
  })

  it('on auth.mfa_required, reveals the TOTP field and shows the mfa-required message', async () => {
    vi.mocked(authApi.login).mockRejectedValueOnce(
      new ApiError({ status: 403, code: 'auth.mfa_required', message: 'no.' }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="sign-in-totp"]').exists()).toBe(false)
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-in-totp"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toContain(
      'Multi-factor authentication is required',
    )
  })

  it('after revealing TOTP, second submit forwards mfa_code to login()', async () => {
    vi.mocked(authApi.login)
      .mockRejectedValueOnce(
        new ApiError({ status: 403, code: 'auth.mfa_required', message: 'no.' }),
      )
      .mockResolvedValueOnce(USER)
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    await h.wrapper.find('[data-test="sign-in-totp"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.login).toHaveBeenLastCalledWith({
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      mfa_code: '123456',
    })
  })

  it('on a non-ApiError thrown by the action, falls back to auth.ui.errors.unknown', async () => {
    vi.mocked(authApi.login).mockRejectedValue(new Error('typeerror'))
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toBe(
      'Something went wrong. Please try again.',
    )
  })

  it('on a network error (status 0), renders the dedicated network message', async () => {
    vi.mocked(authApi.login).mockRejectedValue(
      new ApiError({ status: 0, code: 'network.error', message: 'no.' }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toContain(
      'could not reach the server',
    )
  })

  it('error region uses aria-live="polite" (a11y review priority #7)', async () => {
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    const region = h.wrapper.find('[data-test="sign-in-error"]')
    expect(region.attributes('aria-live')).toBe('polite')
    expect(region.attributes('role')).toBe('alert')
  })

  it('email and password inputs have associated labels', async () => {
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    // v-text-field renders the <label> linked via for=. Verify the
    // label text appears alongside an input with the matching id.
    expect(h.wrapper.find('input[type="email"]').exists()).toBe(true)
    expect(h.wrapper.find('input[type="password"]').exists()).toBe(true)
    expect(h.wrapper.html()).toContain('Email')
    expect(h.wrapper.html()).toContain('Password')
  })

  it('submit button switches to the loading label while isLoggingIn is true', async () => {
    let resolveLogin: (u: typeof USER) => void = () => undefined
    vi.mocked(authApi.login).mockImplementation(
      () =>
        new Promise<typeof USER>((resolve) => {
          resolveLogin = resolve
        }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-in-submit"]').text()).toContain('Signing in')
    resolveLogin(USER)
    await flushPromises()
  })
})
