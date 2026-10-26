/**
 * AH-104d S5 — the verification pending page inside the creator app. The page
 * is the one the web shows; these specs pin what the native shell adds (the
 * "I've verified, sign in" button with its two states, the Security link for
 * a signed-in unverified account) and that none of it exists on the web.
 */

import { ApiError } from '@catalyst/api-client'
import { RouterLinkStub, flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import EmailVerificationPendingPage from './EmailVerificationPendingPage.vue'

const platform = vi.hoisted(() => ({ native: true }))
const tokenStore = vi.hoisted(() => ({
  read: vi.fn(async () => null),
  write: vi.fn(async () => undefined),
  clear: vi.fn(async () => undefined),
}))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'ios' : null),
}))
vi.mock('@/core/auth/tokenStore', () => ({ nativeTokenStore: () => tokenStore }))
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
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

type User = Awaited<ReturnType<typeof authApi.me>>

function makeUser(verifiedAt: string | null, features?: { account_deletion: boolean }): User {
  return {
    type: 'user' as const,
    id: '01HQ',
    attributes: {
      email: 'store@user.example',
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
      ...(features === undefined ? {} : { features }),
    },
  } as User
}

type Harness = Awaited<ReturnType<typeof mountAuthPage>>

const VERIFIED = '[data-test="email-verification-pending-verified"]'
const DELETE = '[data-test="email-verification-pending-delete"]'
const BACK = '[data-test="email-verification-pending-back"]'
const ERROR = '[data-test="email-verification-pending-error"]'

