/**
 * AH-095 — the stranger path (S6): Vitest coverage for the new
 * 'no-account' state on the accept-invitation page.
 *
 * Existing states (loading/expired/already-accepted/pending/email-mismatch/
 * already-member/not-found/error) predate this chunk and are intentionally
 * NOT re-covered here — this file's job is (1) the new branch itself, (2)
 * the byte-identical-existing-path pin (D1's core promise), and (3) the
 * review priorities named at kickoff: email immutability, the collision
 * race, and per-field error rendering on the new form.
 */

import { ApiError } from '@catalyst/api-client'
import type { InvitationPreview, UserEnvelope } from '@catalyst/api-client'
import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import AcceptInvitationPage from './AcceptInvitationPage.vue'

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

vi.mock('../api/invitations.api', () => ({
  invitationsApi: {
    create: vi.fn(),
    accept: vi.fn(),
    preview: vi.fn(),
    createAccount: vi.fn(),
    list: vi.fn(),
  },
}))

import { authApi } from '@/modules/auth/api/auth.api'
import { invitationsApi } from '../api/invitations.api'

const TOKEN = 'a'.repeat(64)
const AGENCY = '01HZAGENCY0000000000000000'
const ROUTE = { path: '/accept-invitation', query: { token: TOKEN, agency: AGENCY } }

function makePreview(overrides: Partial<InvitationPreview> = {}): InvitationPreview {
  return {
    agency_name: 'Northwind Talent',
    role: 'agency_manager',
    is_expired: false,
    is_accepted: false,
    expires_at: '2026-12-01T00:00:00Z',
    invited_email: 'stranger@example.com',
    account_exists: false,
    ...overrides,
  }
}

