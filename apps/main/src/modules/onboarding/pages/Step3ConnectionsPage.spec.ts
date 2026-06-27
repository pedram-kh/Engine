import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@catalyst/api-client'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    connectSocial: vi.fn(),
    disconnectSocial: vi.fn(),
    deletePortfolioItem: vi.fn(),
    uploadPortfolioImage: vi.fn(),
    initiatePortfolioVideoUpload: vi.fn(),
    completePortfolioVideoUpload: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step3ConnectionsPage from './Step3ConnectionsPage.vue'

let teardown: (() => void) | null = null

type SocialSeed = ReadonlyArray<{
  platform: 'instagram' | 'tiktok' | 'youtube'
  handle: string
}>
type PortfolioSeed = ReadonlyArray<{
  id: string
  kind: 'image' | 'video' | 'link'
  title?: string | null
}>

function makeBootstrap(social: SocialSeed = [], portfolio: PortfolioSeed = []): never {
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
        application_status: 'incomplete',
        tier: null,
        kyc_status: 'none',
        kyc_verified_at: null,
        tax_profile_complete: false,
        payout_method_set: false,
        has_signed_master_contract: false,
        click_through_accepted_at: null,
        social_accounts: social.map((s) => ({
          platform: s.platform,
          handle: s.handle,
          profile_url: `https://${s.platform}.com/${s.handle}`,
          is_primary: false,
        })),
        portfolio: portfolio.map((p) => ({
          id: p.id,
          kind: p.kind,
          title: p.title ?? null,
          description: null,
          s3_path: `creators/01/portfolio/${p.id}.jpg`,
          view_url: `https://signed.example/creators/01/portfolio/${p.id}.jpg?sig=test`,
          external_url: null,
          thumbnail_path: null,
          thumbnail_view_url: null,
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
        next_step: 'social',
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

const ROUTE = { path: '/onboarding/connections' }

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap())
  vi.mocked(onboardingApi.connectSocial).mockResolvedValue(makeBootstrap())
  vi.mocked(onboardingApi.disconnectSocial).mockResolvedValue(makeBootstrap())
})

afterEach(() => {
  teardown?.()
  teardown = null
})

async function mount(): Promise<{
  wrapper: Awaited<ReturnType<typeof mountAuthPage>>['wrapper']
}> {
  const { wrapper, unmount } = await mountAuthPage(Step3ConnectionsPage, {
    initialRoute: ROUTE,
    beforeMount: async () => {
      await useOnboardingStore().bootstrap()
    },
  })
  teardown = unmount
  await flushPromises()
  return { wrapper }
}

describe('Step3ConnectionsPage (merged Social + Portfolio)', () => {
  it('renders both sub-sections under one step', async () => {
    const { wrapper } = await mount()

    expect(wrapper.find('[data-testid="step-connections"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="step-social-accounts"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="step-portfolio"]').exists()).toBe(true)
    // The two former per-step Continue buttons are replaced by one.
    expect(wrapper.find('[data-testid="social-advance"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="portfolio-advance"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="connections-advance"]').exists()).toBe(true)
  })

  it('renders three per-platform social forms', async () => {
    const { wrapper } = await mount()

    expect(wrapper.find('[data-testid="social-form-instagram"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-form-tiktok"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-form-youtube"]').exists()).toBe(true)
  })

  it('disables Continue when neither sub-section is satisfied', async () => {
    const { wrapper } = await mount()
    expect(wrapper.find('[data-testid="connections-advance"]').attributes('disabled')).toBeDefined()
  })

  it('disables Continue when only a social account is connected (no portfolio)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap([{ platform: 'instagram', handle: 'creator_x' }], []),
    )
    const { wrapper } = await mount()
    expect(wrapper.find('[data-testid="connections-advance"]').attributes('disabled')).toBeDefined()
  })

  it('disables Continue when only a portfolio item exists (no social account)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap([], [{ id: '01HQA', kind: 'image', title: 'Beach' }]),
    )
    const { wrapper } = await mount()
    expect(wrapper.find('[data-testid="connections-advance"]').attributes('disabled')).toBeDefined()
  })

  it('enables Continue once BOTH a social account and a portfolio item exist', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap(
        [{ platform: 'instagram', handle: 'creator_x' }],
        [{ id: '01HQA', kind: 'image', title: 'Beach' }],
      ),
    )
    const { wrapper } = await mount()
    expect(
      wrapper.find('[data-testid="connections-advance"]').attributes('disabled'),
    ).toBeUndefined()
  })

  it('labels the social CTA "Add" when unconnected and "Edit" when connected (D7)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap([{ platform: 'instagram', handle: 'creator_x' }], []),
    )
    const { wrapper } = await mount()

    // Instagram is connected → "Edit"; TikTok is not → "Add".
    expect(wrapper.find('[data-testid="social-connect-instagram"]').text()).toBe('Edit')
    expect(wrapper.find('[data-testid="social-connect-tiktok"]').text()).toBe('Add')
  })

  it('renders connected accounts via the shared SocialAccountList', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap(
        [
          { platform: 'instagram', handle: 'creator_x' },
          { platform: 'tiktok', handle: 'creator_y' },
        ],
        [],
      ),
    )
    const { wrapper } = await mount()

    expect(wrapper.find('[data-testid="social-account-list"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-account-row-instagram"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-account-row-tiktok"]').exists()).toBe(true)
  })

  it('binds per-platform per-field 422 messages to the offending row only', async () => {
    vi.mocked(onboardingApi.connectSocial).mockRejectedValue(
      ApiError.fromEnvelope(422, {
        errors: [
          {
            id: 'err-1',
            status: '422',
            code: 'validation.failed',
            title: 'The handle field is required.',
            detail: 'The handle field is required.',
            source: { pointer: '/data/attributes/handle' },
            meta: { field: 'handle', rule: 'Required' },
          },
        ],
        meta: { request_id: 'req-1' },
      }),
    )
    const { wrapper } = await mount()

    await wrapper.find('[data-testid="social-handle-tiktok"] input').setValue('badhandle')
    await wrapper.find('[data-testid="social-connect-tiktok"]').trigger('click')
    await flushPromises()

    const html = wrapper.html()
    expect(html).toContain('The handle field is required.')
    expect(html).not.toContain('validation.failed')

    const tiktokRow = wrapper.find('[data-testid="social-form-tiktok"]').html()
    const instagramRow = wrapper.find('[data-testid="social-form-instagram"]').html()
    expect(tiktokRow).toContain('The handle field is required.')
    expect(instagramRow).not.toContain('The handle field is required.')
  })

  it('prefills the input and shows a Remove control for a connected platform', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap([{ platform: 'instagram', handle: 'creator_x' }], []),
    )
    const { wrapper } = await mount()

    const input = wrapper.find('[data-testid="social-handle-instagram"] input')
      .element as HTMLInputElement
    expect(input.value).toBe('creator_x')
    expect(wrapper.find('[data-testid="social-remove-instagram"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-remove-tiktok"]').exists()).toBe(false)
  })

  it('disables the social CTA when the handle is invalid (e.g. a pasted URL)', async () => {
    const { wrapper } = await mount()

    await wrapper
      .find('[data-testid="social-handle-youtube"] input')
      .setValue('https://youtube.com/@ThePrimeTimeagen')
    await flushPromises()

    expect(
      wrapper.find('[data-testid="social-connect-youtube"]').attributes('disabled'),
    ).toBeDefined()
    expect(onboardingApi.connectSocial).not.toHaveBeenCalled()
  })

  it('strips a leading @ before sending the handle to connect', async () => {
    vi.mocked(onboardingApi.connectSocial).mockResolvedValue(
      makeBootstrap([{ platform: 'instagram', handle: 'Creator_X' }], []),
    )
    const { wrapper } = await mount()

    await wrapper.find('[data-testid="social-handle-instagram"] input').setValue('@Creator_X')
    await wrapper.find('[data-testid="social-connect-instagram"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.connectSocial).toHaveBeenCalledWith({
      platform: 'instagram',
      handle: 'Creator_X',
      profile_url: 'https://instagram.com/Creator_X',
    })
  })

  it('removes a connected account via disconnectSocial', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap([{ platform: 'instagram', handle: 'creator_x' }], []),
    )
    const { wrapper } = await mount()

    await wrapper.find('[data-testid="social-remove-instagram"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.disconnectSocial).toHaveBeenCalledWith('instagram')
  })

  it('renders persisted portfolio items via the shared PortfolioGallery', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap(
        [],
        [
          { id: '01HQA', kind: 'image', title: 'Beach shoot' },
          { id: '01HQB', kind: 'video', title: 'Reel cut' },
        ],
      ),
    )
    const { wrapper } = await mount()

    expect(wrapper.find('[data-testid="portfolio-gallery"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-gallery-item-01HQA"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="portfolio-gallery-item-01HQB"]').exists()).toBe(true)
  })

  it('binds the portfolio <img src> to the signed view_url, never the raw s3_path', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap([], [{ id: '01HQA', kind: 'image', title: 'Beach shoot' }]),
    )
    const { wrapper } = await mount()

    const img = wrapper.find('[data-testid="portfolio-gallery-item-01HQA"] img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe(
      'https://signed.example/creators/01/portfolio/01HQA.jpg?sig=test',
    )
  })

  it('calls deletePortfolioItem and re-bootstraps when remove is emitted', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrap([], [{ id: '01HQA', kind: 'image', title: 'Beach shoot' }]),
    )
    vi.mocked(onboardingApi.deletePortfolioItem).mockResolvedValue(undefined)
    const { wrapper } = await mount()

    await wrapper.find('[data-testid="portfolio-gallery-remove-01HQA"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.deletePortfolioItem).toHaveBeenCalledWith('01HQA')
    expect(onboardingApi.bootstrap).toHaveBeenCalledTimes(2)
  })
})