describe('EmailVerificationPendingPage — creator app (native)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    platform.native = true
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  async function mountSignedOut(query: Record<string, string> = { email: 'new@creator.example' }) {
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      initialRoute: { path: '/verify-email/pending', query },
    })
    teardown = h.unmount
    return h
  }

  async function mountSignedIn(user: User): Promise<Harness> {
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      stubs: { RouterLink: RouterLinkStub },
      initialRoute: { path: '/verify-email/pending', query: { email: user.attributes.email } },
      beforeMount: () => {
        const store = useAuthStore()
        store.user = user
        store.bootstrapStatus = 'ready'
      },
    })
    teardown = h.unmount
    return h
  }

  it('offers the button in place of the back link, and keeps the resend button', async () => {
    const h = await mountSignedOut()

    expect(h.wrapper.find(VERIFIED).text()).toBe("I've verified, sign in")
    expect(h.wrapper.find(BACK).exists()).toBe(false)
    expect(h.wrapper.find('[data-test="email-verification-pending-resend"]').exists()).toBe(true)
  })

  describe('signed out (the usual case, straight after sign-up)', () => {
    it('goes to sign-in with the address in the query, and calls no API', async () => {
      const h = await mountSignedOut()
      const pushSpy = vi.spyOn(h.router, 'push')

      await h.wrapper.find(VERIFIED).trigger('click')
      await flushPromises()

      expect(pushSpy).toHaveBeenCalledWith({
        name: 'auth.sign-in',
        query: { email: 'new@creator.example' },
      })
      expect(authApi.me).not.toHaveBeenCalled()
    })

    it('with no address to carry, goes to sign-in without a query', async () => {
      const h = await mountSignedOut({})
      const pushSpy = vi.spyOn(h.router, 'push')

      await h.wrapper.find(VERIFIED).trigger('click')
      await flushPromises()

      expect(pushSpy).toHaveBeenCalledWith({ name: 'auth.sign-in' })
    })

    it('shows no Security link, whatever the flag (nobody is signed in)', async () => {
      const h = await mountSignedOut()

      expect(h.wrapper.find(DELETE).exists()).toBe(false)
    })
  })

  describe('signed in (an unverified sign-in bounced here holding a session)', () => {
    it('now verified: re-reads the account and goes to the wizard, never to /sign-in', async () => {
      vi.mocked(authApi.me).mockResolvedValue(makeUser('2026-10-26T10:00:00Z'))
      const h = await mountSignedIn(makeUser(null))
      const pushSpy = vi.spyOn(h.router, 'push')

      await h.wrapper.find(VERIFIED).trigger('click')
      await flushPromises()

      expect(authApi.me).toHaveBeenCalledTimes(1)
      expect(pushSpy).toHaveBeenCalledTimes(1)
      expect(pushSpy).toHaveBeenCalledWith({ name: 'onboarding.welcome-back' })
    })

    it('still unverified: says so, stays on the page, and the button works again', async () => {
      vi.mocked(authApi.me).mockResolvedValue(makeUser(null))
      const h = await mountSignedIn(makeUser(null))
      const pushSpy = vi.spyOn(h.router, 'push')

      await h.wrapper.find(VERIFIED).trigger('click')
      await flushPromises()

      expect(pushSpy).not.toHaveBeenCalled()
      expect(h.wrapper.find(ERROR).text()).toBe(
        "We can't see your verification yet. Open the link in the email, then try again.",
      )
      expect(h.wrapper.find(VERIFIED).attributes('disabled')).toBeUndefined()
    })

    it('clears the "not yet" message on the next try', async () => {
      vi.mocked(authApi.me).mockResolvedValueOnce(makeUser(null))
      vi.mocked(authApi.me).mockResolvedValueOnce(makeUser('2026-10-26T10:00:00Z'))
      const h = await mountSignedIn(makeUser(null))

      await h.wrapper.find(VERIFIED).trigger('click')
      await flushPromises()
      expect(h.wrapper.find(ERROR).text()).not.toBe('')

      await h.wrapper.find(VERIFIED).trigger('click')
      await flushPromises()
      expect(h.wrapper.find(ERROR).text()).toBe('')
    })

    it('a dead session ends the sign-in: the answer is sign-in again, not an error', async () => {
      vi.mocked(authApi.me).mockRejectedValue(
        new ApiError({ status: 401, code: 'auth.unauthenticated', message: 'no', details: [] }),
      )
      const h = await mountSignedIn(makeUser(null))
      const pushSpy = vi.spyOn(h.router, 'push')

      await h.wrapper.find(VERIFIED).trigger('click')
      await flushPromises()

      expect(pushSpy).toHaveBeenCalledWith({
        name: 'auth.sign-in',
        query: { email: 'store@user.example' },
      })
      expect(h.wrapper.find(ERROR).text()).toBe('')
    })

    it('a failed re-read (offline) leaves the person on the page with the "not yet" message', async () => {
      vi.mocked(authApi.me).mockRejectedValue(new Error('offline'))
      const h = await mountSignedIn(makeUser(null))
      const pushSpy = vi.spyOn(h.router, 'push')

      await h.wrapper.find(VERIFIED).trigger('click')
      await flushPromises()

      expect(pushSpy).not.toHaveBeenCalled()
      expect(h.wrapper.find(ERROR).text()).not.toBe('')
    })
  })

  describe('the Security link (delete an account that never got verified)', () => {
    it('shows for a signed-in account when the server says deletion is available', async () => {
      const h = await mountSignedIn(makeUser(null, { account_deletion: true }))

      const link = h.wrapper.find(DELETE)
      expect(link.exists()).toBe(true)
      expect(link.text()).toBe('Delete my account')
      expect(h.wrapper.findComponent(RouterLinkStub).props('to')).toEqual({
        name: 'creator.security',
      })
    })

    it('is absent when the flag is false', async () => {
      const h = await mountSignedIn(makeUser(null, { account_deletion: false }))

      expect(h.wrapper.find(DELETE).exists()).toBe(false)
    })

    it('is absent when the payload has no features at all (an older API)', async () => {
      const h = await mountSignedIn(makeUser(null))

      expect(h.wrapper.find(DELETE).exists()).toBe(false)
    })
  })
})

describe('EmailVerificationPendingPage — web polarity of the native additions (AH-104d)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    platform.native = false
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('the web page is unchanged: back link, no button, no Security link even with the flag on', async () => {
    const h = await mountAuthPage(EmailVerificationPendingPage, {
      initialRoute: { path: '/verify-email/pending', query: { email: 'store@user.example' } },
      beforeMount: () => {
        const store = useAuthStore()
        store.user = makeUser(null, { account_deletion: true })
        store.bootstrapStatus = 'ready'
      },
    })
    teardown = h.unmount

    expect(h.wrapper.find(BACK).exists()).toBe(true)
    expect(h.wrapper.find(VERIFIED).exists()).toBe(false)
    expect(h.wrapper.find(DELETE).exists()).toBe(false)
  })
})
