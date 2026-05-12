import { ApiError } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import DisableTotpPage from './DisableTotpPage.vue'

/**
 * Mirror of `apps/main/src/modules/auth/pages/DisableTotpPage.spec.ts`
 * (chunk 6.6) with admin's `platform_admin` user fixture. The page
 * itself is structurally identical to main's — gating against the
 * mandatory-MFA model lives in the route table, not in the component.
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

const ADMIN_AFTER_DISABLE = {
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
    two_factor_enabled: false,
    last_login_at: null,
    created_at: '2026-01-01T00:00:00Z',
  },
}

describe('DisableTotpPage (admin)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the heading', async () => {
    const h = await mountAuthPage(DisableTotpPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="disable-totp-heading"]').text()).toBe(
      'Disable two-factor authentication',
    )
  })

  it('happy path: calls disableTotp with password + mfa_code, navigates to settings', async () => {
    vi.mocked(authApi.disableTotp).mockResolvedValue(undefined)
    vi.mocked(authApi.me).mockResolvedValue(ADMIN_AFTER_DISABLE)
    const h = await mountAuthPage(DisableTotpPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="disable-totp-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper.find('[data-test="disable-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(authApi.disableTotp).toHaveBeenCalledWith({
      password: 'Pa$$w0rd!12',
      mfa_code: '123456',
    })
    expect(pushSpy).toHaveBeenCalledWith({ name: 'app.settings' })
  })

  it('on auth.mfa.invalid_code, renders the i18n string', async () => {
    vi.mocked(authApi.disableTotp).mockRejectedValue(
      new ApiError({ status: 422, code: 'auth.mfa.invalid_code', message: 'no.' }),
    )
    const h = await mountAuthPage(DisableTotpPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="disable-totp-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper.find('[data-test="disable-totp-code"] input').setValue('000000')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="disable-totp-error"]').text()).toContain(
      'two-factor code is invalid',
    )
  })

  it('on auth.invalid_credentials, renders the i18n string', async () => {
    vi.mocked(authApi.disableTotp).mockRejectedValue(
      new ApiError({ status: 401, code: 'auth.invalid_credentials', message: 'no.' }),
    )
    const h = await mountAuthPage(DisableTotpPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="disable-totp-password"] input').setValue('wrong')
    await h.wrapper.find('[data-test="disable-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="disable-totp-error"]').text()).toContain(
      'Invalid email or password',
    )
  })

  it('on a non-ApiError, falls back to auth.ui.errors.unknown', async () => {
    vi.mocked(authApi.disableTotp).mockRejectedValue(new Error('boom'))
    const h = await mountAuthPage(DisableTotpPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="disable-totp-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper.find('[data-test="disable-totp-code"] input').setValue('000000')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="disable-totp-error"]').text()).toBe(
      'Something went wrong. Please try again.',
    )
  })

  it('error region uses aria-live="polite"', async () => {
    const h = await mountAuthPage(DisableTotpPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="disable-totp-error"]').attributes('aria-live')).toBe(
      'polite',
    )
  })

  it('button shows the submitting label while in flight', async () => {
    let resolve: () => void = () => undefined
    vi.mocked(authApi.disableTotp).mockImplementation(
      () =>
        new Promise<void>((r) => {
          resolve = r
        }),
    )
    const h = await mountAuthPage(DisableTotpPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="disable-totp-password"] input').setValue('Pa$$w0rd!12')
    await h.wrapper.find('[data-test="disable-totp-code"] input').setValue('123456')
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="disable-totp-submit"]').text()).toContain('Submitting')
    resolve()
    await flushPromises()
  })

  it('renders a cancel link back to settings', async () => {
    const h = await mountAuthPage(DisableTotpPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="disable-totp-back"]').exists()).toBe(true)
  })
})
