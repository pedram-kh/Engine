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

// Default fixture for tests that don't care about post-login dispatch.
// The stabilization fix routes by `user_type`: creators land on
// `/onboarding`, every other user_type lands on `/`. Tests that
// specifically pin the dispatch rule use the AGENCY_USER / CREATOR
// fixtures below.
const USER = {
  type: 'user' as const,
  id: '01HQ',
  attributes: {
    email: 'a@b.c',
    email_verified_at: '2026-01-01T00:00:00Z',
    name: 'A',
    last_name: null,
    user_type: 'agency_user' as const,
    preferred_language: 'en' as const,
    preferred_currency: 'USD',
    timezone: 'Europe/Lisbon',
    theme_preference: 'system' as const,
    mfa_required: false,
    mfa_exempt: false,
    two_factor_enabled: true,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
}

const CREATOR_USER = {
  ...USER,
  attributes: { ...USER.attributes, user_type: 'creator' as const },
}

// Unverified creator fixture. Caught by playwright/specs/
// 2fa-enrollment-and-sign-in.spec.ts:125 and failed-login-lockout-
// and-reset.spec.ts:244 in CI when c479189's `postLoginTarget()`
// dispatched these users to /onboarding without first checking
// `email_verified_at` — the resulting requireOnboardingAccess
// bootstrap() 403s and the SPA shows the generic banner with no
// URL change. The post-stabilization rule: unverified creators go
// to /verify-email/pending instead.
const CREATOR_USER_UNVERIFIED = {
  ...CREATOR_USER,
  attributes: { ...CREATOR_USER.attributes, email_verified_at: null },
}

function pendingError(via?: 'totp' | 'recovery_code'): ApiError {
  return new ApiError({
    status: 403,
    code: 'auth.deletion_pending',
    message: 'This account is scheduled for deletion.',
    details: [
      {
        code: 'auth.deletion_pending',
        meta: { scheduled_for: '2026-11-04T09:30:00+00:00', ...(via ? { mfa_via: via } : {}) },
      },
    ],
  })
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
    expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe('Sign in')
  })

  it('loads the Meta Pixel on mount', async () => {
    // This page is the pixel's ONLY mount point, deliberately: the
    // sibling auth routes carry single-use tokens in their query strings
    // and the pixel reports the document location. If this assertion is
    // ever moved to the layout or index.html, that guarantee is gone.
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    expect(typeof window.fbq).toBe('function')
    const queued = (window.fbq?.queue ?? []) as unknown[][]
    expect(queued).toContainEqual(['track', 'PageView'])
    // Advanced matching off, or the pixel harvests the email field below.
    expect(queued).toContainEqual(['set', 'autoConfig', false, '1372598514719791'])
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

  it('shows the deletion-scheduled banner, with the date, after the delete-account dialog (AH-104b-del)', async () => {
    const h = await mountAuthPage(SignInPage, {
      initialRoute: {
        path: '/sign-in',
        query: { reason: 'deletion_scheduled', date: '2026-11-04T10:00:00+00:00' },
      },
    })
    teardown = h.unmount
    await flushPromises()
    const banner = h.wrapper.find('[data-test="deletion-scheduled-banner"]')
    expect(banner.exists()).toBe(true)
    expect(banner.text()).toContain('November')
    expect(banner.text()).toContain('2026')
    expect(h.wrapper.find('[data-test="session-expired-banner"]').exists()).toBe(false)
  })

  it('shows no deletion banner for a missing or unparseable date', async () => {
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { path: '/sign-in', query: { reason: 'deletion_scheduled', date: 'soon' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="deletion-scheduled-banner"]').exists()).toBe(false)
  })

  it('happy path: agency_user — calls login() and pushes to / on 2xx', async () => {
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

  // ---------------------------------------------------------------------
  // Stabilization (post-Sprint 3) — post-login dispatch by user_type.
  // Pre-fix, every user (including creators) was pushed to `/`, which
  // is `app.dashboard` → the agency dashboard page wrapped in the agency
  // shell (sidebar: Dashboard / Brands / Team / Settings). A creator
  // who arrived via bulk-invite → magic-link → sign-up → sign-in
  // therefore landed on the wrong layout entirely. The fix dispatches
  // by `user_type` when no meaningful `?redirect=` is set.
  // ---------------------------------------------------------------------

  it('happy path: creator — pushes to onboarding.welcome-back on 2xx (post-Sprint-3 stabilization)', async () => {
    vi.mocked(authApi.login).mockResolvedValue(CREATOR_USER)
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({ name: 'onboarding.welcome-back' })
  })

  it('happy path: creator — ignores ?redirect=/ (default agency home) and dispatches to wizard', async () => {
    vi.mocked(authApi.login).mockResolvedValue(CREATOR_USER)
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { path: '/sign-in', query: { redirect: '/' } },
    })
    teardown = h.unmount
    await flushPromises()
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({ name: 'onboarding.welcome-back' })
  })

  it('happy path: unverified creator — pushes to auth.verify-email.pending (NOT onboarding) so the bootstrap 403 is avoided', async () => {
    vi.mocked(authApi.login).mockResolvedValue(CREATOR_USER_UNVERIFIED)
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'auth.verify-email.pending',
      query: { email: 'a@b.c' },
    })
  })

  it('happy path: unverified creator with ?redirect=/ still bounces to verify-email-pending (the /-default redirect is ignored on this branch too)', async () => {
    vi.mocked(authApi.login).mockResolvedValue(CREATOR_USER_UNVERIFIED)
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { path: '/sign-in', query: { redirect: '/' } },
    })
    teardown = h.unmount
    await flushPromises()
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'auth.verify-email.pending',
      query: { email: 'a@b.c' },
    })
  })

  it('happy path: pushes to ?redirect=/foo when query param is set (session-expired flow, both user_types)', async () => {
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

  it('happy path: creator with a wizard ?redirect= honors the redirect (session-expired flow)', async () => {
    vi.mocked(authApi.login).mockResolvedValue(CREATOR_USER)
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { path: '/sign-in', query: { redirect: '/onboarding/profile' } },
    })
    teardown = h.unmount
    await flushPromises()
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith('/onboarding/profile')
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

  // ---------------------------------------------------------------------
  // AH-086 — agency-facing variant (`auth.agency-sign-in`, `/agency/sign-in`).
  // Same component, parameterized by route name (mirrors AuthLayout's
  // `isHero` mechanism). These cases pin the CTA-absence + the
  // shared-component reality: no new Playwright leg, per the kickoff's D4 —
  // this is Vitest territory, same as the other route-driven variants.
  // ---------------------------------------------------------------------

  it('agency variant: shows the agency heading and hides the creator-signup CTA', async () => {
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { name: 'auth.agency-sign-in' },
    })
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe('Agency sign in')
    expect(h.wrapper.find('[data-test="sign-in-signup-link"]').exists()).toBe(false)
    // The forgot-password link is untouched — only the signup CTA is scoped out.
    expect(h.wrapper.find('[data-test="sign-in-forgot-link"]').exists()).toBe(true)
  })

  it('default /sign-in still shows the creator-signup CTA (zero diff for the existing page)', async () => {
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="sign-in-signup-link"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe('Sign in')
  })

  it('agency variant: a door, not a wall — same endpoint, login still calls authApi.login() and role-routes home', async () => {
    vi.mocked(authApi.login).mockResolvedValue(USER)
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { name: 'auth.agency-sign-in' },
    })
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.login).toHaveBeenCalledWith({ email: 'a@b.c', password: 'Pa$$w0rd!12' })
    expect(pushSpy).toHaveBeenCalledWith('/')
  })

  it('agency variant: a creator who signs in here still routes to onboarding.welcome-back — the page is a door, not a wall', async () => {
    vi.mocked(authApi.login).mockResolvedValue(CREATOR_USER)
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { name: 'auth.agency-sign-in' },
    })
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({ name: 'onboarding.welcome-back' })
  })

  // ---------------------------------------------------------------------
  // AH-086b — the team-invite email's magic link now carries
  // ?token=&agency= on the agency variant, forwarding to the SAME
  // accept-invitation page/flow (byte-identical), never rendering the
  // sign-in form for that hop.
  // ---------------------------------------------------------------------

  it('agency variant: a token+agency query forwards to accept-invitation, preserving both params unchanged', async () => {
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { name: 'auth.agency-sign-in', query: { token: 'abc123', agency: '01ABCDEF' } },
    })
    teardown = h.unmount
    // Synchronously — before the async router.replace() below even
    // resolves — the sign-in form never renders for this hop: the
    // template's v-if gates on the SAME computed the forward reads.
    expect(h.wrapper.find('[data-test="sign-in-page"]').exists()).toBe(false)

    // The forward itself is an async router.replace() fired from
    // onMounted, landing on accept-invitation (a lazy-imported route),
    // so a single flushPromises() pass isn't always enough — poll.
    await vi.waitFor(() => {
      expect(h.router.currentRoute.value.name).toBe('accept-invitation')
    })
    expect(h.router.currentRoute.value.query).toEqual({ token: 'abc123', agency: '01ABCDEF' })
  })

  it('agency variant: without a token+agency query, renders the normal sign-in form (no forward)', async () => {
    const h = await mountAuthPage(SignInPage, { initialRoute: { name: 'auth.agency-sign-in' } })
    teardown = h.unmount
    await flushPromises()
    expect(h.router.currentRoute.value.name).toBe('auth.agency-sign-in')
    expect(h.wrapper.find('[data-test="sign-in-page"]').exists()).toBe(true)
  })

  it('agency variant: a token WITHOUT agency (or vice versa) does not forward — renders the form instead', async () => {
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { name: 'auth.agency-sign-in', query: { token: 'abc123' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.router.currentRoute.value.name).toBe('auth.agency-sign-in')
    expect(h.wrapper.find('[data-test="sign-in-page"]').exists()).toBe(true)
  })

  it('default /sign-in ignores a token+agency query — the forward is agency-variant-only (D2 zero-diff holds)', async () => {
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { path: '/sign-in', query: { token: 'abc123', agency: '01ABCDEF' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.router.currentRoute.value.name).toBe('auth.sign-in')
    expect(h.wrapper.find('[data-test="sign-in-page"]').exists()).toBe(true)
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

describe('SignInPage — account scheduled for deletion (AH-104b-del)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    // clearAllMocks keeps queued once-values; a leftover would answer the next test's first call.
    vi.mocked(authApi.login).mockReset()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  async function fill(h: Awaited<ReturnType<typeof mountAuthPage>>): Promise<void> {
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
  }

  it('swaps the form for the restore step, with the date, once the credentials were right', async () => {
    vi.mocked(authApi.login).mockRejectedValueOnce(pendingError())
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    await fill(h)

    expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe(
      'Your account is scheduled for deletion',
    )
    expect(h.wrapper.find('[data-test="sign-in-deletion-body"]').text()).toContain(
      'November 4, 2026',
    )
    expect(h.wrapper.find('[data-test="sign-in-email"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-in-password"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-in-totp"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-in-submit"]').text()).toBe('Restore my account')
    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toBe('')
    // Nothing signed in: the first answer carried no session.
    expect(authApi.login).toHaveBeenCalledTimes(1)
    expect(authApi.login).toHaveBeenCalledWith({ email: 'a@b.c', password: 'Pa$$w0rd!12' })
  })

  it('restore re-sends the same credentials with cancel_deletion and then signs in', async () => {
    vi.mocked(authApi.login)
      .mockRejectedValueOnce(pendingError())
      .mockResolvedValueOnce(CREATOR_USER)
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await fill(h)

    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(authApi.login).toHaveBeenLastCalledWith({
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      cancel_deletion: true,
    })
    expect(pushSpy).toHaveBeenCalledWith({ name: 'onboarding.welcome-back' })
  })

  it('an authenticator-code account restores with a fresh code and shows the hint', async () => {
    vi.mocked(authApi.login)
      .mockRejectedValueOnce(
        new ApiError({ status: 403, code: 'auth.mfa_required', message: 'no.' }),
      )
      .mockRejectedValueOnce(pendingError('totp'))
      .mockResolvedValueOnce(CREATOR_USER)
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await fill(h)
    await h.wrapper.find('[data-test="sign-in-totp"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="sign-in-deletion-hint"]').text()).toBe(
      'Confirm with a code from your authenticator app.',
    )
    expect(h.wrapper.find('[data-test="sign-in-totp"]').exists()).toBe(true)

    await h.wrapper.find('[data-test="sign-in-totp"] input').setValue('654321')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(authApi.login).toHaveBeenLastCalledWith({
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      mfa_code: '654321',
      cancel_deletion: true,
    })
  })

  it('a recovery-code sign-in clears the spent code and asks for another', async () => {
    vi.mocked(authApi.login)
      .mockRejectedValueOnce(
        new ApiError({ status: 403, code: 'auth.mfa_required', message: 'no.' }),
      )
      .mockRejectedValueOnce(pendingError('recovery_code'))
      .mockResolvedValueOnce(CREATOR_USER)
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await fill(h)
    await h.wrapper.find('[data-test="sign-in-totp"] input').setValue('ABCD-EFGH')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    const field = h.wrapper.find('[data-test="sign-in-totp"] input')
    expect(field.exists()).toBe(true)
    expect((field.element as HTMLInputElement).value).toBe('')
    expect(h.wrapper.find('[data-test="sign-in-deletion-hint"]').text()).toContain('now used up')

    await field.setValue('IJKL-MNOP')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(authApi.login).toHaveBeenLastCalledWith({
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      mfa_code: 'IJKL-MNOP',
      cancel_deletion: true,
    })
  })

  it('"Not now" returns to an empty sign-in form and sends nothing', async () => {
    vi.mocked(authApi.login).mockRejectedValueOnce(pendingError())
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await fill(h)

    await h.wrapper.find('[data-test="sign-in-deletion-leave"]').trigger('click')
    await flushPromises()

    expect(authApi.login).toHaveBeenCalledTimes(1)
    expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe('Sign in')
    expect(h.wrapper.find('[data-test="sign-in-deletion-body"]').exists()).toBe(false)
    const password = h.wrapper.find('input[type="password"]').element as HTMLInputElement
    expect(password.value).toBe('')
    expect(h.wrapper.find('[data-test="sign-in-submit"]').text()).toBe('Sign in')
  })

  it('a failed restore keeps the restore step and shows the error in the usual region', async () => {
    vi.mocked(authApi.login)
      .mockRejectedValueOnce(pendingError())
      .mockRejectedValueOnce(
        new ApiError({ status: 403, code: 'auth.invalid_credentials', message: 'no.' }),
      )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await fill(h)

    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="sign-in-deletion-body"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).not.toBe('')
  })

  it('an ordinary wrong password never shows the restore step', async () => {
    vi.mocked(authApi.login).mockRejectedValueOnce(
      new ApiError({ status: 401, code: 'auth.invalid_credentials', message: 'no.' }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    await fill(h)

    expect(h.wrapper.find('[data-test="sign-in-deletion-body"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe('Sign in')
  })

  it('an auth.deletion_pending with no usable date is not rendered as a restore step', async () => {
    vi.mocked(authApi.login).mockRejectedValueOnce(
      new ApiError({ status: 403, code: 'auth.deletion_pending', message: 'x' }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    await fill(h)

    expect(h.wrapper.find('[data-test="sign-in-deletion-body"]').exists()).toBe(false)
  })
})
