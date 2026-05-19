import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    submit: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step9ReviewPage from './Step9ReviewPage.vue'

let teardown: (() => void) | null = null

const STEP_IDS = ['profile', 'social', 'portfolio', 'kyc', 'tax', 'payout', 'contract'] as const

type StepId = (typeof STEP_IDS)[number]

function makeBootstrap(opts: {
  allComplete: boolean
  score?: number
  isSubmitted?: boolean
  /** Per-step is_complete overrides; defaults all to `allComplete`. */
  stepCompleteness?: Partial<Record<StepId, boolean>>
  /** Per-flag overrides; defaults all to true (on). */
  flagOverrides?: Partial<{
    kyc_verification_enabled: boolean
    creator_payout_method_enabled: boolean
    contract_signing_enabled: boolean
  }>
}): never {
  return {
    data: {
      id: '01',
      type: 'creators',
      attributes: {
        display_name: 'Test',
        bio: null,
        country_code: null,
        region: null,
        primary_language: null,
        secondary_languages: null,
        categories: null,
        avatar_path: null,
        cover_path: null,
        avatar_url: null,
        cover_url: null,
        verification_level: 'unverified',
        application_status: opts.isSubmitted ? 'pending' : 'incomplete',
        tier: null,
        kyc_status: opts.allComplete ? 'verified' : 'none',
        kyc_verified_at: null,
        tax_profile_complete: opts.allComplete,
        payout_method_set: opts.allComplete,
        has_signed_master_contract: opts.allComplete,
        click_through_accepted_at: null,
        social_accounts: [],
        portfolio: [],
        profile_completeness_score: opts.score ?? (opts.allComplete ? 100 : 60),
        submitted_at: opts.isSubmitted ? '2026-05-14T00:00:00+00:00' : null,
        approved_at: null,
        created_at: '2026-05-14T00:00:00+00:00',
        updated_at: '2026-05-14T00:00:00+00:00',
      },
      wizard: {
        next_step: 'review',
        is_submitted: opts.isSubmitted ?? false,
        steps: STEP_IDS.map((id) => ({
          id,
          is_complete: opts.stepCompleteness?.[id] ?? opts.allComplete,
        })),
        weights: {},
        flags: {
          kyc_verification_enabled: opts.flagOverrides?.kyc_verification_enabled ?? true,
          creator_payout_method_enabled: opts.flagOverrides?.creator_payout_method_enabled ?? true,
          contract_signing_enabled: opts.flagOverrides?.contract_signing_enabled ?? true,
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

describe('Step9ReviewPage', () => {
  it('renders one row per wizard step', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap({ allComplete: false }))

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    for (const id of STEP_IDS) {
      expect(wrapper.find(`[data-testid="review-row-${id}"]`).exists()).toBe(true)
    }
  })

  it('disables submit when any step is incomplete', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap({ allComplete: false }))

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="review-submit"]').attributes('disabled')).toBeDefined()
  })

  it('enables submit when all steps complete and is_submitted=false', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap({ allComplete: true }))

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="review-submit"]').attributes('disabled')).toBeUndefined()
  })

  it('calls submit() when the submit button is clicked', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap({ allComplete: true }))
    vi.mocked(onboardingApi.submit).mockResolvedValue(
      makeBootstrap({ allComplete: true, isSubmitted: true }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    await wrapper.find('[data-testid="review-submit"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.submit).toHaveBeenCalledTimes(1)
  })

  // -------------------------------------------------------------------------
  // Sprint 3 stabilization (May 19, 2026): the page disabled Submit but
  // gave the creator zero on-page explanation of which step was blocking.
  // These specs pin the new row-status + inline-blocker UX so the next
  // regression turns this UI muffled-failure mode into a test failure
  // instead.
  // -------------------------------------------------------------------------
  it('renders the localized status label on every row (Completed / Not started)', async () => {
    // Mixed state: 5 complete, 2 incomplete. Mirrors what the user saw
    // in the wild — most steps green, profile blocking submit.
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({
        allComplete: true,
        stepCompleteness: { profile: false, social: false },
      }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="review-row-status-profile"]').text()).toBe('Not started')
    expect(wrapper.find('[data-testid="review-row-status-social"]').text()).toBe('Not started')
    expect(wrapper.find('[data-testid="review-row-status-portfolio"]').text()).toBe('Completed')
    expect(wrapper.find('[data-testid="review-row-status-tax"]').text()).toBe('Completed')
    // data-status attribute carries the machine-readable form for tests
    // that want to assert without depending on i18n text.
    expect(wrapper.find('[data-testid="review-row-profile"]').attributes('data-status')).toBe(
      'not-started',
    )
  })

  it('renders the "Skipped" status when a flag-gated step is complete via flag-OFF satisfaction', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({
        allComplete: true,
        flagOverrides: { kyc_verification_enabled: false, creator_payout_method_enabled: false },
      }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="review-row-status-kyc"]').text()).toBe('Skipped')
    expect(wrapper.find('[data-testid="review-row-status-payout"]').text()).toBe('Skipped')
    // Vendor-cleared steps stay "Completed" even when their flag-on
    // path is live. Contract here was completed AND its flag is on.
    expect(wrapper.find('[data-testid="review-row-status-contract"]').text()).toBe('Completed')
  })

  it('shows the inline blocker listing every incomplete step name when submit is disabled', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({
        allComplete: true,
        stepCompleteness: { profile: false },
      }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const blocker = wrapper.find('[data-testid="review-incomplete-blocker"]')
    expect(blocker.exists()).toBe(true)
    // The blocker should name the actual incomplete step ("Profile
    // basics") so the creator knows WHERE to click "Edit". The exact
    // count word is locale-specific; pin only the singular case here.
    expect(blocker.text()).toContain('Profile basics')
    expect(blocker.text()).toContain('1')
    expect(wrapper.find('[data-testid="review-submit"]').attributes('disabled')).toBeDefined()
  })

  it('uses the plural form of the blocker when more than one step is incomplete', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({
        allComplete: true,
        stepCompleteness: { profile: false, tax: false, social: false },
      }),
    )

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const blocker = wrapper.find('[data-testid="review-incomplete-blocker"]')
    expect(blocker.exists()).toBe(true)
    expect(blocker.text()).toContain('3')
    expect(blocker.text()).toContain('Profile basics')
    expect(blocker.text()).toContain('Social accounts')
    expect(blocker.text()).toContain('Tax information')
  })

  it('hides the inline blocker when every step is complete', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap({ allComplete: true }))

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="review-incomplete-blocker"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="review-submit"]').attributes('disabled')).toBeUndefined()
  })
})
