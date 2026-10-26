/**
 * AH-104d S4 — SignUpPage as the creator app's sign-up sheet. Same component,
 * same form: these specs pin what the native shell changes (the sheet look —
 * mark, subtitle, label above, icon, large button, banner error) and that the
 * web polarity of every branch is unchanged. The payload and the navigation
 * are the web's, byte for byte.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import SignUpPage from './SignUpPage.vue'

const platform = vi.hoisted(() => ({ native: true }))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'ios' : null),
}))
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
    last_name: 'B',
    user_type: 'creator' as const,
    preferred_language: 'en' as const,
    preferred_currency: null,
    timezone: null,
    theme_preference: 'system' as const,
    mfa_required: false,
    mfa_exempt: false,
    two_factor_enabled: false,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
}

const FIELD_TESTS = [
  'sign-up-name',
  'sign-up-last-name',
  'sign-up-email',
  'sign-up-password',
  'sign-up-password-confirmation',
] as const

type Harness = Awaited<ReturnType<typeof mountAuthPage>>

async function fillAndSubmit(h: Harness): Promise<void> {
  await h.wrapper.find('[data-test="sign-up-name"] input').setValue('Alice')
  await h.wrapper.find('[data-test="sign-up-last-name"] input').setValue('Smith')
  await h.wrapper.find('[data-test="sign-up-email"] input').setValue('a@b.c')
  await h.wrapper.find('[data-test="sign-up-password"] input').setValue('Pa$$w0rd!12')
  await h.wrapper.find('[data-test="sign-up-password-confirmation"] input').setValue('Pa$$w0rd!12')
  await h.wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('SignUpPage — creator app (native sheet)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    platform.native = true
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('draws the sheet: mark, title, subtitle, and the root class the CSS hangs on', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="sign-up-page"]').classes()).toContain('sign-up-native')
    expect(h.wrapper.find('[data-test="sign-up-native-mark"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="sign-up-heading"]').classes()).toContain(
      'sign-up-native__title',
    )
    expect(h.wrapper.find('[data-test="sign-up-native-subtitle"]').text()).toBe(
      'Create an account to find campaigns and work with brands.',
    )
  })

  it('puts a label above each of the five fields, linked by for/id, with an icon', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    const labels = h.wrapper.findAll('label.sign-up-native__label')
    expect(labels.map((l) => l.text())).toEqual([
      'First name',
      'Last name',
      'Email',
      'Password',
      'Confirm password',
    ])
    for (const test of FIELD_TESTS) {
      const field = h.wrapper.find(`[data-test="${test}"]`)
      expect(field.exists()).toBe(true)
      expect(field.find('.v-field__prepend-inner .v-icon').exists()).toBe(true)
      // no floating label inside the field — the label is the one above it
      expect(field.find('.v-label.v-field-label').exists()).toBe(false)
    }
    expect(labels.map((l) => l.attributes('for'))).toEqual([
      'sign-up-name',
      'sign-up-last-name',
      'sign-up-email',
      'sign-up-password',
      'sign-up-password-confirmation',
    ])
  })

  it('keeps every autocomplete hint and input type, so the keychain and mail keyboards still work', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    const attr = (test: string, name: string) =>
      h.wrapper.find(`[data-test="${test}"] input`).attributes(name)
    expect(attr('sign-up-name', 'autocomplete')).toBe('given-name')
    expect(attr('sign-up-last-name', 'autocomplete')).toBe('family-name')
    expect(attr('sign-up-email', 'autocomplete')).toBe('email')
    expect(attr('sign-up-email', 'type')).toBe('email')
    expect(attr('sign-up-password', 'autocomplete')).toBe('new-password')
    expect(attr('sign-up-password', 'type')).toBe('password')
    expect(attr('sign-up-password-confirmation', 'autocomplete')).toBe('new-password')
    expect(attr('sign-up-password-confirmation', 'type')).toBe('password')
  })

  it('submits the same payload and goes to the in-app pending page with the email', async () => {
    vi.mocked(authApi.signUp).mockResolvedValue(USER)
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')

    await fillAndSubmit(h)

    expect(authApi.signUp).toHaveBeenCalledTimes(1)
    expect(vi.mocked(authApi.signUp).mock.calls[0]?.[0]).toMatchObject({
      name: 'Alice',
      last_name: 'Smith',
      email: 'a@b.c',
      password: 'Pa$$w0rd!12',
      password_confirmation: 'Pa$$w0rd!12',
      preferred_language: 'en',
    })
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'auth.verify-email.pending',
      query: { email: 'a@b.c' },
    })
  })

  it('submit is the large primary button', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    const btn = h.wrapper.find('[data-test="sign-up-submit"]')
    expect(btn.classes()).toContain('v-btn--size-large')
  })

  it('shows a 422 as per-field messages and leaves the banner empty (not the generic error)', async () => {
    vi.mocked(authApi.signUp).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'bad',
        details: [
          {
            code: 'validation.failed',
            detail: 'The email has already been taken.',
            source: { pointer: '/data/attributes/email' },
          },
        ],
      }),
    )
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    await fillAndSubmit(h)

    expect(h.wrapper.find('[data-test="sign-up-email"]').text()).toContain(
      'The email has already been taken.',
    )
    const banner = h.wrapper.find('[data-test="sign-up-error"]')
    expect(banner.classes()).not.toContain('sign-up-native__error--on')
    expect(banner.text()).toBe('')
  })

  it('a coded error (429) draws the banner: on-class, alert icon, translated text', async () => {
    vi.mocked(authApi.signUp).mockRejectedValue(
      new ApiError({
        status: 429,
        code: 'rate_limit.exceeded',
        message: 'Too many',
        details: [{ code: 'rate_limit.exceeded', meta: { seconds: 120 } }],
      }),
    )
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    await fillAndSubmit(h)

    const banner = h.wrapper.find('[data-test="sign-up-error"]')
    expect(banner.classes()).toContain('sign-up-native__error')
    expect(banner.classes()).toContain('sign-up-native__error--on')
    expect(banner.find('.mdi-alert-circle-outline').exists()).toBe(true)
    expect(banner.text().length).toBeGreaterThan(0)
    expect(banner.text()).not.toContain('rate_limit.exceeded')
  })

  it('the invitation flow still goes to sign-in with ?invited=1, and keeps its banner', async () => {
    vi.mocked(authApi.signUp).mockResolvedValue(USER)
    const h = await mountAuthPage(SignUpPage, {
      initialRoute: { path: '/sign-up', query: { token: 'magic-token' } },
    })
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')

    expect(h.wrapper.find('[data-test="sign-up-invitation-banner"]').exists()).toBe(true)
    await fillAndSubmit(h)

    expect(pushSpy).toHaveBeenCalledWith({ name: 'auth.sign-in', query: { invited: '1' } })
  })

  it('draws the consent line (privacy only) and the back link in their sheet classes', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    const consent = h.wrapper.find('[data-test="sign-up-consent"]')
    expect(consent.classes()).toContain('sign-up-native__consent')
    expect(consent.find('[data-test="sign-up-consent-privacy"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="sign-up-back-link"]').element.parentElement?.className).toBe(
      'sign-up-native__back',
    )
  })
})

describe('SignUpPage — web polarity of the native branches (AH-104d)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    platform.native = false
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('keeps the web form: floating labels, no mark, no subtitle, no sheet classes, no icons', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="sign-up-page"]').classes()).not.toContain('sign-up-native')
    expect(h.wrapper.find('[data-test="sign-up-native-mark"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-up-native-subtitle"]').exists()).toBe(false)
    expect(h.wrapper.find('label.sign-up-native__label').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="sign-up-heading"]').classes()).toContain('text-h5')
    for (const test of FIELD_TESTS) {
      const field = h.wrapper.find(`[data-test="${test}"]`)
      expect(field.find('.v-field__prepend-inner .v-icon').exists()).toBe(false)
      expect(field.find('.v-label.v-field-label').exists()).toBe(true)
    }
    expect(h.wrapper.find('[data-test="sign-up-submit"]').classes()).not.toContain(
      'v-btn--size-large',
    )
    const consent = h.wrapper.find('[data-test="sign-up-consent"]')
    expect(consent.classes()).not.toContain('sign-up-native__consent')
    expect(consent.classes()).toContain('text-caption')
  })

  it('draws a coded error as the plain red text, with no icon', async () => {
    vi.mocked(authApi.signUp).mockRejectedValue(
      new ApiError({
        status: 429,
        code: 'rate_limit.exceeded',
        message: 'Too many',
        details: [{ code: 'rate_limit.exceeded', meta: { seconds: 120 } }],
      }),
    )
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    await fillAndSubmit(h)

    const banner = h.wrapper.find('[data-test="sign-up-error"]')
    expect(banner.classes()).toContain('text-error')
    expect(banner.classes()).not.toContain('sign-up-native__error')
    expect(banner.find('.mdi-alert-circle-outline').exists()).toBe(false)
    expect(banner.text().length).toBeGreaterThan(0)
  })

  it('keeps the back link in the plain wrapper', async () => {
    const h = await mountAuthPage(SignUpPage)
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="sign-up-back-link"]').element.parentElement?.className).toBe(
      'mt-4 text-body-2',
    )
  })
})
