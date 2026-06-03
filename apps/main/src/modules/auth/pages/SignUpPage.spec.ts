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
      // Sprint 5 Chunk C — the auto-detected browser zone always rides the
      // payload. Its value is environment-dependent (the test runner's tz),
      // so we assert presence + type rather than a fixed zone here; the
      // dedicated test below pins it to the exact resolved value.
      timezone: expect.any(String),
    })
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'auth.verify-email.pending',
      query: { email: 'a@b.c' },
    })
  })

  it('forwards the auto-detected browser timezone in the signUp() payload (Sprint 5 Chunk C)', async () => {
    vi.mocked(authApi.signUp).mockResolvedValue(USER)
    // Read the same source the component reads so the assertion is
    // deterministic regardless of which tz the CI runner sits in.
    const expectedTz = Intl.DateTimeFormat().resolvedOptions().timeZone
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
    expect(authApi.signUp).toHaveBeenCalledWith(expect.objectContaining({ timezone: expectedTz }))
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

  // ---------------------------------------------------------------------
  // Stabilization (post-Sprint 3) — per-field rendering for the real
  // `validation.failed` envelope shape emitted by
  // App\Core\Errors\ValidationExceptionRenderer. Without this binding
  // the password-too-short message stays trapped in details[] and the
  // user sees the generic "Something went wrong" banner instead of the
  // specific reason. The earlier tests in this file stub the top-level
  // `code` directly (e.g. `auth.password.too_short`) which is NOT what
  // the real backend ships; this test pins the real shape so the
  // contract drift can't recur.
  // ---------------------------------------------------------------------

  it('binds per-field validation messages (real `validation.failed` envelope, password too short)', async () => {
    vi.mocked(authApi.signUp).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'The password field must be at least 12 characters.',
        details: [
          {
            code: 'validation.failed',
            title: 'The password field must be at least 12 characters.',
            detail: 'The password field must be at least 12 characters.',
            source: { pointer: '/data/attributes/password' },
            meta: { field: 'password', rule: 'App\\Modules\\Identity\\Rules\\StrongPassword' },
          },
        ],
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

    // The message must render under the password field, not in the
    // top-level banner — that's the whole point of this fix.
    const passwordField = h.wrapper.find('[data-test="sign-up-password"]')
    expect(passwordField.text()).toContain('at least 12 characters')

    // The top-level banner stays empty when per-field rendering owns
    // the surface (single signal source per error class).
    expect(h.wrapper.find('[data-test="sign-up-error"]').text()).toBe('')
  })

  it('binds multiple per-field errors simultaneously (name + email + password)', async () => {
    vi.mocked(authApi.signUp).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'The name field is required.',
        details: [
          {
            code: 'validation.failed',
            title: 'The name field is required.',
            detail: 'The name field is required.',
            source: { pointer: '/data/attributes/name' },
            meta: { field: 'name' },
          },
          {
            code: 'validation.failed',
            title: 'The email field must be a valid email address.',
            detail: 'The email field must be a valid email address.',
            source: { pointer: '/data/attributes/email' },
            meta: { field: 'email' },
          },
          {
            code: 'validation.failed',
            title: 'The password field is required.',
            detail: 'The password field is required.',
            source: { pointer: '/data/attributes/password' },
            meta: { field: 'password' },
          },
        ],
      }),
    )
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="sign-up-name"]').text()).toContain('name field is required')
    expect(h.wrapper.find('[data-test="sign-up-email"]').text()).toContain('valid email address')
    expect(h.wrapper.find('[data-test="sign-up-password"]').text()).toContain(
      'password field is required',
    )
    expect(h.wrapper.find('[data-test="sign-up-error"]').text()).toBe('')
  })

  it('clears per-field errors on the next submit', async () => {
    vi.mocked(authApi.signUp).mockRejectedValueOnce(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'fail',
        details: [
          {
            code: 'validation.failed',
            title: 'The password field is required.',
            detail: 'The password field is required.',
            source: { pointer: '/data/attributes/password' },
            meta: { field: 'password' },
          },
        ],
      }),
    )
    vi.mocked(authApi.signUp).mockResolvedValueOnce(USER)

    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
    await h.wrapper.find('[data-test="sign-up-email"] input').setValue('a@b.c')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="sign-up-password"]').text()).toContain(
      'password field is required',
    )

    await h.wrapper.find('[data-test="sign-up-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper
      .find('[data-test="sign-up-password-confirmation"] input')
      .setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    // After the successful retry, the previous per-field error must
    // have been cleared from the DOM (resetForNewSubmit semantics).
    expect(h.wrapper.find('[data-test="sign-up-password"]').text()).not.toContain(
      'password field is required',
    )
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
      // Sprint 5 Chunk C — the browser tz rides the invite-acceptance path
      // too (same SignUpPage handles both entry points — D-c1).
      timezone: expect.any(String),
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
