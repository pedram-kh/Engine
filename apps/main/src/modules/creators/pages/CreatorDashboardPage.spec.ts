import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../../onboarding/api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    reopen: vi.fn(),
  },
}))

vi.mock('../connectionRequests.api', () => ({
  connectionRequestsApi: {
    list: vi.fn(),
    accept: vi.fn(),
    decline: vi.fn(),
  },
}))

vi.mock('../assignments.api', () => ({
  creatorAssignmentsApi: {
    list: vi.fn(),
    accept: vi.fn(),
    decline: vi.fn(),
    counter: vi.fn(),
  },
}))

import type { ConnectionRequestListItem } from '@catalyst/api-client'

import { onboardingApi } from '../../onboarding/api/onboarding.api'
import { useOnboardingStore } from '../../onboarding/stores/useOnboardingStore'
import { connectionRequestsApi } from '../connectionRequests.api'
import { creatorAssignmentsApi } from '../assignments.api'
import CreatorDashboardPage from './CreatorDashboardPage.vue'

function makeRequest(
  id: string,
  agencyName: string,
  sentAt: string | null = '2026-05-20T10:00:00+00:00',
): ConnectionRequestListItem {
  return {
    id,
    type: 'connection_request',
    attributes: {
      relationship_status: 'pending_request',
      invitation_sent_at: sentAt,
      agency_id: `01AGENCYULID${id}`,
      agency_name: agencyName,
    },
  }
}

let teardown: (() => void) | null = null

function makeBootstrap(
  applicationStatus: 'incomplete' | 'pending' | 'approved' | 'rejected',
): never {
  return {
    data: {
      id: '01',
      type: 'creators',
      attributes: {
        display_name: 'Alessia',
        bio: null,
        country_code: 'IT',
        region: null,
        primary_language: 'it',
        secondary_languages: null,
        categories: null,
        avatar_path: null,
        cover_path: null,
        avatar_url: null,
        cover_url: null,
        verification_level: 'unverified',
        application_status: applicationStatus,
        tier: null,
        kyc_status: applicationStatus === 'approved' ? 'verified' : 'none',
        kyc_verified_at: null,
        tax_profile_complete: applicationStatus !== 'incomplete',
        payout_method_set: applicationStatus !== 'incomplete',
        has_signed_master_contract: applicationStatus !== 'incomplete',
        click_through_accepted_at: null,
        social_accounts: [],
        portfolio: [],
        profile_completeness_score: applicationStatus === 'incomplete' ? 60 : 100,
        submitted_at: applicationStatus === 'incomplete' ? null : '2026-05-14T00:00:00+00:00',
        approved_at: applicationStatus === 'approved' ? '2026-05-15T00:00:00+00:00' : null,
        rejection_reason:
          applicationStatus === 'rejected' ? 'Portfolio links were unreachable.' : null,
        rejected_at: applicationStatus === 'rejected' ? '2026-05-15T00:00:00+00:00' : null,
        created_at: '2026-05-14T00:00:00+00:00',
        updated_at: '2026-05-14T00:00:00+00:00',
      },
      wizard: {
        next_step: 'review',
        is_submitted: applicationStatus !== 'incomplete',
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: true,
          creator_payout_method_enabled: true,
          contract_signing_enabled: true,
        },
      },
    },
  } as never
}

