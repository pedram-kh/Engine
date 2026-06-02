import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../../onboarding/api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    reopen: vi.fn(),
  },
}))

import { onboardingApi } from '../../onboarding/api/onboarding.api'
import { useOnboardingStore } from '../../onboarding/stores/useOnboardingStore'
import CreatorDashboardPage from './CreatorDashboardPage.vue'

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
})