const AUTHENTICATED_USER = {
  type: 'user' as const,
  id: '01HQEXISTING',
  attributes: {
    email: 'existing@example.com',
    email_verified_at: '2026-01-01T00:00:00Z',
    name: 'Existing',
    last_name: 'User',
    user_type: 'agency_user' as const,
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

const NEW_USER: UserEnvelope = {
  data: {
    type: 'user',
    id: '01HQNEWSTRANGER',
    attributes: {
      email: 'stranger@example.com',
      email_verified_at: '2026-05-01T00:00:00Z',
      name: 'Stella',
      last_name: 'Stranger',
      user_type: 'agency_user',
      preferred_language: 'en',
      preferred_currency: null,
      timezone: null,
      theme_preference: 'system',
      mfa_required: false,
      mfa_exempt: false,
      two_factor_enabled: false,
      last_login_at: null,
      created_at: '2026-05-01T00:00:00Z',
    },
  },
}

async function unauthenticated(): Promise<void> {
  vi.mocked(authApi.me).mockRejectedValue(
    new ApiError({ status: 401, code: 'auth.unauthenticated', message: 'no session' }),
  )
}

async function fillCreateAccountForm(
  wrapper: Awaited<ReturnType<typeof mountAuthPage>>['wrapper'],
  values: { firstName?: string; lastName?: string; password?: string; confirmation?: string } = {},
): Promise<void> {
  await wrapper
    .find('[data-test="create-account-first-name"] input')
    .setValue(values.firstName ?? 'Stella')
  await wrapper
    .find('[data-test="create-account-last-name"] input')
    .setValue(values.lastName ?? 'Stranger')
  await wrapper
    .find('[data-test="create-account-password"] input')
    .setValue(values.password ?? 'Correct-Horse-Battery-Staple-42')
  await wrapper
    .find('[data-test="create-account-password-confirmation"] input')
    .setValue(values.confirmation ?? 'Correct-Horse-Battery-Staple-42')
}

describe('AcceptInvitationPage — the stranger (no-account) path (AH-095 S6)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  // -------------------------------------------------------------------
  // D1 branch — account_exists drives the fork
  // -------------------------------------------------------------------

  it('renders the set-password form when unauthenticated and no account exists for the invited email', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-test="accept-invitation-create-account"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="accept-invitation-unauthenticated"]').exists()).toBe(false)
  })

  it('renders the sign-in CTA (not the form) when unauthenticated and an account already exists — byte-identical to pre-AH-095', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({
      data: makePreview({ account_exists: true }),
    })
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-test="accept-invitation-unauthenticated"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="accept-invitation-create-account"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="accept-sign-in-btn"]').exists()).toBe(true)
  })

  it('an authenticated user ALWAYS lands on the pending accept path, even when account_exists is false', async () => {
    vi.mocked(authApi.me).mockResolvedValue(AUTHENTICATED_USER)
    vi.mocked(invitationsApi.preview).mockResolvedValue({
      data: makePreview({ account_exists: false }),
    })
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-test="accept-invitation-pending"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="accept-invitation-create-account"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="accept-invitation-btn"]').exists()).toBe(true)
  })

  it('renders the agency name + role in the create-account subheading', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({
      data: makePreview({ agency_name: 'Northwind Talent', role: 'agency_staff' }),
    })
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    const card = h.wrapper.find('[data-test="accept-invitation-create-account"]')
    expect(card.text()).toContain('Northwind Talent')
    expect(card.text()).toContain('Staff')
  })

  // -------------------------------------------------------------------
  // Review priority #1 — email immutability, pinned at the UI layer
  // -------------------------------------------------------------------

  it('the email field renders the invited_email from the preview and is read-only — no editable email input exists on this form', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({
      data: makePreview({ invited_email: 'stranger@example.com' }),
    })
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    const emailField = h.wrapper.find('[data-test="create-account-email"] input')
    expect((emailField.element as HTMLInputElement).value).toBe('stranger@example.com')
    expect(emailField.attributes('readonly')).toBeDefined()
  })

  it('the submitted payload carries token/name/last_name/password/password_confirmation only — no email key at all', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    vi.mocked(invitationsApi.createAccount).mockResolvedValue(NEW_USER)
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await fillCreateAccountForm(h.wrapper)
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(invitationsApi.createAccount).toHaveBeenCalledWith(AGENCY, {
      token: TOKEN,
      name: 'Stella',
      last_name: 'Stranger',
      password: 'Correct-Horse-Battery-Staple-42',
      password_confirmation: 'Correct-Horse-Battery-Staple-42',
    })
    const payload = vi.mocked(invitationsApi.createAccount).mock.calls[0]?.[1]
    expect(payload).not.toHaveProperty('email')
  })

  // -------------------------------------------------------------------
  // Happy path — setUser() direct, no bootstrap round-trip
  // -------------------------------------------------------------------

  it('happy path: submits the form, calls setUser() with the response directly, and lands on the success state', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    vi.mocked(invitationsApi.createAccount).mockResolvedValue(NEW_USER)
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await fillCreateAccountForm(h.wrapper)
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="accept-invitation-success"]').exists()).toBe(true)
    // setUser() lands the store's user directly from the createAccount()
    // response — assert there was no second `/me` round-trip: the ONLY
    // authApi.me() call is the loadPreview() bootstrap on mount.
    expect(authApi.me).toHaveBeenCalledTimes(1)
  })

  it('the submit button shows the submitting label while the request is in flight', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    let resolveCreate: (u: UserEnvelope) => void = () => undefined
    vi.mocked(invitationsApi.createAccount).mockImplementation(
      () =>
        new Promise<UserEnvelope>((resolve) => {
          resolveCreate = resolve
        }),
    )
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await fillCreateAccountForm(h.wrapper)
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="create-account-submit"]').text()).toContain(
      'Creating your account',
    )
    resolveCreate(NEW_USER)
    await flushPromises()
  })

  // -------------------------------------------------------------------
  // D3 — the collision race re-branches to the sign-in path
  // -------------------------------------------------------------------

  it('on invitation.account_exists (409, the collision race), re-branches to the sign-in state rather than erroring', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    vi.mocked(invitationsApi.createAccount).mockRejectedValue(
      new ApiError({
        status: 409,
        code: 'invitation.account_exists',
        message: 'An account already exists for the invited email.',
      }),
    )
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await fillCreateAccountForm(h.wrapper)
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="accept-invitation-unauthenticated"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="accept-invitation-error"]').exists()).toBe(false)
  })

  // -------------------------------------------------------------------
  // Guards — expiry / already-accepted / not-found surfaced identically
  // to the existing accept-form's error handling
  // -------------------------------------------------------------------

  it('on a 410 / invitation.expired response, switches to the expired state', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    vi.mocked(invitationsApi.createAccount).mockRejectedValue(
      new ApiError({ status: 410, code: 'invitation.expired', message: 'expired' }),
    )
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await fillCreateAccountForm(h.wrapper)
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="accept-invitation-expired"]').exists()).toBe(true)
  })

  it('on invitation.already_accepted, switches to the already-accepted state', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    vi.mocked(invitationsApi.createAccount).mockRejectedValue(
      new ApiError({ status: 409, code: 'invitation.already_accepted', message: 'already used' }),
    )
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await fillCreateAccountForm(h.wrapper)
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="accept-invitation-already-accepted"]').exists()).toBe(true)
  })

  // -------------------------------------------------------------------
  // Field-level + generic error rendering on the new form
  // -------------------------------------------------------------------

  it('binds a per-field password error under the password field (validation.failed envelope)', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    vi.mocked(invitationsApi.createAccount).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'The password field must be at least 12 characters.',
        details: [
          {
            code: 'validation.failed',
            title: 'The password field must be at least 12 characters.',
            detail: 'The password field must be at least 12 characters.',
            source: { pointer: '/data/attributes/password' },
            meta: { field: 'password' },
          },
        ],
      }),
    )
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await fillCreateAccountForm(h.wrapper, { password: 'short', confirmation: 'short' })
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    const passwordField = h.wrapper.find('[data-test="create-account-password"]')
    expect(passwordField.text()).toContain('at least 12 characters')
    expect(h.wrapper.find('[data-test="create-account-error"]').text()).toBe('')
  })

  it('binds per-field errors on both name fields simultaneously', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    vi.mocked(invitationsApi.createAccount).mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'The name field is required.',
        details: [
          {
            code: 'validation.failed',
            title: 'The name field is required.',
            detail: 'The name field is required.',
            source: { pointer: '/data/attributes/name' },
            meta: { field: 'name' },
          },
          {
            code: 'validation.failed',
            title: 'The last name field is required.',
            detail: 'The last name field is required.',
            source: { pointer: '/data/attributes/last_name' },
            meta: { field: 'last_name' },
          },
        ],
      }),
    )
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="create-account-first-name"]').text()).toContain(
      'name field is required',
    )
    expect(h.wrapper.find('[data-test="create-account-last-name"]').text()).toContain(
      'last name field is required',
    )
  })

  it('on a non-ApiError, falls back to the generic unknown-error message', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    vi.mocked(invitationsApi.createAccount).mockRejectedValue(new Error('boom'))
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await fillCreateAccountForm(h.wrapper)
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(h.wrapper.find('[data-test="create-account-error"]').text()).toBe(
      'Something went wrong. Please try again.',
    )
  })

  it('clears a previous per-field error on the next submit attempt (the retry has no field errors this time)', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    vi.mocked(invitationsApi.createAccount).mockRejectedValueOnce(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'fail',
        details: [
          {
            code: 'validation.failed',
            title: 'The password field is required.',
            detail: 'The password field is required.',
            source: { pointer: '/data/attributes/password' },
            meta: { field: 'password' },
          },
        ],
      }),
    )
    // The retry still fails, but with a non-field (generic) error, so the
    // form stays mounted and the FIELD error's clearing is observable
    // (a successful retry would flip `state` to 'success' and unmount the
    // form entirely — not what this test is pinning).
    vi.mocked(invitationsApi.createAccount).mockRejectedValueOnce(new Error('still down'))

    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    await fillCreateAccountForm(h.wrapper)
    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="create-account-password"]').text()).toContain(
      'password field is required',
    )

    await h.wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(h.wrapper.find('[data-test="create-account-password"]').text()).not.toContain(
      'password field is required',
    )
    expect(h.wrapper.find('[data-test="create-account-error"]').text()).toBe(
      'Something went wrong. Please try again.',
    )
  })

  // -------------------------------------------------------------------
  // a11y — the error region matches the SignUpPage/SignInPage convention
  // -------------------------------------------------------------------

  it('the create-account error region uses aria-live="polite"', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-test="create-account-error"]').attributes('aria-live')).toBe(
      'polite',
    )
  })

  // -------------------------------------------------------------------
  // "Already have an account?" link — carries the redirect param
  // -------------------------------------------------------------------

  it('the "already have an account" sign-in link points at auth.sign-in with the redirect query preserved', async () => {
    await unauthenticated()
    vi.mocked(invitationsApi.preview).mockResolvedValue({ data: makePreview() })
    const h = await mountAuthPage(AcceptInvitationPage, { initialRoute: ROUTE })
    teardown = h.unmount
    await flushPromises()

    const link = h.wrapper.find('[data-test="create-account-sign-in-link"]')
    expect(link.exists()).toBe(true)
  })
})
