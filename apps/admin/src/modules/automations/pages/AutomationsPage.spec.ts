/**
 * AutomationsPage unit tests (AH-090, D6).
 *
 * Focus: the wiring the mocked API contract can actually prove — that each
 * write sends the shape the backend validates, that every one of them is gated
 * behind a min-length reason (the backend 422s a reasonless call, so a UI that
 * let one through would produce an error the operator cannot act on), and that a
 * broken rule is rendered as broken instead of silently looking healthy.
 *
 * What these specs deliberately do NOT prove: that the payload matches the real
 * endpoint. The contract here is mocked, so a renamed field would pass. That gap
 * is named in the AH-090 review — the Pest integration test covers the real
 * approve → job → relation path, and no Playwright leg ships this chunk (the
 * admin suite has no business-data fixtures).
 */

import { ApiError } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/automations/api/automations.api', async () => {
  const actual = await vi.importActual<typeof import('@/modules/automations/api/automations.api')>(
    '@/modules/automations/api/automations.api',
  )
  return {
    ...actual,
    adminAutomationsApi: {
      list: vi.fn(),
      create: vi.fn(),
      update: vi.fn(),
      toggle: vi.fn(),
      remove: vi.fn(),
      agencyOptions: vi.fn(),
    },
  }
})

import {
  adminAutomationsApi,
  type AdminAgencyOptionListResponse,
  type AdminAutomationRule,
  type AdminAutomationRuleListResponse,
  type AutomationBrokenReason,
} from '@/modules/automations/api/automations.api'

import { mountAutomationsPage } from '../../../../tests/unit/helpers/mountAutomationsPage'
import AutomationsPage from './AutomationsPage.vue'

function rule(overrides: Partial<AdminAutomationRule['attributes']> = {}): AdminAutomationRule {
  return {
    id: '01JRULE0000000000000000001',
    type: 'automation_rules',
    attributes: {
      name: 'Approved → Northwind',
      event: 'creator.approved',
      action: 'connect_to_agency',
      enabled: true,
      target_agency_id: '01JAGENCY000000000000000001',
      target_agency_name: 'Northwind Talent',
      target_agency_suspended: false,
      broken_reason: null,
      last_fired_at: null,
      fire_count: 0,
      created_at: '2026-08-27T10:00:00+00:00',
      updated_at: '2026-08-27T10:00:00+00:00',
      ...overrides,
    },
  }
}

function listResponse(...rules: AdminAutomationRule[]): AdminAutomationRuleListResponse {
  return { data: rules }
}

function agencyOptions(): AdminAgencyOptionListResponse {
  return {
    data: [
      {
        id: '01JAGENCY000000000000000001',
        type: 'agencies',
        attributes: { name: 'Northwind Talent' },
      },
      {
        id: '01JAGENCY000000000000000002',
        type: 'agencies',
        attributes: { name: 'Eastwind Talent' },
      },
    ],
  }
}

