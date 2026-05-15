import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    deletePortfolioItem: vi.fn(),
    uploadPortfolioImage: vi.fn(),
    initiatePortfolioVideoUpload: vi.fn(),
    completePortfolioVideoUpload: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step4PortfolioPage from './Step4PortfolioPage.vue'

let teardown: (() => void) | null = null

function makeBootstrapWith(
  portfolio: ReadonlyArray<{ id: string; kind: 'image' | 'video' | 'link'; title?: string | null }>,
): never {
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
        application_status: 'incomplete',
        tier: null,
        kyc_status: 'none',
        kyc_verified_at: null,
        tax_profile_complete: false,
        payout_method_set: false,
        has_signed_master_contract: false,
        click_through_accepted_at: null,
        social_accounts: [],
        portfolio: portfolio.map((p) => ({
          id: p.id,
          kind: p.kind,
          title: p.title ?? null,
          description: null,
          s3_path: `creators/01/portfolio/${p.id}.jpg`,
          external_url: null,
          thumbnail_path: null,
          mime_type: p.kind === 'image' ? 'image/jpeg' : 'video/mp4',
          size_bytes: 1024,
          duration_seconds: null,
          position: 0,
        })),
        profile_completeness_score: 0,
        submitted_at: null,
        approved_at: null,
        created_at: '2026-05-14T00:00:00+00:00',
        updated_at: '2026-05-14T00:00:00+00:00',
      },
      wizard: {
        next_step: 'portfolio',
        is_submitted: false,
        steps: [],
        weights: {},
        flags: {
          kyc_verification_enabled: false,
          creator_payout_method_enabled: false,
          contract_signing_enabled: false,
        },
      },
    },
  } as never
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrapWith([]))
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('Step4PortfolioPage', () => {
  it('renders the upload grid and the empty gallery placeholder', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step4PortfolioPage, {
      initialRoute: { path: '/onboarding/portfolio' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="step-portfolio"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-drop-zone"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-gallery-empty"]').exists()).toBe(true)
  })

  it('disables the advance button when the gallery is empty', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step4PortfolioPage, {
      initialRoute: { path: '/onboarding/portfolio' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const advance = wrapper.find('[data-testid="portfolio-advance"]')
    expect(advance.attributes('disabled')).toBeDefined()
  })

  it('renders persisted items via the shared PortfolioGallery and enables advance', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrapWith([
        { id: '01HQA', kind: 'image', title: 'Beach shoot' },
        { id: '01HQB', kind: 'video', title: 'Reel cut' },
      ]),
    )

    const { wrapper, unmount } = await mountAuthPage(Step4PortfolioPage, {
      initialRoute: { path: '/onboarding/portfolio' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="portfolio-gallery"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-gallery-item-01HQA"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-gallery-item-01HQB"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-advance"]').attributes('disabled')).toBeUndefined()
  })

  it('calls deletePortfolioItem and re-bootstraps when remove is emitted', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrapWith([{ id: '01HQA', kind: 'image', title: 'Beach shoot' }]),
    )
    vi.mocked(onboardingApi.deletePortfolioItem).mockResolvedValue(undefined)

    const { wrapper, unmount } = await mountAuthPage(Step4PortfolioPage, {
      initialRoute: { path: '/onboarding/portfolio' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    await wrapper.find('[data-testid="portfolio-gallery-remove-01HQA"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.deletePortfolioItem).toHaveBeenCalledWith('01HQA')
    expect(onboardingApi.bootstrap).toHaveBeenCalledTimes(2)
  })
})
