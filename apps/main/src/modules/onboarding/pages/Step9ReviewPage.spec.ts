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

// Backend substantive steps surfaced post-AH-003 (kyc/tax/payout hidden).
const BACKEND_STEP_IDS = ['profile', 'social', 'portfolio', 'contract'] as const
// UX review rows: social + portfolio collapse into the merged "connections" row.
const UX_ROW_IDS = ['profile', 'connections', 'contract'] as const

type BackendStepId = (typeof BACKEND_STEP_IDS)[number]

function makeBootstrap(opts: {
  allComplete: boolean
  score?: number
  isSubmitted?: boolean
  /** Per-step is_complete overrides; defaults all to `allComplete`. */
  stepCompleteness?: Partial<Record<BackendStepId, boolean>>
  /** Per-flag overrides; defaults all to true (on). */
  flagOverrides?: Partial<{
    kyc_verification_enabled: boolean
    creator_payout_method_enabled: boolean
    contract_signing_enabled: boolean
  }>
  /** ISO timestamp when the click-through agreement was accepted, or null. */
  clickThroughAcceptedAt?: string | null
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
        click_through_accepted_at: opts.clickThroughAcceptedAt ?? null,
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
        steps: BACKEND_STEP_IDS.map((id) => ({
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
  it('renders one row per VISIBLE UX step (profile, merged connections, contract)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap({ allComplete: false }))

    const { wrapper, unmount } = await mountAuthPage(Step9ReviewPage, {
      initialRoute: { path: '/onboarding/review' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    for (const id of UX_ROW_IDS) {
      expect(wrapper.find(`[data-testid="review-row-${id}"]`).exists()).toBe(true)
    }
    // Merged-away / hidden steps must not appear as their own rows.
    for (const id of ['social', 'portfolio', 'kyc', 'tax', 'payout']) {
      expect(wrapper.find(`[data-testid="review-row-${id}"]`).exists()).toBe(false)
    }
  })

  it('disables submit when any visible step is incomplete', async () => {
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

  it('enables submit when all visible steps complete and is_submitted=false', async () => {
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

  it('treats the merged connections row as incomplete unless BOTH social and portfolio are complete', async () => {
    // social complete, portfolio NOT → connections row is "Not started".
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({
        allComplete: true,
        stepCompleteness: { portfolio: false },
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

    expect(wrapper.find('[data-testid="review-row-status-connections"]').text()).toBe('Not started')
    expect(wrapper.find('[data-testid="review-submit"]').attributes('disabled')).toBeDefined()
  })

  it('renders the localized status label on every row (Completed / Not started)', async () => {
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
    // connections is "Not started" because social is incomplete.
    expect(wrapper.find('[data-testid="review-row-status-connections"]').text()).toBe('Not started')
    expect(wrapper.find('[data-testid="review-row-status-contract"]').text()).toBe('Completed')
    expect(wrapper.find('[data-testid="review-row-profile"]').attributes('data-status')).toBe(
      'not-started',
    )
  })

  it('renders the "Skipped" status when the contract step is complete via flag-OFF satisfaction', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({
        allComplete: true,
        flagOverrides: { contract_signing_enabled: false },
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

    expect(wrapper.find('[data-testid="review-row-status-contract"]').text()).toBe('Skipped')
    // The merged connections row has no flag-gated sub-step → stays Completed.
    expect(wrapper.find('[data-testid="review-row-status-connections"]').text()).toBe('Completed')
  })

  it('renders "Completed" for a flag-OFF contract once the agreement is accepted (AH-004)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({
        allComplete: true,
        flagOverrides: { contract_signing_enabled: false },
        clickThroughAcceptedAt: '2026-06-28T00:00:00+00:00',
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

    // The click-through acceptance is real work → "Completed", not "Skipped".
    expect(wrapper.find('[data-testid="review-row-status-contract"]').text()).toBe('Completed')
    expect(wrapper.find('[data-testid="review-row-contract"]').attributes('data-status')).toBe(
      'completed',
    )
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
    expect(blocker.text()).toContain('Profile basics')
    expect(blocker.text()).toContain('1')
    expect(wrapper.find('[data-testid="review-submit"]').attributes('disabled')).toBeDefined()
  })

  it('uses the plural form of the blocker when more than one step is incomplete', async () => {
    // profile incomplete + portfolio incomplete → 2 incomplete rows
    // (profile + the merged connections step).
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap({
        allComplete: true,
        stepCompleteness: { profile: false, portfolio: false },
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
    expect(blocker.text()).toContain('2')
    expect(blocker.text()).toContain('Profile basics')
    expect(blocker.text()).toContain('Social & portfolio')
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
