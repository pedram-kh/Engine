import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import AuthBootstrapErrorPage from './AuthBootstrapErrorPage.vue'

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

describe('AuthBootstrapErrorPage', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the heading and description', async () => {
    const h = await mountAuthPage(AuthBootstrapErrorPage)
    teardown = h.unmount
    expect(h.wrapper.find('[data-test="auth-bootstrap-error-heading"]').text()).toBe(
      'Something went wrong',
    )
    expect(h.wrapper.find('[data-test="auth-bootstrap-error-description"]').text()).toContain(
      'could not load your account',
    )
  })

  it('try-again: re-fires bootstrap and navigates to ?attempted on success', async () => {
    vi.mocked(authApi.me).mockResolvedValue(USER)
    const h = await mountAuthPage(AuthBootstrapErrorPage, {
      initialRoute: { path: '/error/auth-bootstrap', query: { attempted: '/dashboard' } },
    })
    teardown = h.unmount
    await flushPromises()
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="auth-bootstrap-error-retry"]').trigger('click')
    await flushPromises()
    expect(authApi.me).toHaveBeenCalled()
    expect(pushSpy).toHaveBeenCalledWith('/dashboard')
  })

  it('try-again with no ?attempted query: navigates to /', async () => {
    vi.mocked(authApi.me).mockResolvedValue(USER)
    const h = await mountAuthPage(AuthBootstrapErrorPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="auth-bootstrap-error-retry"]').trigger('click')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith('/')
  })

  it('try-again that bootstrap-errors does NOT navigate', async () => {
    vi.mocked(authApi.me).mockRejectedValue(new Error('still broken'))
    const h = await mountAuthPage(AuthBootstrapErrorPage)
    teardown = h.unmount
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="auth-bootstrap-error-retry"]').trigger('click')
    await flushPromises()
    expect(pushSpy).not.toHaveBeenCalled()
  })

  it('button shows the loading label while retrying', async () => {
    let resolveMe: (u: typeof USER) => void = () => undefined
    vi.mocked(authApi.me).mockImplementation(
      () =>
        new Promise<typeof USER>((resolve) => {
          resolveMe = resolve
        }),
    )
    const h = await mountAuthPage(AuthBootstrapErrorPage)
    teardown = h.unmount
    await h.wrapper.find('[data-test="auth-bootstrap-error-retry"]').trigger('click')
    await flushPromises()
    expect(h.wrapper.find('[data-test="auth-bootstrap-error-retry"]').text()).toContain('Loading')
    resolveMe(USER)
    await flushPromises()
  })
})
