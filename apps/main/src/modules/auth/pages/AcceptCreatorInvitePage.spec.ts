/**
 * Sprint 3 Chunk 4 sub-step 4 — Vitest coverage for the 5-state UI on
 * the new /auth/accept-invite route.
 *
 * The 5 states (Decision C1=a):
 *   loading | valid-pending | already-accepted | expired | invalid
 *
 * Each state has a distinct `data-test` anchor and a localised heading
 * + description; we render the en bundle here (a separate i18n
 * smoke-test asserts pt + it parity at the bundle level via the
 * `i18n-auth-codes.spec.ts` architecture test).
 */

import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import AcceptCreatorInvitePage from './AcceptCreatorInvitePage.vue'

vi.mock('@/modules/auth/api/creator-invitations.api', () => ({
  previewCreatorInvitation: vi.fn(),
}))

import { previewCreatorInvitation } from '@/modules/auth/api/creator-invitations.api'

describe('AcceptCreatorInvitePage — 5 states', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
  })

  it('renders the invalid state when the URL has no token', async () => {
    const h = await mountAuthPage(AcceptCreatorInvitePage, {
      initialRoute: { path: '/auth/accept-invite' },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="accept-creator-invite-invalid"]').exists()).toBe(true)
    expect(previewCreatorInvitation).not.toHaveBeenCalled()
  })

  it('renders the valid-pending state with the agency name when preview is valid', async () => {
    vi.mocked(previewCreatorInvitation).mockResolvedValue({
      kind: 'valid-pending',
      agencyName: 'Acme Talent',
    })
    const h = await mountAuthPage(AcceptCreatorInvitePage, {
      initialRoute: { path: '/auth/accept-invite', query: { token: 'good-token' } },
    })
    teardown = h.unmount
    await flushPromises()
    const card = h.wrapper.find('[data-test="accept-creator-invite-valid-pending"]')
    expect(card.exists()).toBe(true)
    expect(card.text()).toContain('Acme Talent')
    expect(card.find('[data-test="accept-creator-invite-continue"]').exists()).toBe(true)
  })

  it('renders the already-accepted state when preview returns is_accepted', async () => {
    vi.mocked(previewCreatorInvitation).mockResolvedValue({
      kind: 'already-accepted',
      agencyName: 'Acme Talent',
    })
    const h = await mountAuthPage(AcceptCreatorInvitePage, {
      initialRoute: { path: '/auth/accept-invite', query: { token: 'used-token' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="accept-creator-invite-already-accepted"]').exists()).toBe(
      true,
    )
    expect(h.wrapper.find('[data-test="accept-creator-invite-sign-in"]').exists()).toBe(true)
  })

  it('renders the expired state when preview returns is_expired', async () => {
    vi.mocked(previewCreatorInvitation).mockResolvedValue({
      kind: 'expired',
      agencyName: 'Acme Talent',
    })
    const h = await mountAuthPage(AcceptCreatorInvitePage, {
      initialRoute: { path: '/auth/accept-invite', query: { token: 'expired-token' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="accept-creator-invite-expired"]').exists()).toBe(true)
  })

  it('renders the invalid state when preview returns invalid (404 path)', async () => {
    vi.mocked(previewCreatorInvitation).mockResolvedValue({ kind: 'invalid' })
    const h = await mountAuthPage(AcceptCreatorInvitePage, {
      initialRoute: { path: '/auth/accept-invite', query: { token: 'bogus-token' } },
    })
    teardown = h.unmount
    await flushPromises()
    expect(h.wrapper.find('[data-test="accept-creator-invite-invalid"]').exists()).toBe(true)
  })

  it('clicking Continue navigates to /sign-up?token=<token>', async () => {
    vi.mocked(previewCreatorInvitation).mockResolvedValue({
      kind: 'valid-pending',
      agencyName: 'Acme Talent',
    })
    const h = await mountAuthPage(AcceptCreatorInvitePage, {
      initialRoute: { path: '/auth/accept-invite', query: { token: 'good-token' } },
    })
    teardown = h.unmount
    await flushPromises()
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="accept-creator-invite-continue"]').trigger('click')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'auth.sign-up',
      query: { token: 'good-token' },
    })
  })

  it('clicking Sign in (from already-accepted state) navigates to /sign-in', async () => {
    vi.mocked(previewCreatorInvitation).mockResolvedValue({
      kind: 'already-accepted',
      agencyName: 'Acme Talent',
    })
    const h = await mountAuthPage(AcceptCreatorInvitePage, {
      initialRoute: { path: '/auth/accept-invite', query: { token: 'used' } },
    })
    teardown = h.unmount
    await flushPromises()
    const pushSpy = vi.spyOn(h.router, 'push')
    await h.wrapper.find('[data-test="accept-creator-invite-sign-in"]').trigger('click')
    await flushPromises()
    expect(pushSpy).toHaveBeenCalledWith({ name: 'auth.sign-in' })
  })

  it('renders the loading state synchronously before the preview promise resolves', async () => {
    let resolvePreview: (value: { kind: 'valid-pending'; agencyName: string }) => void
    const pending = new Promise<{ kind: 'valid-pending'; agencyName: string }>((resolve) => {
      resolvePreview = resolve
    })
    vi.mocked(previewCreatorInvitation).mockReturnValue(pending)

    const h = await mountAuthPage(AcceptCreatorInvitePage, {
      initialRoute: { path: '/auth/accept-invite', query: { token: 'slow' } },
    })
    teardown = h.unmount

    expect(h.wrapper.find('[data-test="accept-creator-invite-loading"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="accept-creator-invite-valid-pending"]').exists()).toBe(false)

    // Now resolve and confirm the loading card swaps for the final state.
    resolvePreview!({ kind: 'valid-pending', agencyName: 'Acme Talent' })
    await flushPromises()
    expect(h.wrapper.find('[data-test="accept-creator-invite-loading"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-test="accept-creator-invite-valid-pending"]').exists()).toBe(true)
  })
})