/** Type a value into a teleported dialog field and let Vue settle. */
async function type(testid: string, tag: 'input' | 'textarea', value: string): Promise<void> {
  const el = document.querySelector(`[data-testid="${testid}"] ${tag}`) as
    | HTMLInputElement
    | HTMLTextAreaElement
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

function button(testid: string): HTMLButtonElement | null {
  return document.querySelector(`[data-testid="${testid}"]`) as HTMLButtonElement | null
}

function fieldValue(testid: string, tag: 'input' | 'textarea'): string {
  return (
    document.querySelector(`[data-testid="${testid}"] ${tag}`) as
      | HTMLInputElement
      | HTMLTextAreaElement
  ).value
}

/**
 * Choose an agency through the autocomplete's own model rather than driving
 * Vuetify's menu internals — the assertion that matters is which ULID reaches
 * the payload, not how the menu renders.
 */
async function pickAgency(
  h: Awaited<ReturnType<typeof mountAutomationsPage>>,
  ulid: string,
): Promise<void> {
  await h.wrapper.findComponent({ name: 'VAutocomplete' }).setValue(ulid)
  await flushPromises()
}

describe('AutomationsPage (AH-090, D6)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(adminAutomationsApi.agencyOptions).mockResolvedValue(agencyOptions())
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('lists rules with their fire trace on mount, and loads the agency picker feed', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(
      listResponse(rule({ fire_count: 3, last_fired_at: '2026-08-26T09:00:00+00:00' })),
    )

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    expect(adminAutomationsApi.list).toHaveBeenCalledOnce()
    // The picker is fed by `?for=select`, not the paginated agencies index.
    expect(adminAutomationsApi.agencyOptions).toHaveBeenCalledOnce()

    const row = h.wrapper.find('[data-testid="admin-automation-01JRULE0000000000000000001"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('Approved → Northwind')
    expect(row.text()).toContain('Northwind Talent')
    expect(row.text()).toContain('3')
  })

  it('shows the empty state when nothing is configured', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(listResponse())

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-automations-empty"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-testid="admin-automations-table"]').exists()).toBe(false)
  })

  it('always shows the kill-switch notice — a rule created here does nothing until the flag is on', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(listResponse(rule()))

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-testid="admin-automations-flag-notice"]').exists()).toBe(true)
  })

  it.each<[AutomationBrokenReason, Partial<AdminAutomationRule['attributes']>]>([
    ['agency_suspended', { target_agency_suspended: true }],
    ['agency_missing', { target_agency_name: null }],
    ['misconfigured', { target_agency_id: null, target_agency_name: null }],
  ])('renders a %s rule as broken rather than looking healthy', async (reason, overrides) => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(
      listResponse(rule({ broken_reason: reason, ...overrides })),
    )

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    // The backend job refuses to fire in these states; hiding it here would
    // leave an operator staring at a rule that silently never does anything.
    expect(
      h.wrapper.find('[data-testid="admin-automation-broken-01JRULE0000000000000000001"]').exists(),
    ).toBe(true)
  })

  it('creates a rule with the reason and the agency ULID in the config', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(listResponse())
    vi.mocked(adminAutomationsApi.create).mockResolvedValue({ data: rule() })

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-automations-create"]').trigger('click')
    await flushPromises()

    await type('admin-automation-form-name', 'input', 'Approved → Northwind')
    await type('admin-automation-form-reason', 'textarea', 'Northwind signed a platform-wide deal.')

    // The confirm stays disabled until an agency is chosen: a rule with no
    // target is a rule that can never act.
    expect(button('admin-automation-form-confirm')?.disabled).toBe(true)

    await pickAgency(h, '01JAGENCY000000000000000001')

    button('admin-automation-form-confirm')?.click()
    await flushPromises()

    expect(adminAutomationsApi.create).toHaveBeenCalledWith({
      name: 'Approved → Northwind',
      event: 'creator.approved',
      action: 'connect_to_agency',
      config: { agency_id: '01JAGENCY000000000000000001' },
      reason: 'Northwind signed a platform-wide deal.',
    })

    // The new row comes from the SERVER response, not from the form values.
    expect(
      h.wrapper.find('[data-testid="admin-automation-01JRULE0000000000000000001"]').exists(),
    ).toBe(true)
  })

  it('keeps the create confirm disabled while the reason is too short', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(listResponse())

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-automations-create"]').trigger('click')
    await flushPromises()

    await type('admin-automation-form-name', 'input', 'Approved → Northwind')
    await pickAgency(h, '01JAGENCY000000000000000001')
    await type('admin-automation-form-reason', 'textarea', 'too short')

    expect(button('admin-automation-form-confirm')?.disabled).toBe(true)
    expect(adminAutomationsApi.create).not.toHaveBeenCalled()
  })

  it('edits an existing rule, prefilled but with an EMPTY reason', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(listResponse(rule()))
    vi.mocked(adminAutomationsApi.update).mockResolvedValue({
      data: rule({ name: 'Retargeted', target_agency_name: 'Eastwind Talent' }),
    })

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper
      .find('[data-testid="admin-automation-edit-01JRULE0000000000000000001"]')
      .trigger('click')
    await flushPromises()

    // The name is prefilled; the reason is NOT — a reason describes THIS change,
    // so reusing the last one would be a lie in the audit log.
    expect(fieldValue('admin-automation-form-name', 'input')).toBe('Approved → Northwind')
    expect(fieldValue('admin-automation-form-reason', 'textarea')).toBe('')
    expect(button('admin-automation-form-confirm')?.disabled).toBe(true)

    await type('admin-automation-form-name', 'input', 'Retargeted')
    await type('admin-automation-form-reason', 'textarea', 'The deal moved to Eastwind Talent.')
    button('admin-automation-form-confirm')?.click()
    await flushPromises()

    expect(adminAutomationsApi.update).toHaveBeenCalledWith(
      '01JRULE0000000000000000001',
      expect.objectContaining({ name: 'Retargeted', reason: 'The deal moved to Eastwind Talent.' }),
    )
    expect(h.wrapper.find('[data-testid="admin-automations-table"]').text()).toContain('Retargeted')
  })

  it('warns that another rule already watches the event before a duplicate is created', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(listResponse(rule()))

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-automations-create"]').trigger('click')
    await flushPromises()

    // The backend has no uniqueness constraint (overlap is absorbed by the
    // connect's idempotency); this stops the duplicate being created BLIND.
    expect(document.querySelector('[data-testid="admin-automation-form-existing"]')).not.toBeNull()
  })

  it('gates the toggle behind a reason and sends the INTENDED next state', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(listResponse(rule({ enabled: true })))
    vi.mocked(adminAutomationsApi.toggle).mockResolvedValue({ data: rule({ enabled: false }) })

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper
      .find('[data-testid="admin-automation-switch-01JRULE0000000000000000001"]')
      .trigger('click')
    await flushPromises()

    // The switch's click is intercepted before the model flips, so the UI never
    // shows a state the server has not confirmed.
    expect(adminAutomationsApi.toggle).not.toHaveBeenCalled()
    expect(button('admin-automation-toggle-confirm')?.disabled).toBe(true)

    await type('admin-automation-toggle-reason', 'textarea', 'Pausing while we verify the roster.')
    button('admin-automation-toggle-confirm')?.click()
    await flushPromises()

    expect(adminAutomationsApi.toggle).toHaveBeenCalledWith(
      '01JRULE0000000000000000001',
      false,
      'Pausing while we verify the roster.',
    )
  })

  it('gates the delete behind a reason and drops the row on success', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(listResponse(rule()))
    vi.mocked(adminAutomationsApi.remove).mockResolvedValue(undefined)

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    await h.wrapper
      .find('[data-testid="admin-automation-delete-01JRULE0000000000000000001"]')
      .trigger('click')
    await flushPromises()

    expect(button('admin-automation-delete-confirm')?.disabled).toBe(true)

    await type('admin-automation-delete-reason', 'textarea', 'The representation deal ended.')
    button('admin-automation-delete-confirm')?.click()
    await flushPromises()

    expect(adminAutomationsApi.remove).toHaveBeenCalledWith(
      '01JRULE0000000000000000001',
      'The representation deal ended.',
    )
    expect(
      h.wrapper.find('[data-testid="admin-automation-01JRULE0000000000000000001"]').exists(),
    ).toBe(false)
  })

  it('still renders a rule naming an event this build does not know', async () => {
    vi.mocked(adminAutomationsApi.list).mockResolvedValue(
      // A rule written by a newer version, or hand-edited. The row must not go
      // blank: an operator needs to see that the rule EXISTS.
      listResponse(rule({ event: 'kyc.verified' as never, name: 'From the future' })),
    )

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    const row = h.wrapper.find('[data-testid="admin-automation-01JRULE0000000000000000001"]')
    expect(row.text()).toContain('From the future')
    expect(row.text()).toContain('kyc.verified')
  })

  it('shows an owned message when the load fails, never a raw API code', async () => {
    vi.mocked(adminAutomationsApi.list).mockRejectedValue(
      new ApiError({ status: 403, code: 'auth.forbidden', message: 'no' }),
    )

    const h = await mountAutomationsPage(AutomationsPage)
    teardown = h.unmount
    await flushPromises()

    const alert = h.wrapper.find('[data-testid="admin-automations-error"]')
    expect(alert.exists()).toBe(true)
    expect(alert.text()).not.toContain('auth.forbidden')
  })
})
