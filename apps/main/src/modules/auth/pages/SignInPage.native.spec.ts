/**
 * AH-100 D5 — SignInPage as the creator app's sign-in screen. The page is
 * the same component; these specs pin what differs inside the Capacitor
 * shell (token login, no Meta Pixel, no sign-up funnel, the wrong-door
 * message) and that the web polarity of each branch is unchanged.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import SignInPage from './SignInPage.vue'

const platform = vi.hoisted(() => ({ native: true }))
const tokenStore = vi.hoisted(() => ({
  read: vi.fn(async () => null),
  write: vi.fn(async () => undefined),
  clear: vi.fn(async () => undefined),
}))
const loadMetaPixel = vi.hoisted(() => vi.fn())

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'ios' : null),
}))
vi.mock('@/core/auth/tokenStore', () => ({ nativeTokenStore: () => tokenStore }))
vi.mock('@/modules/auth/internal/metaPixel', () => ({ loadMetaPixel }))
vi.mock('@/modules/auth/api/auth.api', () => ({
  authApi: {
    me: vi.fn(),
    login: vi.fn(),
    tokenLogin: vi.fn(),
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

const CREATOR = {
  type: 'user' as const,
  id: '01HQ',
  attributes: {
    email: 'a@b.c',
    email_verified_at: '2026-01-01T00:00:00Z',
    name: 'A',
    last_name: null,
    user_type: 'creator' as const,
    preferred_language: 'en' as const,
    preferred_currency: 'USD',
    timezone: 'Europe/Lisbon',
    theme_preference: 'system' as const,
    mfa_required: false,
    mfa_exempt: false,
    two_factor_enabled: false,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
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

const WEB_SIGN_IN = 'https://platform.catalyst-growth.com/sign-in'

function wrongSpa(): ApiError {
  return new ApiError({
    status: 403,
    code: 'auth.wrong_spa',
    message: 'no.',
    details: [{ code: 'auth.wrong_spa', meta: { correct_spa_url: WEB_SIGN_IN } }],
  })
}

describe('SignInPage — creator app (native)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    platform.native = true
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  async function submit(h: Awaited<ReturnType<typeof mountAuthPage>>): Promise<void> {
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
  }

  it('does not load the Meta Pixel', async () => {
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    expect(loadMetaPixel).not.toHaveBeenCalled()
  })

  it('replaces the sign-up link with the web sign-up pointer', async () => {
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="sign-in-signup-link"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-in-native-create-account"]').text()).toBe(
      'New to Catalyst? Create your account at platform.catalyst-growth.com, then sign in here.',
    )
    expect(h.wrapper.find('[data-test="sign-in-forgot-link"]').exists()).toBe(true)
  })

  it('signs in through /auth/token, stores the token, and lands a creator on welcome-back', async () => {
    vi.mocked(authApi.tokenLogin).mockResolvedValue({
      user: CREATOR,
      token: 'tok-1',
      expiresAt: null,
    })
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')

    await submit(h)

    expect(authApi.tokenLogin).toHaveBeenCalledWith({
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      platform: 'ios',
    })
    expect(authApi.login).not.toHaveBeenCalled()
    expect(tokenStore.write).toHaveBeenCalledWith('tok-1', null)
    expect(pushSpy).toHaveBeenCalledWith({ name: 'onboarding.welcome-back' })
  })

  it('on auth.mfa_required reveals the code field and resubmits it as mfa_code', async () => {
    vi.mocked(authApi.tokenLogin)
      .mockRejectedValueOnce(new ApiError({ status: 401, code: 'auth.mfa_required', message: '' }))
      .mockResolvedValueOnce({ user: CREATOR, token: 'tok-1', expiresAt: null })
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    await submit(h)
    await h.wrapper.find('[data-test="sign-in-totp"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(authApi.tokenLogin).toHaveBeenLastCalledWith({
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      platform: 'ios',
      mfa_code: '123456',
    })
  })

  describe('session-expired banner (AH-100b D3)', () => {
    it('shows it for the 401 policy (?reason=session_expired)', async () => {
      const h = await mountAuthPage(SignInPage, {
        initialRoute: { path: '/sign-in', query: { reason: 'session_expired' } },
      })
      teardown = h.unmount

      expect(h.wrapper.find('[data-test="session-expired-banner"]').exists()).toBe(true)
    })

    it('shows it for a lapsed cold start (the bootstrap raised the lapse flag)', async () => {
      const h = await mountAuthPage(SignInPage, {
        beforeMount: ({ pinia }) => {
          useAuthStore(pinia).sessionLapsed = true
        },
      })
      teardown = h.unmount

      expect(h.wrapper.find('[data-test="session-expired-banner"]').exists()).toBe(true)
    })

    it('does NOT show it on a plain landing — a deliberate logout, a fresh install, a cold start after logout', async () => {
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      expect(h.wrapper.find('[data-test="session-expired-banner"]').exists()).toBe(false)
    })

    it('the banner goes once the lapse flag is cleared (login or logout clears it)', async () => {
      const h = await mountAuthPage(SignInPage, {
        beforeMount: ({ pinia }) => {
          useAuthStore(pinia).sessionLapsed = true
        },
      })
      teardown = h.unmount
      expect(h.wrapper.find('[data-test="session-expired-banner"]').exists()).toBe(true)

      useAuthStore().sessionLapsed = false
      await flushPromises()

      expect(h.wrapper.find('[data-test="session-expired-banner"]').exists()).toBe(false)
    })
  })

  it('on auth.wrong_spa renders the app wrong-door message with the web address', async () => {
    vi.mocked(authApi.tokenLogin).mockRejectedValue(wrongSpa())
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    await submit(h)

    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toBe(
      `The Catalyst app is for creators. Please sign in to your account on the web at ${WEB_SIGN_IN}.`,
    )
    expect(tokenStore.write).not.toHaveBeenCalled()
  })

  it('on auth.wrong_spa without a correct_spa_url falls back to the backend code copy', async () => {
    vi.mocked(authApi.tokenLogin).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.wrong_spa', message: 'no.' }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    await submit(h)

    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toContain(
      'This account is registered for the admin console',
    )
  })

  it.each([
    ['auth.invalid_credentials', 401, {}, 'Invalid email or password.'],
    ['auth.mfa.invalid_code', 401, {}, 'The two-factor code is invalid.'],
    ['auth.mfa.rate_limited', 423, { minutes: 3 }, 'try again in 3 minutes'],
    ['auth.mfa.enrollment_suspended', 423, {}, 'has been suspended'],
    ['auth.account_locked.suspended', 423, {}, 'This account has been locked.'],
    ['auth.account_locked.temporary', 423, {}, 'Too many failed sign-in attempts.'],
  ])('renders %s inline', async (code, status, meta, expected) => {
    vi.mocked(authApi.tokenLogin).mockRejectedValue(
      new ApiError({ status, code, message: 'no.', details: [{ code, meta }] }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    await submit(h)

    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toContain(expected)
  })

  // AH-101 U2 — Figma frame 02 (sheet) and 02b (failed state).
  describe('the sign-in sheet (AH-101 U2)', () => {
    it('draws the brand mark above the sheet, and the title and one-sentence welcome', async () => {
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      expect(h.wrapper.find('section').classes()).toContain('sign-in-native')
      expect(h.wrapper.find('[data-test="sign-in-native-mark"]').exists()).toBe(true)
      expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe('Sign in')
      expect(h.wrapper.find('[data-test="sign-in-native-subtitle"]').text()).toBe(
        'Welcome back. Sign in to see your campaigns, invitations and messages.',
      )
    })

    it('puts the labels above the fields, not inside them', async () => {
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      const email = h.wrapper.find('label[for="sign-in-email"]')
      const password = h.wrapper.find('label[for="sign-in-password"]')
      expect(email.text()).toBe('Email')
      expect(password.text()).toBe('Password')
      // The field itself carries no floating label of its own.
      expect(h.wrapper.find('[data-test="sign-in-email"] .v-label').exists()).toBe(false)
      expect(h.wrapper.find('[data-test="sign-in-password"] .v-label').exists()).toBe(false)
      // …and the label really does name the input (for → id).
      expect(h.wrapper.find('input#sign-in-email').exists()).toBe(true)
      expect(h.wrapper.find('input#sign-in-password').exists()).toBe(true)
    })

    it('carries the field icons', async () => {
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      expect(h.wrapper.find('[data-test="sign-in-email"] .mdi-email-outline').exists()).toBe(true)
      expect(h.wrapper.find('[data-test="sign-in-password"] .mdi-lock-outline').exists()).toBe(true)
    })

    it('puts "forgot password" in the password label row, once', async () => {
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      const links = h.wrapper.findAll('[data-test="sign-in-forgot-link"]')
      expect(links).toHaveLength(1)
      expect(links[0]!.element.closest('.sign-in-native__label-row')).not.toBeNull()
    })

    it('the pointer line names the site in its own span, with the full sentence unchanged', async () => {
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      const pointer = h.wrapper.find('[data-test="sign-in-native-create-account"]')
      expect(pointer.element.tagName).toBe('P')
      expect(pointer.find('.sign-in-native__site').text()).toBe('platform.catalyst-growth.com')
      expect(h.wrapper.text()).not.toContain('Create an account')
    })

    it('the sign-in button is the large (44px) primary button', async () => {
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      const btn = h.wrapper.find('[data-test="sign-in-submit"]')
      expect(btn.classes()).toContain('v-btn--size-large')
      expect(btn.classes()).toContain('bg-primary')
    })

    it('before any failure: the live region is present but silent, the mark is full size', async () => {
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      const region = h.wrapper.find('[data-test="sign-in-error"]')
      expect(region.attributes('role')).toBe('alert')
      expect(region.classes()).not.toContain('sign-in-native__error--on')
      expect(region.text()).toBe('')
      expect(h.wrapper.find('[data-test="sign-in-native-mark"]').classes()).not.toContain(
        'sign-in-native__mark--compact',
      )
    })

    it('a failed sign-in draws the banner, outlines the credentials and shrinks the mark (02b)', async () => {
      vi.mocked(authApi.tokenLogin).mockRejectedValue(
        new ApiError({ status: 401, code: 'auth.invalid_credentials', message: 'no.' }),
      )
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      await submit(h)

      const region = h.wrapper.find('[data-test="sign-in-error"]')
      expect(region.classes()).toContain('sign-in-native__error--on')
      expect(region.find('.mdi-alert-circle-outline').exists()).toBe(true)
      expect(region.text()).toBe('Invalid email or password.')
      expect(h.wrapper.find('[data-test="sign-in-password"]').classes()).toContain('v-input--error')
      expect(h.wrapper.find('[data-test="sign-in-email"]').classes()).toContain('v-input--error')
      expect(h.wrapper.find('[data-test="sign-in-native-mark"]').classes()).toContain(
        'sign-in-native__mark--compact',
      )
    })

    it('the two-factor prompt is not a credential failure: no red outline, but the code field has a label', async () => {
      vi.mocked(authApi.tokenLogin).mockRejectedValue(
        new ApiError({ status: 401, code: 'auth.mfa_required', message: '' }),
      )
      const h = await mountAuthPage(SignInPage)
      teardown = h.unmount

      await submit(h)

      expect(h.wrapper.find('[data-test="sign-in-password"]').classes()).not.toContain(
        'v-input--error',
      )
      expect(h.wrapper.find('label[for="sign-in-totp"]').text()).toBe('Two-factor code')
      expect(h.wrapper.find('[data-test="sign-in-totp"] .v-label').exists()).toBe(false)
    })
  })
})

describe('SignInPage — web polarity of the native branches', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    platform.native = false
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('a raised lapse flag paints no banner on web — only ?reason=session_expired does', async () => {
    const h = await mountAuthPage(SignInPage, {
      beforeMount: ({ pinia }) => {
        useAuthStore(pinia).sessionLapsed = true
      },
    })
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="session-expired-banner"]').exists()).toBe(false)
  })

  it('loads the Meta Pixel and keeps the sign-up link, with no native pointer', async () => {
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    expect(loadMetaPixel).toHaveBeenCalledTimes(1)
    expect(h.wrapper.find('[data-test="sign-in-signup-link"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="sign-in-native-create-account"]').exists()).toBe(false)
  })

  it('renders auth.wrong_spa with the existing copy, not the app wrong-door message', async () => {
    vi.mocked(authApi.login).mockRejectedValue(wrongSpa())
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('x')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(authApi.tokenLogin).not.toHaveBeenCalled()
    expect(h.wrapper.find('[data-test="sign-in-error"]').text()).toContain(
      'This account is registered for the admin console',
    )
  })

  // AH-101 U2 — the sheet is native only; the web page keeps its own markup.
  it('has none of the sheet: no mark, subtitle, outside labels or native modifier', async () => {
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    expect(h.wrapper.find('section').classes()).not.toContain('sign-in-native')
    expect(h.wrapper.find('[data-test="sign-in-native-mark"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-in-native-subtitle"]').exists()).toBe(false)
    expect(h.wrapper.find('label[for="sign-in-email"]').exists()).toBe(true) // Vuetify's own
    expect(h.wrapper.find('[data-test="sign-in-email"] .v-label').text()).toBe('Email')
    expect(h.wrapper.find('[data-test="sign-in-password"] .v-label').text()).toBe('Password')
    expect(h.wrapper.find('.sign-in-native__label').exists()).toBe(false)
    expect(h.wrapper.find('.mdi-email-outline').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-in-submit"]').classes()).not.toContain(
      'v-btn--size-large',
    )
  })

  it('keeps the sign-up and forgot links in the bottom row, and the plain red error text', async () => {
    vi.mocked(authApi.login).mockRejectedValue(
      new ApiError({ status: 401, code: 'auth.invalid_credentials', message: 'no.' }),
    )
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount

    const links = h.wrapper.findAll('[data-test="sign-in-forgot-link"]')
    expect(links).toHaveLength(1)
    expect(links[0]!.element.closest('.sign-in-native__label-row')).toBeNull()
    expect(h.wrapper.find('[data-test="sign-in-signup-link"]').exists()).toBe(true)

    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('x')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    const region = h.wrapper.find('[data-test="sign-in-error"]')
    expect(region.classes()).toContain('text-error')
    expect(region.classes()).not.toContain('sign-in-native__error--on')
    expect(region.find('.mdi-alert-circle-outline').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-in-password"]').classes()).not.toContain(
      'v-input--error',
    )
  })
})

describe('SignInPage — creator app, account scheduled for deletion (AH-104b-del)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(authApi.tokenLogin).mockReset()
    platform.native = true
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('shows the restore step in the sheet and restores through /auth/token with cancel_deletion', async () => {
    vi.mocked(authApi.tokenLogin)
      .mockRejectedValueOnce(pendingError())
      .mockResolvedValueOnce({ user: CREATOR, token: 'tok-1', expiresAt: null })
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe(
      'Your account is scheduled for deletion',
    )
    expect(h.wrapper.find('[data-test="sign-in-deletion-body"]').text()).toContain(
      'November 4, 2026',
    )
    expect(h.wrapper.find('[data-test="sign-in-native-create-account"]').exists()).toBe(false)
    // No token was stored by the first, refused, attempt.
    expect(tokenStore.write).not.toHaveBeenCalled()

    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(authApi.login).not.toHaveBeenCalled()
    expect(authApi.tokenLogin).toHaveBeenLastCalledWith({
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      platform: 'ios',
      cancel_deletion: true,
    })
    expect(tokenStore.write).toHaveBeenCalledWith('tok-1', null)
    expect(pushSpy).toHaveBeenCalledWith({ name: 'onboarding.welcome-back' })
  })

  it('"Not now" puts the sheet back, with the sign-up pointer', async () => {
    vi.mocked(authApi.tokenLogin).mockRejectedValueOnce(pendingError())
    const h = await mountAuthPage(SignInPage)
    teardown = h.unmount
    await h.wrapper.find('input[type="email"]').setValue('a@b.c')
    await h.wrapper.find('input[type="password"]').setValue('Pa$$w0rd!12')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    await h.wrapper.find('[data-test="sign-in-deletion-leave"]').trigger('click')
    await flushPromises()

    expect(h.wrapper.find('[data-test="sign-in-heading"]').text()).toBe('Sign in')
    expect(h.wrapper.find('[data-test="sign-in-native-subtitle"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="sign-in-native-create-account"]').exists()).toBe(true)
    expect(authApi.tokenLogin).toHaveBeenCalledTimes(1)
  })
})
