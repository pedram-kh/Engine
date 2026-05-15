import { ApiError } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import SignUpPage from './SignUpPage.vue'

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
    email_verified_at: null,
    name: 'A',
    user_type: 'creator' as const,
    preferred_language: 'en' as const,
    preferred_currency: null,
    timezone: null,
    theme_preference: 'system' as const,
    mfa_required: false,
    two_factor_enabled: false,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
}

describe('SignUpPage', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the sign-up heading from i18n', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="sign-up-heading"]').text()).toBe(
      'Create your Catalyst account',
    )
  })

  it('happy path: forwards the form to signUp() and navigates to verify-email pending with email in query', async () => {
    vi.mocked(authApi.signUp).mockResolvedValue(USER)
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
    await h.wrapper.find('[data-test="sign-up-email"] input').setValue('a@b.c')
    await h.wrapper.find('[data-test="sign-up-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper
      .find('[data-test="sign-up-password-confirmation"] input')
      .setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.signUp).toHaveBeenCalledWith({
      name: 'Alice',
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      password_confirmation: 'Pa$$w0rd!12',
    })
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'auth.verify-email.pending',
      query: { email: 'a@b.c' },
    })
  })

  it('on auth.signup.email_taken, renders the i18n string inline', async () => {
    vi.mocked(authApi.signUp).mockRejectedValue(
      new ApiError({ status: 422, code: 'auth.signup.email_taken', message: 'no.' }),
    )
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
    await h.wrapper.find('[data-test="sign-up-email"] input').setValue('a@b.c')
    await h.wrapper.find('[data-test="sign-up-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper
      .find('[data-test="sign-up-password-confirmation"] input')
      .setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-up-error"]').text()).toContain('already exists')
  })

  it('on auth.password.too_short, interpolates {min}', async () => {
    vi.mocked(authApi.signUp).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'auth.password.too_short',
        message: 'no.',
        details: [{ code: 'auth.password.too_short', meta: { min: 12 } }],
      }),
    )
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
    await h.wrapper.find('[data-test="sign-up-email"] input').setValue('a@b.c')
    await h.wrapper.find('[data-test="sign-up-password"] input').setValue('short')
    await h.wrapper.find('[data-test="sign-up-password-confirmation"] input').setValue('short')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-up-error"]').text()).toContain('12 characters')
  })

  it('on a non-ApiError, falls back to auth.ui.errors.unknown', async () => {
    vi.mocked(authApi.signUp).mockRejectedValue(new Error('boom'))
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
    await h.wrapper.find('[data-test="sign-up-email"] input').setValue('a@b.c')
    await h.wrapper.find('[data-test="sign-up-password"] input').setValue('whatever')
    await h.wrapper.find('[data-test="sign-up-password-confirmation"] input').setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-up-error"]').text()).toBe(
      'Something went wrong. Please try again.',
    )
  })

  it('error region uses aria-live="polite" (a11y)', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    const region = h.wrapper.find('[data-test="sign-up-error"]')
    expect(region.attributes('aria-live')).toBe('polite')
  })

  // --------------------------------------------------------------------------
  // Sprint 3 Chunk 4 sub-step 4 — magic-link invitation forward path
  // --------------------------------------------------------------------------

  it('does NOT render the invitation banner when there is no ?token= query', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="sign-up-invitation-banner"]').exists()).toBe(false)
  })

  it('renders the invitation banner when ?token=<token> is present', async () => {
    const h = await mountAuthPage(SignUpPage, {
      initialRoute: { path: '/sign-up', query: { token: 'abc-token' } },
    })
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="sign-up-invitation-banner"]').exists()).toBe(true)
  })

  it('forwards invitation_token in the signUp() payload when the URL carries ?token=', async () => {
    vi.mocked(authApi.signUp).mockResolvedValue(USER)
    const h = await mountAuthPage(SignUpPage, {
      initialRoute: { path: '/sign-up', query: { token: 'magic-token' } },
    })
    teardown = h.unmount
    await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
    await h.wrapper.find('[data-test="sign-up-email"] input').setValue('a@b.c')
    await h.wrapper.find('[data-test="sign-up-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper
      .find('[data-test="sign-up-password-confirmation"] input')
      .setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.signUp).toHaveBeenCalledWith({
      name: 'Alice',
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      password_confirmation: 'Pa$$w0rd!12',
      invitation_token: 'magic-token',
    })
  })

  it('on successful invitation signup, navigates to /sign-in?invited=1 (not the verify-email-pending page)', async () => {
    vi.mocked(authApi.signUp).mockResolvedValue(USER)
    const h = await mountAuthPage(SignUpPage, {
      initialRoute: { path: '/sign-up', query: { token: 'magic-token' } },
    })
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
    await h.wrapper.find('[data-test="sign-up-email"] input').setValue('a@b.c')
    await h.wrapper.find('[data-test="sign-up-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper
      .find('[data-test="sign-up-password-confirmation"] input')
      .setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'auth.sign-in',
      query: { invited: '1' },
    })
  })

  it('on invitation.email_mismatch, renders the i18n string inline', async () => {
    vi.mocked(authApi.signUp).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'invitation.email_mismatch',
        message: 'Email does not match.',
      }),
    )
    const h = await mountAuthPage(SignUpPage, {
      initialRoute: { path: '/sign-up', query: { token: 'magic-token' } },
    })
    teardown = h.unmount
    await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
    await h.wrapper.find('[data-test="sign-up-email"] input').setValue('wrong@example.com')
    await h.wrapper.find('[data-test="sign-up-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper
      .find('[data-test="sign-up-password-confirmation"] input')
      .setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-up-error"]').text()).toContain('different email')
  })

  it('submit button shows loading label while isSigningUp is true', async () => {
    let resolveSignUp: (u: typeof USER) => void = () => undefined
    vi.mocked(authApi.signUp).mockImplementation(
      () =>
        new Promise<typeof USER>((resolve) => {
          resolveSignUp = resolve
        }),
    )
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
    await h.wrapper.find('[data-test="sign-up-email"] input').setValue('a@b.c')
    await h.wrapper.find('[data-test="sign-up-password"] input').setValue('whatever')
    await h.wrapper.find('[data-test="sign-up-password-confirmation"] input').setValue('whatever')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-up-submit"]').text()).toContain('Submitting')
    resolveSignUp(USER)
    await flushPromises()
  })
})
