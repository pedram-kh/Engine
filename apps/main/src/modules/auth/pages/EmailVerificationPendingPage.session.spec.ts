/**
 * AH-104d W11 (2026-10-28) — "I've verified, sign in" on a phone that signed in during THIS
 * app run, then verified in Safari and came back.
 *
 * The component specs next door seed `bootstrapStatus = 'ready'`, which is the state of an
 * app opened cold with a stored token. A native sign-in does not set it (`loginWithToken()`
 * only sets the user), so a session made in this run sits at `idle`, and `refreshSession()`
 * used to return without a request for anything but `ready`: the button re-read nothing, the
 * stale unverified user answered "not yet", and only a force-quit (a cold bootstrap) fixed it.
 *
 * These specs use the real store, the real guard chain and the real route table, with only
 * the HTTP layer faked, and start from the state the sign-in leaves behind.
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { runGuards } from '@/core/router'
import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import EmailVerificationPendingPage from './EmailVerificationPendingPage.vue'

const tokenStore = vi.hoisted(() => ({
  read: vi.fn(async () => 'token-1'),
  write: vi.fn(async () => undefined),
  clear: vi.fn(async () => undefined),
}))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => true,
  nativePlatform: () => 'ios',
}))
vi.mock('@/core/auth/tokenStore', () => ({ nativeTokenStore: () => tokenStore }))
vi.mock('@/core/auth/installMarker', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/core/auth/installMarker')>()),
  ensureInstallReconciled: async () => 'ok',
}))
vi.mock('@/modules/auth/api/auth.api', () => ({
  authApi: {
    me: vi.fn(),
    login: vi.fn(),
    tokenLogin: vi.fn(),
    logout: vi.fn(),
    resendVerification: vi.fn(),
  },
}))
vi.mock('@/modules/onboarding/api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn() },
}))

import { authApi } from '@/modules/auth/api/auth.api'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'
import { onboardingApi } from '@/modules/onboarding/api/onboarding.api'

type User = Awaited<ReturnType<typeof authApi.me>>

function makeUser(verifiedAt: string | null): User {
  return {
    type: 'user' as const,
    id: '01HQ',
    attributes: {
      email: 'walk@creator.example',
      email_verified_at: verifiedAt,
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
  } as User
}

function creatorEnvelope(status: 'incomplete' | 'submitted') {
  return {
    data: {
      id: 'c1',
      type: 'creator',
      attributes: { application_status: status, profile_completeness_score: 0 },
      wizard: { next_step: 'profile', steps: [], flags: {}, is_submitted: false },
    },
  } as unknown as Awaited<ReturnType<typeof onboardingApi.bootstrap>>
}

const VERIFIED = '[data-test="email-verification-pending-verified"]'
const ERROR = '[data-test="email-verification-pending-error"]'

describe('EmailVerificationPendingPage — signed in during this app run (W11)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  /** The state `loginWithToken()` leaves behind: a user, a token, and no bootstrap. */
  async function mountAfterSignIn() {
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      initialRoute: { path: '/verify-email/pending', query: { email: 'walk@creator.example' } },
      beforeMount: ({ router }) => {
        const store = useAuthStore()
        store.user = makeUser(null)
        expect(store.bootstrapStatus).toBe('idle')
        // The production router's dispatcher, on the harness's route table.
        router.beforeEach(async (to, from) => (await runGuards(to, from, useAuthStore())) ?? true)
      },
    })
    teardown = h.unmount
    return h
  }

  it('verified in Safari meanwhile: re-reads the account and lands on the wizard entry', async () => {
    vi.mocked(authApi.me).mockResolvedValue(makeUser('2026-10-28T03:00:00Z'))
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(creatorEnvelope('incomplete'))
    const h = await mountAfterSignIn()

    await h.wrapper.find(VERIFIED).trigger('click')
    await flushPromises()

    expect(authApi.me).toHaveBeenCalled()
    await vi.waitFor(() => expect(h.router.currentRoute.value.name).toBe('onboarding.welcome-back'))
    expect(h.wrapper.find(ERROR).text()).toBe('')
  })

  it('the guard chain failing after the push is shown, never silent', async () => {
    vi.mocked(authApi.me).mockResolvedValue(makeUser('2026-10-28T03:00:00Z'))
    vi.mocked(onboardingApi.bootstrap).mockRejectedValue(
      new ApiError({ status: 500, code: 'server.error', message: 'boom', details: [] }),
    )
    const h = await mountAfterSignIn()

    await h.wrapper.find(VERIFIED).trigger('click')
    await flushPromises()

    await vi.waitFor(() => expect(h.wrapper.find(ERROR).text()).not.toBe(''))
    expect(h.router.currentRoute.value.name).toBe('auth.verify-email.pending')
    expect(h.wrapper.find(VERIFIED).attributes('disabled')).toBeUndefined()
  })

  it('still unverified: says so, and the next tap re-reads again (not stuck on the first answer)', async () => {
    vi.mocked(authApi.me).mockResolvedValueOnce(makeUser(null))
    vi.mocked(authApi.me).mockResolvedValueOnce(makeUser('2026-10-28T03:00:00Z'))
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(creatorEnvelope('incomplete'))
    const h = await mountAfterSignIn()

    await h.wrapper.find(VERIFIED).trigger('click')
    await flushPromises()
    expect(h.wrapper.find(ERROR).text()).not.toBe('')
    expect(h.router.currentRoute.value.name).toBe('auth.verify-email.pending')

    await h.wrapper.find(VERIFIED).trigger('click')
    await flushPromises()
    // Tap 1: the re-read. Tap 2: the re-read, then the wizard guard's own `/me`.
    await vi.waitFor(() => expect(h.router.currentRoute.value.name).toBe('onboarding.welcome-back'))
    expect(authApi.me).toHaveBeenCalledTimes(3)
  })

  it('stays busy while the wizard guard chain is still loading, then settles', async () => {
    vi.mocked(authApi.me).mockResolvedValue(makeUser('2026-10-28T03:00:00Z'))
    let release: (v: ReturnType<typeof creatorEnvelope>) => void = () => undefined
    vi.mocked(onboardingApi.bootstrap).mockReturnValue(
      new Promise((resolve) => {
        release = resolve as typeof release
      }),
    )
    const h = await mountAfterSignIn()

    await h.wrapper.find(VERIFIED).trigger('click')
    await vi.waitFor(() => expect(onboardingApi.bootstrap).toHaveBeenCalled())
    expect(h.wrapper.find(VERIFIED).attributes('disabled')).toBeDefined()

    release(creatorEnvelope('incomplete'))
    await vi.waitFor(() => expect(h.router.currentRoute.value.name).toBe('onboarding.welcome-back'))
  })
})
