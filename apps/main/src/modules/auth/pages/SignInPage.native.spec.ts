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
})
