import { ApiError } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import EnableTotpPage from './EnableTotpPage.vue'

/**
 * Mirror of `apps/main/src/modules/auth/pages/EnableTotpPage.spec.ts`
 * (chunks 6.6 + 7.1) with one additional case pinning admin's D7
 * intended-destination preservation: when `?redirect=<intended>` is
 * present in the route, the post-confirm navigation lands there
 * (not at `app.dashboard`). Main's identical-shape page lacks this
 * adaptation, so the case is admin-only.
 */
vi.mock('@/modules/auth/api/admin-auth.api', () => ({
  authApi: {
    me: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    enrollTotp: vi.fn(),
    verifyTotp: vi.fn(),
    disableTotp: vi.fn(),
    regenerateRecoveryCodes: vi.fn(),
  },
}))

import { authApi } from '@/modules/auth/api/admin-auth.api'

const ENROLLMENT = {
  provisional_token: 'tok',
  otpauth_url: 'otpauth://...',
  qr_code_svg: '<svg data-test="qr"/>',
  manual_entry_key: 'KEY-A1B2-C3D4',
  expires_in_seconds: 600,
}

const RECOVERY = {
  recovery_codes: ['rc-1', 'rc-2', 'rc-3'] as const,
}

const ADMIN_AFTER_CONFIRM = {
  type: 'user' as const,
  id: '01HQ',
  attributes: {
    email: 'admin@example.com',
    email_verified_at: '2026-01-01T00:00:00Z',
    name: 'Admin User',
    user_type: 'platform_admin' as const,
    preferred_language: 'en' as const,
    preferred_currency: 'EUR',
    timezone: 'Europe/Lisbon',
    theme_preference: 'system' as const,
    mfa_required: true,
    two_factor_enabled: true,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
}

describe('EnableTotpPage (admin)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('on mount, calls enrollTotp and renders the QR + manual key', async () => {
    vi.mocked(authApi.enrollTotp).mockResolvedValue({ ...ENROLLMENT })
    const h = await mountAuthPage(EnableTotpPage)
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()
    expect(authApi.enrollTotp).toHaveBeenCalled()
    expect(h.wrapper.find('[data-test="enable-totp-qr"]').html()).toContain('svg')
    expect(h.wrapper.find('[data-test="enable-totp-manual-key"]').text()).toBe('KEY-A1B2-C3D4')
  })

  it('happy path: confirm form submits provisional_token + code, transitions to recovery codes', async () => {
    vi.mocked(authApi.enrollTotp).mockResolvedValue({ ...ENROLLMENT })
    vi.mocked(authApi.verifyTotp).mockResolvedValue({ ...RECOVERY })
    vi.mocked(authApi.me).mockResolvedValue(ADMIN_AFTER_CONFIRM)
    const h = await mountAuthPage(EnableTotpPage)
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()

    await h.wrapper.find('[data-test="enable-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(authApi.verifyTotp).toHaveBeenCalledWith({
      provisional_token: 'tok',
      code: '123456',
    })
    // Assert on the child's own root selector (`recovery-codes-display`)
    // rather than a parent-supplied data-test. Vue 3 single-root
    // attribute fall-through makes the parent's `data-test` REPLACE
    // the child's `data-test` on the rendered root — see the chunk-7.1
    // post-merge hotfix on main + the matching admin docblock guard
    // in EnableTotpPage.vue.
    expect(h.wrapper.find('[data-test="recovery-codes-display"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="recovery-codes-list"]').text()).toBe('rc-1\nrc-2\nrc-3')
  })

  it('on enrollTotp error, renders the error inline and does NOT show the form', async () => {
    vi.mocked(authApi.enrollTotp).mockRejectedValue(
      new ApiError({ status: 429, code: 'auth.mfa.rate_limited', message: 'no.' }),
    )
    const h = await mountAuthPage(EnableTotpPage)
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()
    expect(h.wrapper.find('[data-test="enable-totp-error-fatal"]').text()).toContain(
      'invalid two-factor attempts',
    )
  })

  it('on verifyTotp ApiError (auth.mfa.invalid_code), renders the i18n string', async () => {
    vi.mocked(authApi.enrollTotp).mockResolvedValue({ ...ENROLLMENT })
    vi.mocked(authApi.verifyTotp).mockRejectedValue(
      new ApiError({ status: 422, code: 'auth.mfa.invalid_code', message: 'no.' }),
    )
    const h = await mountAuthPage(EnableTotpPage)
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()
    await h.wrapper.find('[data-test="enable-totp-code"] input').setValue('000000')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="enable-totp-error"]').text()).toContain(
      'two-factor code is invalid',
    )
  })

  it('on verifyTotp non-ApiError, falls back to auth.ui.errors.unknown', async () => {
    vi.mocked(authApi.enrollTotp).mockResolvedValue({ ...ENROLLMENT })
    vi.mocked(authApi.verifyTotp).mockRejectedValue(new Error('boom'))
    const h = await mountAuthPage(EnableTotpPage)
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()
    await h.wrapper.find('[data-test="enable-totp-code"] input').setValue('000000')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="enable-totp-error"]').text()).toBe(
      'Something went wrong. Please try again.',
    )
  })

  it('error region uses aria-live="polite"', async () => {
    vi.mocked(authApi.enrollTotp).mockResolvedValue({ ...ENROLLMENT })
    const h = await mountAuthPage(EnableTotpPage)
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()
    expect(h.wrapper.find('[data-test="enable-totp-error"]').attributes('aria-live')).toBe('polite')
  })

  it('on RecoveryCodesDisplay "confirmed" emit, navigates to the dashboard by default', async () => {
    vi.mocked(authApi.enrollTotp).mockResolvedValue({ ...ENROLLMENT })
    vi.mocked(authApi.verifyTotp).mockResolvedValue({ ...RECOVERY })
    const h = await mountAuthPage(EnableTotpPage)
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()

    await h.wrapper.find('[data-test="enable-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    const pushSpy = vi.spyOn(h.router, 'push')
    const recovery = h.wrapper.findComponent({ name: 'RecoveryCodesDisplay' })
    expect(recovery.exists()).toBe(true)
    recovery.vm.$emit('confirmed')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({ name: 'app.dashboard' })
  })

  it('admin D7: on confirmed emit with ?redirect=/settings, navigates to /settings (not dashboard)', async () => {
    vi.mocked(authApi.enrollTotp).mockResolvedValue({ ...ENROLLMENT })
    vi.mocked(authApi.verifyTotp).mockResolvedValue({ ...RECOVERY })
    const h = await mountAuthPage(EnableTotpPage, {
      initialRoute: { path: '/auth/2fa/enable', query: { redirect: '/settings' } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()

    await h.wrapper.find('[data-test="enable-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    const pushSpy = vi.spyOn(h.router, 'push')
    const recovery = h.wrapper.findComponent({ name: 'RecoveryCodesDisplay' })
    expect(recovery.exists()).toBe(true)
    recovery.vm.$emit('confirmed')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith('/settings')
  })

  it('admin D7: on confirmed emit with non-string redirect, falls back to dashboard', async () => {
    vi.mocked(authApi.enrollTotp).mockResolvedValue({ ...ENROLLMENT })
    vi.mocked(authApi.verifyTotp).mockResolvedValue({ ...RECOVERY })
    const h = await mountAuthPage(EnableTotpPage, {
      // Two values for the same key make Vue Router parse `redirect`
      // as a `string[]` rather than a `string`, exercising the
      // !== 'string' branch of the fallback.
      initialRoute: { path: '/auth/2fa/enable', query: { redirect: ['/a', '/b'] } },
    })
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()

    await h.wrapper.find('[data-test="enable-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    const pushSpy = vi.spyOn(h.router, 'push')
    const recovery = h.wrapper.findComponent({ name: 'RecoveryCodesDisplay' })
    recovery.vm.$emit('confirmed')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({ name: 'app.dashboard' })
  })

  it('shows the loading state while enrollTotp is in flight', async () => {
    vi.mocked(authApi.enrollTotp).mockImplementation(() => new Promise(() => undefined))
    const h = await mountAuthPage(EnableTotpPage)
    teardown = h.unmount
    await flushPromises()
    await h.wrapper.vm.$nextTick()
    expect(h.wrapper.find('[data-test="enable-totp-loading"]').exists()).toBe(true)
  })
})
