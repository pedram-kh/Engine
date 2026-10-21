/**
 * AH-104b-del B4 — the Security page and its delete-account dialog, web and native.
 *
 * The dialog is the whole point: the button stays disabled until the tick, the password,
 * (the 2FA code when enrolled) and the typed word are all present; the server's refusals map
 * to copy (wrong password, 429 countdown, blocked + reasons); and a success clears the store
 * and goes to sign-in with the date, with no logout call.
 */

import { ApiError, type AuthApi, type User } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'
import enAuth from '@/core/i18n/locales/en/auth.json'
import enCreator from '@/core/i18n/locales/en/creator.json'
import { SUPPORT_EMAIL } from '@/core/config/support'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
  nativePlatform: () => (platform.native ? 'android' : null),
}))
vi.mock('@/core/auth/tokenStore', () => ({
  nativeTokenStore: () => ({ clear: vi.fn(async () => undefined) }),
}))
vi.mock('@/core/platform/push/pushLogoutCleanup', () => ({
  pushLogoutCleanup: vi.fn(async () => undefined),
  pushForgetLocal: vi.fn(async () => undefined),
}))
vi.mock('@/modules/auth/api/auth.api', () => ({
  authApi: {
    me: vi.fn(),
    updateMe: vi.fn(),
    login: vi.fn(),
    tokenLogin: vi.fn(),
    requestAccountDeletion: vi.fn(),
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
  } satisfies AuthApi,
}))

import { authApi } from '@/modules/auth/api/auth.api'
import { useAuthStore } from '@/modules/auth/stores/useAuthStore'

import SecurityPage from './SecurityPage.vue'

const mocked = authApi as unknown as { [K in keyof AuthApi]: ReturnType<typeof vi.fn> }

const SCHEDULED = '2026-11-04T10:00:00+00:00'

/** Slot-through stand-ins: the real overlays teleport out of the wrapper. */
const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}
const VBottomSheetStub = {
  name: 'VBottomSheet',
  props: ['modelValue'],
  template: '<div class="vbottomsheet-stub"><slot /></div>',
}

function makeUser(twoFactor = false): User {
  return {
    id: '01HQVKWP0M4XKMJWR5J2PXKKKQ',
    type: 'user',
    attributes: {
      email: 'creator@example.test',
      email_verified_at: '2026-04-30T12:00:00+00:00',
      name: 'Creator Account',
      last_name: null,
      user_type: 'creator',
      preferred_language: null,
      preferred_currency: 'USD',
      timezone: 'Europe/Lisbon',
      theme_preference: 'system',
      mfa_required: false,
      mfa_exempt: false,
      two_factor_enabled: twoFactor,
      last_login_at: null,
      created_at: '2026-04-15T09:00:00+00:00',
      features: { account_deletion: true },
    },
  }
}