beforeEach(() => {
  vi.clearAllMocks()
  // Default: an empty inbox so approved-branch mounts that don't care about
  // requests don't trip on an unmocked list() (the catch would swallow it,
  // but a clean default keeps the other assertions honest).
  vi.mocked(connectionRequestsApi.list).mockResolvedValue({ data: [] })
  // The approved-branch campaign-invitation teaser (D-10) fetches assignments.
  vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({ data: [] })
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('CreatorDashboardPage', () => {
  it('renders the pending banner when application_status=pending', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap('pending'))

    const { wrapper, unmount } = await mountAuthPage(CreatorDashboardPage, {
      initialRoute: { path: '/creator/dashboard' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="dashboard-banner-pending"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-submitted-at"]').exists()).toBe(true)
  })

  it('renders the approved banner when application_status=approved', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap('approved'))

    const { wrapper, unmount } = await mountAuthPage(CreatorDashboardPage, {
      initialRoute: { path: '/creator/dashboard' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="dashboard-banner-approved"]').exists()).toBe(true)
  })

  it('renders the rejected banner when application_status=rejected', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap('rejected'))

    const { wrapper, unmount } = await mountAuthPage(CreatorDashboardPage, {
      initialRoute: { path: '/creator/dashboard' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="dashboard-banner-rejected"]').exists()).toBe(true)
  })

  // Cluster 5 (D-c3-1): the rejection reason now reaches the creator via
  // the creator-facing `attributes` block, so the banner renders it.
  it('renders the rejection reason from creator attributes when rejected', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap('rejected'))

    const { wrapper, unmount } = await mountAuthPage(CreatorDashboardPage, {
      initialRoute: { path: '/creator/dashboard' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const reason = wrapper.find('[data-testid="dashboard-rejection-reason"]')
    expect(reason.exists()).toBe(true)
    expect(reason.text()).toContain('Portfolio links were unreachable.')
  })

  // Cluster 6 (D-c3-9): the "Update & resubmit" control calls reopen and
  // routes the creator back into the wizard's welcome-back entry.
  it('reopens the application and navigates into the wizard on resubmit', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap('rejected'))
    vi.mocked(onboardingApi.reopen).mockResolvedValue(makeBootstrap('incomplete'))

    const { wrapper, router, unmount } = await mountAuthPage(CreatorDashboardPage, {
      initialRoute: { path: '/creator/dashboard' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const push = vi.spyOn(router, 'push').mockResolvedValue(undefined)

    const button = wrapper.find('[data-testid="dashboard-resubmit"]')
    expect(button.exists()).toBe(true)
    await button.trigger('click')
    await flushPromises()

    expect(onboardingApi.reopen).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({ name: 'onboarding.welcome-back' })
  })

  it('renders the incomplete banner when application_status=incomplete', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap('incomplete'))

    const { wrapper, unmount } = await mountAuthPage(CreatorDashboardPage, {
      initialRoute: { path: '/creator/dashboard' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="dashboard-banner-incomplete"]').exists()).toBe(true)
  })

  it('renders the completeness bar with the score from the store', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap('pending'))

    const { wrapper, unmount } = await mountAuthPage(CreatorDashboardPage, {
      initialRoute: { path: '/creator/dashboard' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="completeness-bar"]').exists()).toBe(true)
  })

  // ── Connection requests inbox (Sprint 6.6c) ──────────────────────────────

  async function mountApproved() {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap('approved'))
    const harness = await mountAuthPage(CreatorDashboardPage, {
      initialRoute: { path: '/creator/dashboard' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = harness.unmount
    await flushPromises()
    return harness
  }

  it('renders the requests section + rows from the api in the approved branch', async () => {
    vi.mocked(connectionRequestsApi.list).mockResolvedValue({
      data: [makeRequest('01R1', 'Alpha'), makeRequest('01R2', 'Bravo')],
    })

    const { wrapper } = await mountApproved()

    expect(connectionRequestsApi.list).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="dashboard-requests"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-requests-list"]').exists()).toBe(true)

    const row = wrapper.find('[data-testid="dashboard-request-01R1"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('Alpha')
    // The localized "Sent {date}" subtitle binds invitation_sent_at.
    expect(row.text()).toContain('Sent')
    expect(wrapper.find('[data-testid="dashboard-request-01R2"]').text()).toContain('Bravo')
  })

  it.each(['pending', 'rejected', 'incomplete'] as const)(
    'does NOT render the requests section (or fetch) in the %s branch',
    async (appStatus) => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap(appStatus))

      const { wrapper, unmount } = await mountAuthPage(CreatorDashboardPage, {
        initialRoute: { path: '/creator/dashboard' },
        beforeMount: async () => {
          await useOnboardingStore().bootstrap()
        },
      })
      teardown = unmount
      await flushPromises()

      expect(wrapper.find('[data-testid="dashboard-requests"]').exists()).toBe(false)
      expect(connectionRequestsApi.list).not.toHaveBeenCalled()
    },
  )

  it('accepts a request → POSTs the row id, re-fetches, drops the row + a connected toast naming the agency', async () => {
    vi.mocked(connectionRequestsApi.list)
      .mockResolvedValueOnce({ data: [makeRequest('01R1', 'Alpha')] })
      .mockResolvedValueOnce({ data: [] })
    vi.mocked(connectionRequestsApi.accept).mockResolvedValue({
      data: {
        id: '01R1',
        type: 'connection_request',
        attributes: { relationship_status: 'roster' },
      },
      meta: { code: 'connection.accepted' },
    })

    const { wrapper } = await mountApproved()

    await wrapper.find('[data-testid="dashboard-request-accept-01R1"]').trigger('click')
    await flushPromises()

    expect(connectionRequestsApi.accept).toHaveBeenCalledWith('01R1')
    // Re-fetch after the mutation (D-d7) — list called on mount + after accept.
    expect(connectionRequestsApi.list).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="dashboard-request-01R1"]').exists()).toBe(false)
    expect(document.body.textContent).toContain("You're now connected with Alpha.")
  })

  it('declines a request → POSTs the row id, re-fetches, drops the row + a declined toast', async () => {
    vi.mocked(connectionRequestsApi.list)
      .mockResolvedValueOnce({ data: [makeRequest('01R1', 'Alpha')] })
      .mockResolvedValueOnce({ data: [] })
    vi.mocked(connectionRequestsApi.decline).mockResolvedValue({
      data: {
        id: '01R1',
        type: 'connection_request',
        attributes: { relationship_status: 'declined' },
      },
      meta: { code: 'connection.declined' },
    })

    const { wrapper } = await mountApproved()

    await wrapper.find('[data-testid="dashboard-request-decline-01R1"]').trigger('click')
    await flushPromises()

    expect(connectionRequestsApi.decline).toHaveBeenCalledWith('01R1')
    expect(connectionRequestsApi.list).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="dashboard-request-01R1"]').exists()).toBe(false)
    expect(document.body.textContent).toContain('Request declined.')
  })

  it('renders the empty state when there are no requests', async () => {
    vi.mocked(connectionRequestsApi.list).mockResolvedValue({ data: [] })

    const { wrapper } = await mountApproved()

    expect(wrapper.find('[data-testid="dashboard-requests"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="dashboard-requests-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-requests-list"]').exists()).toBe(false)
  })

  it('surfaces an error toast (and keeps the row) when accept fails', async () => {
    vi.mocked(connectionRequestsApi.list).mockResolvedValue({
      data: [makeRequest('01R1', 'Alpha')],
    })
    vi.mocked(connectionRequestsApi.accept).mockRejectedValue(new Error('boom'))

    const { wrapper } = await mountApproved()

    await wrapper.find('[data-testid="dashboard-request-accept-01R1"]').trigger('click')
    await flushPromises()

    expect(document.body.textContent).toContain('Something went wrong. Please try again.')
    // No re-fetch on failure — the row stays so the creator can retry.
    expect(connectionRequestsApi.list).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="dashboard-request-01R1"]').exists()).toBe(true)
  })
})
