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

function makeBootstrap(opts: {
  allComplete: boolean
  score?: number
  isSubmitted?: boolean
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
        steps: STEP_IDS.map((id) => ({ id, is_complete: opts.allComplete })),
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
})
