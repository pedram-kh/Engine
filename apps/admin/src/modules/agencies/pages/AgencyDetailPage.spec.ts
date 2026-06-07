/**
 * AgencyDetailPage unit tests (Sprint 13, D-3).
 *
 * Focus: the suspend / reactivate flow — the reason gate on suspend
 * (the confirm stays disabled until the min-length reason is typed), the
 * page refreshing from the server response after each transition, and
 * the suspension panel reflecting the returned state. The backend owns
 * the auth-layer login block + the audit row; this spec asserts the SPA
 * sends the reason and renders the returned status.
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
    },
  }
})

import { adminAgenciesApi, type AdminAgencyEnvelope } from '@/modules/agencies/api/agencies.api'

import { mountAgencyPage } from '../../../../tests/unit/helpers/mountAgencyPage'
import AgencyDetailPage from './AgencyDetailPage.vue'

function envelope(
  overrides: Partial<AdminAgencyEnvelope['data']['attributes']> = {},
): AdminAgencyEnvelope {
  return {
    data: {
      id: '01HQAGENCY',
      type: 'agencies',
      attributes: {
        name: 'Acme Talent',
        slug: 'acme-talent',
        country_code: 'US',
        subscription_tier: 'pro',
        subscription_status: 'active',
        is_active: true,
        is_suspended: false,
        suspended_at: null,
        suspended_reason: null,
        member_count: 4,
        created_at: '2026-05-10T00:00:00Z',
        ...overrides,
      },
    },
  }
}

describe('AgencyDetailPage (Sprint 13, D-3)', () => {
  let teardown: (() => void) | null = null

  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    teardown?.()
    teardown = null
    document.body.innerHTML = ''
  })

  it('loads the agency on mount and shows the active state', async () => {
    vi.mocked(adminAgenciesApi.show).mockResolvedValue(envelope())

    const h = await mountAgencyPage(AgencyDetailPage, {
      initialRoute: { name: 'app.agencies.detail', params: { ulid: '01HQAGENCY' } },
    })
    teardown = h.unmount
    await flushPromises()

    expect(adminAgenciesApi.show).toHaveBeenCalledWith('01HQAGENCY')
    expect(h.wrapper.find('[data-testid="admin-agency-detail-status"]').text()).toContain('Active')
    expect(h.wrapper.find('[data-testid="admin-agency-suspend-btn"]').exists()).toBe(true)
  })

  it('keeps the suspend confirm disabled until a min-length reason is typed', async () => {
    vi.mocked(adminAgenciesApi.show).mockResolvedValue(envelope())

    const h = await mountAgencyPage(AgencyDetailPage, {
      initialRoute: { name: 'app.agencies.detail', params: { ulid: '01HQAGENCY' } },
    })
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-agency-suspend-btn"]').trigger('click')
    await flushPromises()

    const confirm = document.querySelector(
      '[data-testid="admin-agency-suspend-confirm"]',
    ) as HTMLButtonElement | null
    expect(confirm).not.toBeNull()
    expect(confirm?.disabled).toBe(true)

    const textarea = document.querySelector(
      '[data-testid="admin-agency-suspend-reason"] textarea',
    ) as HTMLTextAreaElement | null
    expect(textarea).not.toBeNull()
    if (textarea) {
      textarea.value = 'Fraudulent activity reported by partner.'
      textarea.dispatchEvent(new Event('input'))
    }
    await flushPromises()

    expect(
      (document.querySelector('[data-testid="admin-agency-suspend-confirm"]') as HTMLButtonElement)
        .disabled,
    ).toBe(false)
  })

  it('suspends with the typed reason and refreshes to the suspended state', async () => {
    vi.mocked(adminAgenciesApi.show).mockResolvedValue(envelope())
    vi.mocked(adminAgenciesApi.suspend).mockResolvedValue(
      envelope({
        is_active: false,
        is_suspended: true,
        suspended_at: '2026-06-07T00:00:00Z',
        suspended_reason: 'Fraudulent activity reported by partner.',
      }),
    )

    const h = await mountAgencyPage(AgencyDetailPage, {
      initialRoute: { name: 'app.agencies.detail', params: { ulid: '01HQAGENCY' } },
    })
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-agency-suspend-btn"]').trigger('click')
    await flushPromises()

    const textarea = document.querySelector(
      '[data-testid="admin-agency-suspend-reason"] textarea',
    ) as HTMLTextAreaElement
    textarea.value = 'Fraudulent activity reported by partner.'
    textarea.dispatchEvent(new Event('input'))
    await flushPromises()
    ;(
      document.querySelector('[data-testid="admin-agency-suspend-confirm"]') as HTMLButtonElement
    ).click()
    await flushPromises()

    expect(adminAgenciesApi.suspend).toHaveBeenCalledWith(
      '01HQAGENCY',
      'Fraudulent activity reported by partner.',
    )
    expect(h.wrapper.find('[data-testid="admin-agency-detail-status"]').text()).toContain(
      'Suspended',
    )
    expect(h.wrapper.find('[data-testid="admin-agency-reactivate-btn"]').exists()).toBe(true)
  })

  it('reactivates a suspended agency and refreshes to the active state', async () => {
    vi.mocked(adminAgenciesApi.show).mockResolvedValue(
      envelope({
        is_active: false,
        is_suspended: true,
        suspended_at: '2026-06-07T00:00:00Z',
        suspended_reason: 'Prior suspension.',
      }),
    )
    vi.mocked(adminAgenciesApi.reactivate).mockResolvedValue(envelope())

    const h = await mountAgencyPage(AgencyDetailPage, {
      initialRoute: { name: 'app.agencies.detail', params: { ulid: '01HQAGENCY' } },
    })
    teardown = h.unmount
    await flushPromises()

    await h.wrapper.find('[data-testid="admin-agency-reactivate-btn"]').trigger('click')
    await flushPromises()
    ;(
      document.querySelector('[data-testid="admin-agency-reactivate-confirm"]') as HTMLButtonElement
    ).click()
    await flushPromises()

    expect(adminAgenciesApi.reactivate).toHaveBeenCalledWith('01HQAGENCY')
    expect(h.wrapper.find('[data-testid="admin-agency-detail-status"]').text()).toContain('Active')
  })
})
