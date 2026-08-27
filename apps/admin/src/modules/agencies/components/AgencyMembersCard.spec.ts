/**
 * AgencyMembersCard unit tests (AH-087).
 *
 * The frontend half of the review priorities:
 *   - the three-state MFA column (Exempt wins the chip over Enrolled — R6),
 *   - the reason gate on all three operations (nothing leaves the SPA without
 *     one, mirroring MemberMfaActionRequest's min-length),
 *   - the row re-rendering from the server's response rather than from a
 *     local guess,
 *   - the backend's semantic conflict codes surfacing as readable copy,
 *     including platform-admin-not-exemptable (priority #2's UI end).
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/agencies/api/agencies.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/agencies/api/agencies.api')>(
    '@/modules/agencies/api/agencies.api',
  )
  return {
    ...actual,
    adminAgenciesApi: {
      list: vi.fn(),
      show: vi.fn(),
      suspend: vi.fn(),
      reactivate: vi.fn(),
      members: vi.fn(),
      resetMemberTwoFactor: vi.fn(),
      exemptMemberFromTwoFactor: vi.fn(),
      removeMemberTwoFactorExemption: vi.fn(),
    },
  }
})

import { ApiError } from '@catalyst/api-client'

import {
  adminAgenciesApi,
  type AdminAgencyMember,
  type AdminAgencyMemberListResponse,
} from '@/modules/agencies/api/agencies.api'

import { mountAgencyPage } from '../../../../tests/unit/helpers/mountAgencyPage'
import AgencyMembersCard from './AgencyMembersCard.vue'

const AGENCY = '01HQAGENCY'

function member(overrides: Partial<AdminAgencyMember['attributes']> = {}): AdminAgencyMember {
  return {
    id: overrides.user_id ?? '01HQMEMBER1',
    type: 'agency_members',
    attributes: {
      user_id: '01HQMEMBER1',
      name: 'Dana Reyes',
      email: 'dana@acme.test',
      role: 'agency_manager',
      status: 'active',
      joined_at: '2026-03-01T00:00:00Z',
      last_active_at: '2026-08-20T10:00:00Z',
      is_mfa_enrolled: false,
      is_mfa_exempt: false,
      mfa_exempted_at: null,
      mfa_exemption_reason: null,
      ...overrides,
    },
  }
}

function page(rows: AdminAgencyMember[]): AdminAgencyMemberListResponse {
  return {
    data: rows,
    meta: { total: rows.length, page: 1, per_page: 25, last_page: 1 },
  }
}

async function mountCard(rows: AdminAgencyMember[]) {
  vi.mocked(adminAgenciesApi.members).mockResolvedValue(page(rows))
  const h = await mountAgencyPage(AgencyMembersCard, {
    initialRoute: { name: 'app.agencies.detail', params: { ulid: AGENCY } },
    props: { agencyUlid: AGENCY },
  })
  await flushPromises()
  return h
}

/** The confirm button lives in a teleported v-dialog, so query the document. */
function dialogEl<T extends Element = HTMLElement>(testid: string): T | null {
  return document.querySelector<T>(`[data-testid="${testid}"]`)
}

async function typeReason(text: string): Promise<void> {
  const textarea = dialogEl<HTMLTextAreaElement>('admin-member-mfa-dialog-reason')
  const field = textarea?.tagName === 'TEXTAREA' ? textarea : textarea?.querySelector('textarea')
  if (field) {
    field.value = text
    field.dispatchEvent(new Event('input'))
  }
  await flushPromises()
}

async function openRowAction(
  h: Awaited<ReturnType<typeof mountCard>>,
  testid: string,
): Promise<void> {
  await h.wrapper.find(`[data-testid="admin-agency-members-menu-01HQMEMBER1"]`).trigger('click')
  await flushPromises()
  const item = dialogEl<HTMLElement>(testid)
  item?.click()
  await flushPromises()
}