async function mountPage(options: { twoFactor?: boolean; native?: boolean } = {}) {
  platform.native = options.native ?? false
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().setUser(makeUser(options.twoFactor ?? false))

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/creator/security', name: 'creator.security', component: { template: '<div />' } },
      { path: '/sign-in', name: 'auth.sign-in', component: { template: '<div />' } },
    ],
  })
  await router.push('/creator/security')
  await router.isReady()

  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en: { ...enApp, ...enAuth, ...enCreator } } as never,
  })

  const wrapper = mount(SecurityPage, {
    global: {
      plugins: [
        pinia,
        router,
        i18n,
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
      ],
      stubs: { VDialog: VDialogStub, VBottomSheet: VBottomSheetStub },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return { wrapper, router }
}

type Wrapper = Awaited<ReturnType<typeof mountPage>>['wrapper']

const submit = (w: Wrapper) => w.find('[data-test="delete-account-submit"]')
const field = (w: Wrapper, name: string) => w.find(`[data-test="delete-account-${name}"] input`)

async function fill(
  w: Wrapper,
  values: { ack?: boolean; password?: string; code?: string; word?: string },
) {
  if (values.ack !== undefined) {
    await w.find('[data-test="delete-account-ack"] input').setValue(values.ack)
  }
  if (values.password !== undefined) {
    await field(w, 'password').setValue(values.password)
  }
  if (values.code !== undefined) {
    await field(w, 'code').setValue(values.code)
  }
  if (values.word !== undefined) {
    await field(w, 'word').setValue(values.word)
  }
  await flushPromises()
}

function apiError(status: number, code: string, meta?: Record<string, unknown>): ApiError {
  return new ApiError({
    status,
    code,
    message: code,
    details: [{ code, status: String(status), ...(meta ? { meta } : {}) }],
  })
}

beforeEach(() => {
  for (const fn of Object.values(mocked)) {
    fn.mockReset()
  }
  mocked.requestAccountDeletion.mockResolvedValue({
    requestedAt: '2026-10-21T10:00:00+00:00',
    scheduledFor: SCHEDULED,
  })
})

afterEach(() => {
  vi.useRealTimers()
  document.body.innerHTML = ''
})

describe.each([
  ['web', false],
  ['native', true],
])('Security page (%s)', (_label, native) => {
  it('explains what is deleted, what stays, the 14 days, and offers the button', async () => {
    const { wrapper } = await mountPage({ native })

    expect(wrapper.find('[data-test="security-title"]').text()).toBe('Security')
    expect(wrapper.find('[data-test="security-delete-erased"]').text()).toContain('email')
    expect(wrapper.find('[data-test="security-delete-kept"]').text()).toContain('Messages')
    expect(wrapper.find('[data-test="security-delete-grace"]').text()).toContain('14 days')
    expect(wrapper.find('[data-test="security-delete-open"]').text()).toBe('Delete my account')
    // The right container for the platform.
    expect(wrapper.find(native ? '.vbottomsheet-stub' : '.vdialog-stub').exists()).toBe(true)
    expect(wrapper.find(native ? '.vdialog-stub' : '.vbottomsheet-stub').exists()).toBe(false)
  })

  it('keeps the button disabled until the tick, the password and the typed word are all there', async () => {
    const { wrapper } = await mountPage({ native })

    expect(submit(wrapper).attributes('disabled')).toBeDefined()

    await fill(wrapper, { ack: true })
    expect(submit(wrapper).attributes('disabled')).toBeDefined()

    await fill(wrapper, { password: 'Pa$$w0rd!12' })
    expect(submit(wrapper).attributes('disabled')).toBeDefined()

    await fill(wrapper, { word: 'delet' })
    expect(submit(wrapper).attributes('disabled')).toBeDefined()

    await fill(wrapper, { word: 'delete' }) // lower case: the keyboard's doing
    expect(submit(wrapper).attributes('disabled')).toBeUndefined()

    await fill(wrapper, { password: '' })
    expect(submit(wrapper).attributes('disabled')).toBeDefined()
  })

  it('asks for the 2FA code ONLY when two-factor is enrolled', async () => {
    const plain = await mountPage({ native, twoFactor: false })
    expect(plain.wrapper.find('[data-test="delete-account-code"]').exists()).toBe(false)

    const enrolled = await mountPage({ native, twoFactor: true })
    expect(enrolled.wrapper.find('[data-test="delete-account-code"]').exists()).toBe(true)

    await fill(enrolled.wrapper, { ack: true, password: 'Pa$$w0rd!12', word: 'DELETE' })
    expect(submit(enrolled.wrapper).attributes('disabled')).toBeDefined() // no code yet
    await fill(enrolled.wrapper, { code: '123456' })
    expect(submit(enrolled.wrapper).attributes('disabled')).toBeUndefined()
  })

  it('on success: sends the body, signs the user out locally, and goes to sign-in with the date', async () => {
    const { wrapper, router } = await mountPage({ native, twoFactor: true })
    await fill(wrapper, { ack: true, password: 'Pa$$w0rd!12', code: ' 123456 ', word: 'Delete' })

    await submit(wrapper).trigger('click')
    await flushPromises()

    expect(mocked.requestAccountDeletion).toHaveBeenCalledWith({
      password: 'Pa$$w0rd!12',
      mfa_code: '123456',
      acknowledged: true,
    })
    // The word is a client-side gate and is never sent.
    expect(JSON.stringify(mocked.requestAccountDeletion.mock.calls[0])).not.toContain('Delete"')
    expect(mocked.logout).not.toHaveBeenCalled()
    expect(useAuthStore().user).toBeNull()
    expect(router.currentRoute.value.name).toBe('auth.sign-in')
    expect(router.currentRoute.value.query).toEqual({
      reason: 'deletion_scheduled',
      date: SCHEDULED,
    })
  })

  it('omits mfa_code when two-factor is not enrolled', async () => {
    const { wrapper } = await mountPage({ native })
    await fill(wrapper, { ack: true, password: 'Pa$$w0rd!12', word: 'DELETE' })
    await submit(wrapper).trigger('click')
    await flushPromises()

    expect(mocked.requestAccountDeletion).toHaveBeenCalledWith({
      password: 'Pa$$w0rd!12',
      acknowledged: true,
    })
  })

  it('wrong password (403): shows the sign-in wording, stays signed in, stays on the page', async () => {
    mocked.requestAccountDeletion.mockRejectedValue(apiError(403, 'auth.invalid_credentials'))
    const { wrapper, router } = await mountPage({ native })
    await fill(wrapper, { ack: true, password: 'nope', word: 'DELETE' })

    await submit(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="delete-account-error"]').text()).toBe(
      enAuth.auth.login.invalid_credentials,
    )
    expect(useAuthStore().user).not.toBeNull()
    expect(router.currentRoute.value.name).toBe('creator.security')
  })

  it('blocked (422): names each reason and the support address', async () => {
    mocked.requestAccountDeletion.mockRejectedValue(
      apiError(422, 'account_deletion.blocked', { reasons: ['payment', 'someFutureReason'] }),
    )
    const { wrapper } = await mountPage({ native })
    await fill(wrapper, { ack: true, password: 'Pa$$w0rd!12', word: 'DELETE' })

    await submit(wrapper).trigger('click')
    await flushPromises()

    const text = wrapper.find('[data-test="delete-account-error"]').text()
    expect(text).toContain(enCreator.account_deletion.blocked)
    expect(wrapper.find('[data-test="delete-account-blockers"]').text()).toContain(
      enCreator.account_deletion.blockers.payment,
    )
    // An unknown reason falls back to the generic line, never a raw code.
    expect(wrapper.find('[data-test="delete-account-blockers"]').text()).toContain(
      enCreator.account_deletion.blockers.other,
    )
    expect(text).not.toContain('someFutureReason')
    expect(wrapper.find('[data-test="delete-account-contact"]').text()).toContain(SUPPORT_EMAIL)
    expect(useAuthStore().user).not.toBeNull()
  })

  it('429: counts down and keeps the button disabled until the wait is over', async () => {
    vi.useFakeTimers()
    mocked.requestAccountDeletion.mockRejectedValue(
      apiError(429, 'rate_limit.exceeded', { seconds: 3 }),
    )
    const { wrapper } = await mountPage({ native })
    await fill(wrapper, { ack: true, password: 'Pa$$w0rd!12', word: 'DELETE' })

    await submit(wrapper).trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="delete-account-error"]').text()).toContain('3 seconds')
    expect(submit(wrapper).attributes('disabled')).toBeDefined()

    await vi.advanceTimersByTimeAsync(1000)
    expect(wrapper.find('[data-test="delete-account-error"]').text()).toContain('2 seconds')

    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
    expect(wrapper.find('[data-test="delete-account-error"]').text()).toBe('')
    expect(submit(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('404 (the flag went off while the page was open): says it is not available', async () => {
    mocked.requestAccountDeletion.mockRejectedValue(apiError(404, 'not_found'))
    const { wrapper } = await mountPage({ native })
    await fill(wrapper, { ack: true, password: 'Pa$$w0rd!12', word: 'DELETE' })

    await submit(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="delete-account-error"]').text()).toBe(
      enCreator.account_deletion.not_available,
    )
  })

  it('a network failure shows the generic network line', async () => {
    mocked.requestAccountDeletion.mockRejectedValue(
      new ApiError({ status: 0, code: 'network.error', message: 'offline' }),
    )
    const { wrapper } = await mountPage({ native })
    await fill(wrapper, { ack: true, password: 'Pa$$w0rd!12', word: 'DELETE' })

    await submit(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="delete-account-error"]').text()).toBe(
      enAuth.auth.ui.errors.network,
    )
  })
})