describe('AgencyMembersCard (AH-087)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('loads the agency members on mount and states the admin boundary', async () => {
    const h = await mountCard([member()])
    teardown = h.unmount

    expect(adminAgenciesApi.members).toHaveBeenCalledWith(AGENCY, {
      role: undefined,
      search: undefined,
      page: 1,
      per_page: 25,
    })

    // D4: the boundary is written on the surface itself, not only in the docs.
    const note = h.wrapper.find('[data-testid="admin-agency-members-card"]').text()
    expect(note).toContain('can never see')
    expect(h.wrapper.text()).toContain('dana@acme.test')
  })

  it('renders the three MFA states, with Exempt winning over Enrolled', async () => {
    const h = await mountCard([
      member({ user_id: '01HQMEMBER1', is_mfa_enrolled: false, is_mfa_exempt: false }),
      member({ user_id: '01HQMEMBER2', is_mfa_enrolled: true, is_mfa_exempt: false }),
      member({
        user_id: '01HQMEMBER3',
        // R6: enrolled AND exempt — the chip says Exempt because that is the
        // security-relevant fact; the tooltip carries "also enrolled".
        is_mfa_enrolled: true,
        is_mfa_exempt: true,
        mfa_exempted_at: '2026-08-01T00:00:00Z',
        mfa_exemption_reason: 'Shared terminal, no personal device.',
      }),
    ])
    teardown = h.unmount

    expect(h.wrapper.find('[data-testid="admin-agency-members-mfa-01HQMEMBER1"]').text()).toContain(
      'Not enrolled',
    )
    expect(h.wrapper.find('[data-testid="admin-agency-members-mfa-01HQMEMBER2"]').text()).toContain(
      'Enrolled',
    )
    expect(h.wrapper.find('[data-testid="admin-agency-members-mfa-01HQMEMBER3"]').text()).toContain(
      'Exempt',
    )
  })

  it('offers Exempt on a non-exempt row and Remove exemption on an exempt one, never both', async () => {
    const h = await mountCard([member({ is_mfa_exempt: false })])
    teardown = h.unmount

    await h.wrapper.find('[data-testid="admin-agency-members-menu-01HQMEMBER1"]').trigger('click')
    await flushPromises()

    expect(dialogEl('admin-agency-members-exempt-01HQMEMBER1')).not.toBeNull()
    expect(dialogEl('admin-agency-members-remove-exemption-01HQMEMBER1')).toBeNull()
    // Reset is always available: losing a device is orthogonal to exemption.
    expect(dialogEl('admin-agency-members-reset-01HQMEMBER1')).not.toBeNull()
  })

  it('keeps the confirm disabled until a min-length reason is typed', async () => {
    const h = await mountCard([member()])
    teardown = h.unmount

    await openRowAction(h, 'admin-agency-members-reset-01HQMEMBER1')

    const confirm = dialogEl<HTMLButtonElement>('admin-member-mfa-dialog-confirm')
    expect(confirm).not.toBeNull()
    expect(confirm?.disabled).toBe(true)

    await typeReason('short')
    expect(dialogEl<HTMLButtonElement>('admin-member-mfa-dialog-confirm')?.disabled).toBe(true)

    await typeReason('Lost their phone, verified by video call.')
    expect(dialogEl<HTMLButtonElement>('admin-member-mfa-dialog-confirm')?.disabled).toBe(false)
  })

  it('resets 2FA with the typed reason and re-renders the row from the response', async () => {
    const h = await mountCard([member({ is_mfa_enrolled: true })])
    teardown = h.unmount

    vi.mocked(adminAgenciesApi.resetMemberTwoFactor).mockResolvedValue({
      data: member({ is_mfa_enrolled: false }),
    })

    await openRowAction(h, 'admin-agency-members-reset-01HQMEMBER1')
    await typeReason('Lost their phone, verified by video call.')
    dialogEl<HTMLButtonElement>('admin-member-mfa-dialog-confirm')?.click()
    await flushPromises()

    expect(adminAgenciesApi.resetMemberTwoFactor).toHaveBeenCalledWith(
      AGENCY,
      '01HQMEMBER1',
      'Lost their phone, verified by video call.',
    )
    expect(h.wrapper.find('[data-testid="admin-agency-members-mfa-01HQMEMBER1"]').text()).toContain(
      'Not enrolled',
    )
    expect(h.wrapper.emitted('acted')?.[0]).toEqual([
      { messageKey: 'admin.agencies.members.actions.reset.success', color: 'success' },
    ])
  })

  it('exempts with a reason and flips the chip to Exempt from the response', async () => {
    const h = await mountCard([member()])
    teardown = h.unmount

    vi.mocked(adminAgenciesApi.exemptMemberFromTwoFactor).mockResolvedValue({
      data: member({
        is_mfa_exempt: true,
        mfa_exempted_at: '2026-08-27T00:00:00Z',
        mfa_exemption_reason: 'Warehouse shared login, approved by ops lead.',
      }),
    })

    await openRowAction(h, 'admin-agency-members-exempt-01HQMEMBER1')
    await typeReason('Warehouse shared login, approved by ops lead.')
    dialogEl<HTMLButtonElement>('admin-member-mfa-dialog-confirm')?.click()
    await flushPromises()

    expect(adminAgenciesApi.exemptMemberFromTwoFactor).toHaveBeenCalledWith(
      AGENCY,
      '01HQMEMBER1',
      'Warehouse shared login, approved by ops lead.',
    )
    expect(h.wrapper.find('[data-testid="admin-agency-members-mfa-01HQMEMBER1"]').text()).toContain(
      'Exempt',
    )
  })

  it('removes an exemption with a reason — both directions of the flip need a why', async () => {
    const h = await mountCard([
      member({
        is_mfa_exempt: true,
        mfa_exempted_at: '2026-08-01T00:00:00Z',
        mfa_exemption_reason: 'Prior exemption.',
      }),
    ])
    teardown = h.unmount

    vi.mocked(adminAgenciesApi.removeMemberTwoFactorExemption).mockResolvedValue({
      data: member({ is_mfa_exempt: false }),
    })

    await openRowAction(h, 'admin-agency-members-remove-exemption-01HQMEMBER1')

    // R5's divergence from agency.reactivate, visible in the UI: the confirm
    // is gated on a reason here too.
    expect(dialogEl<HTMLButtonElement>('admin-member-mfa-dialog-confirm')?.disabled).toBe(true)

    await typeReason('Device issued; requirement restored per policy.')
    dialogEl<HTMLButtonElement>('admin-member-mfa-dialog-confirm')?.click()
    await flushPromises()

    expect(adminAgenciesApi.removeMemberTwoFactorExemption).toHaveBeenCalledWith(
      AGENCY,
      '01HQMEMBER1',
      'Device issued; requirement restored per policy.',
    )
    expect(h.wrapper.find('[data-testid="admin-agency-members-mfa-01HQMEMBER1"]').text()).toContain(
      'Not enrolled',
    )
  })

  it('surfaces the platform-admin refusal in the dialog and leaves the row alone', async () => {
    const h = await mountCard([member()])
    teardown = h.unmount

    vi.mocked(adminAgenciesApi.exemptMemberFromTwoFactor).mockRejectedValue(
      new ApiError({ status: 422, code: 'mfa.platform_admin_not_exemptable', message: 'refused' }),
    )

    await openRowAction(h, 'admin-agency-members-exempt-01HQMEMBER1')
    await typeReason('Attempting to exempt a platform administrator.')
    dialogEl<HTMLButtonElement>('admin-member-mfa-dialog-confirm')?.click()
    await flushPromises()

    const error = dialogEl('admin-member-mfa-dialog-error')
    expect(error).not.toBeNull()
    expect(error?.textContent ?? '').toContain('can never be exempted')
    // The dialog stays open on a refusal, and the chip has not moved.
    expect(h.wrapper.find('[data-testid="admin-agency-members-mfa-01HQMEMBER1"]').text()).toContain(
      'Not enrolled',
    )
    expect(h.wrapper.emitted('acted')).toBeUndefined()
  })

  it('surfaces a 409 conflict code as its own copy rather than a generic failure', async () => {
    const h = await mountCard([member({ is_mfa_enrolled: false })])
    teardown = h.unmount

    vi.mocked(adminAgenciesApi.resetMemberTwoFactor).mockRejectedValue(
      new ApiError({ status: 409, code: 'mfa.nothing_to_reset', message: 'conflict' }),
    )

    await openRowAction(h, 'admin-agency-members-reset-01HQMEMBER1')
    await typeReason('Support ticket 4821, user reported a lost device.')
    dialogEl<HTMLButtonElement>('admin-member-mfa-dialog-confirm')?.click()
    await flushPromises()

    expect(dialogEl('admin-member-mfa-dialog-error')?.textContent ?? '').toContain(
      'no two-factor enrollment to reset',
    )
  })

  it('re-requests page 1 when the role filter changes', async () => {
    const h = await mountCard([member()])
    teardown = h.unmount
    vi.mocked(adminAgenciesApi.members).mockClear()

    const chip = h.wrapper.find('[data-testid="admin-agency-members-role-filter-agency_admin"]')
    await chip.trigger('click')
    await flushPromises()

    expect(adminAgenciesApi.members).toHaveBeenCalledWith(
      AGENCY,
      expect.objectContaining({ role: 'agency_admin', page: 1 }),
    )
  })

  it('renders the load error code when the members request fails', async () => {
    vi.mocked(adminAgenciesApi.members).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'forbidden' }),
    )
    const h = await mountAgencyPage(AgencyMembersCard, {
      initialRoute: { name: 'app.agencies.detail', params: { ulid: AGENCY } },
      props: { agencyUlid: AGENCY },
    })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-agency-members-error"]').exists()).toBe(true)
  })
})
