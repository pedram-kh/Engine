import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../../onboarding/api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    updateProfile: vi.fn(),
    uploadAvatar: vi.fn(),
    deleteAvatar: vi.fn(),
    connectSocial: vi.fn(),
    disconnectSocial: vi.fn(),
    deletePortfolioItem: vi.fn(),
    uploadPortfolioImage: vi.fn(),
    initiatePortfolioVideoUpload: vi.fn(),
    completePortfolioVideoUpload: vi.fn(),
  },
}))

import { onboardingApi } from '../../onboarding/api/onboarding.api'
import { useOnboardingStore } from '../../onboarding/stores/useOnboardingStore'
import CreatorProfilePage from './CreatorProfilePage.vue'

let teardown: (() => void) | null = null

type ApplicationStatus = 'incomplete' | 'pending' | 'approved' | 'rejected'

interface SeedOptions {
  status?: ApplicationStatus
  /** Override profile-basics fields; pass nulls to drop below the floor. */
  attrs?: Record<string, unknown>
  /** Number of connected social accounts to seed. */
  socials?: number
  /** Number of portfolio items to seed. */
  portfolio?: number
}

/**
 * A complete, floor-MEETING creator by default (display_name + country +
 * primary_language + ≥1 category + avatar). Individual tests drop fields via
 * `attrs` or counts via `socials`/`portfolio`.
 */
function makeBootstrap(options: SeedOptions = {}): never {
  const { status = 'pending', attrs = {}, socials = 1, portfolio = 1 } = options
  return {
    data: {
      id: '01',
      type: 'creators',
      attributes: {
        display_name: 'Existing Name',
        bio: null,
        country_code: 'IT',
        region: null,
        phone: null,
        whatsapp: null,
        address_street: null,
        address_postal_code: null,
        primary_language: 'en',
        secondary_languages: [],
        categories: ['lifestyle'],
        avatar_path: 'creators/01/avatar.jpg',
        cover_path: null,
        avatar_url: 'https://signed.example/creators/01/avatar.jpg?sig=test',
        cover_url: null,
        verification_level: 'unverified',
        application_status: status,
        tier: null,
        kyc_status: 'none',
        kyc_verified_at: null,
        tax_profile_complete: false,
        payout_method_set: false,
        has_signed_master_contract: false,
        click_through_accepted_at: null,
        social_accounts: Array.from({ length: socials }, (_, i) => ({
          platform: ['instagram', 'tiktok', 'youtube'][i] ?? 'instagram',
          handle: `creator_${i}`,
          profile_url: `https://example.com/creator_${i}`,
          is_primary: false,
        })),
        portfolio: Array.from({ length: portfolio }, (_, i) => ({
          id: `01HQ${i}`,
          kind: 'image',
          title: `Item ${i}`,
          description: null,
          s3_path: `creators/01/portfolio/${i}.jpg`,
          view_url: `https://signed.example/creators/01/portfolio/${i}.jpg?sig=test`,
          external_url: null,
          thumbnail_path: null,
          thumbnail_view_url: null,
          mime_type: 'image/jpeg',
          size_bytes: 1024,
          duration_seconds: null,
          position: i,
        })),
        profile_completeness_score: 100,
        submitted_at: '2026-05-20T00:00:00+00:00',
        approved_at: null,
        created_at: '2026-05-14T00:00:00+00:00',
        updated_at: '2026-05-14T00:00:00+00:00',
        ...attrs,
      },
      wizard: {
        next_step: 'review',
        is_submitted: true,
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

const ROUTE = { path: '/creator/profile' }

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap())
  vi.mocked(onboardingApi.updateProfile).mockResolvedValue(makeBootstrap())
})

afterEach(() => {
  teardown?.()
  teardown = null
})

async function mount(seed: SeedOptions = {}): Promise<{
  wrapper: Awaited<ReturnType<typeof mountAuthPage>>['wrapper']
}> {
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrap(seed))
  const { wrapper, unmount } = await mountAuthPage(CreatorProfilePage, {
    initialRoute: ROUTE,
    beforeMount: async () => {
      await useOnboardingStore().bootstrap()
    },
  })
  teardown = unmount
  await flushPromises()
  return { wrapper }
}

describe('CreatorProfilePage — completeness floor', () => {
  it('renders the two sections for a post-submission creator', async () => {
    const { wrapper } = await mount({ status: 'pending' })
    expect(wrapper.find('[data-testid="creator-profile"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-profile-basics"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-profile-connections"]').exists()).toBe(true)
  })

  // (a) pending/rejected — clear display_name → blocked.
  it('hard-blocks the save for a pending creator when display_name is cleared', async () => {
    const { wrapper } = await mount({ status: 'pending' })

    await wrapper.find('[data-testid="profile-display-name"] input').setValue('')
    await flushPromises()

    const saveBtn = wrapper.find('[data-testid="creator-profile-save"]')
    expect(saveBtn.classes()).toContain('v-btn--disabled')
    expect(wrapper.find('[data-testid="creator-profile-incomplete-hint"]').exists()).toBe(true)

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(onboardingApi.updateProfile).not.toHaveBeenCalled()
  })

  it('hard-blocks the save for a rejected creator when display_name is cleared', async () => {
    const { wrapper } = await mount({ status: 'rejected' })

    await wrapper.find('[data-testid="profile-display-name"] input').setValue('')
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-profile-save"]').classes()).toContain(
      'v-btn--disabled',
    )
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(onboardingApi.updateProfile).not.toHaveBeenCalled()
  })

  // (b) pending/rejected — delete avatar → save blocked (avatar_path null is a
  // floor field; the gate reacts to the store, mirroring AvatarUploadDrop's
  // delete which sets avatar_path null on the refreshed creator).
  it('hard-blocks the save after the avatar is deleted (avatar_path null)', async () => {
    const { wrapper } = await mount({ status: 'pending' })
    // Acquire the store AFTER mount so it binds to the pinia the component uses.
    const store = useOnboardingStore()

    // Floor met initially → save enabled.
    expect(wrapper.find('[data-testid="creator-profile-save"]').classes()).not.toContain(
      'v-btn--disabled',
    )

    // Simulate the avatar-delete result landing in the store.
    store.creator!.attributes.avatar_path = null
    store.creator!.attributes.avatar_url = null
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-profile-save"]').classes()).toContain(
      'v-btn--disabled',
    )
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(onboardingApi.updateProfile).not.toHaveBeenCalled()
  })

  // (c) approved — clear a field → save PROCEEDS but the warning renders.
  it('soft-warns (never blocks) for an approved creator who clears a field', async () => {
    const { wrapper } = await mount({ status: 'approved' })

    await wrapper.find('[data-testid="profile-display-name"] input').setValue('')
    await flushPromises()

    // Warning renders; no hard-block hint; save NOT disabled.
    expect(wrapper.find('[data-testid="creator-profile-approved-warning"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-profile-incomplete-hint"]').exists()).toBe(false)
    const saveBtn = wrapper.find('[data-testid="creator-profile-save"]')
    expect(saveBtn.classes()).not.toContain('v-btn--disabled')

    // The save genuinely proceeds (not a disguised block).
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(onboardingApi.updateProfile).toHaveBeenCalledTimes(1)
  })

  it('shows no approved warning while an approved profile still meets the floor', async () => {
    const { wrapper } = await mount({ status: 'approved' })
    expect(wrapper.find('[data-testid="creator-profile-approved-warning"]').exists()).toBe(false)
  })

  // (d) last-social removal → warning renders, removal proceeds.
  it('warns when the last social account is removed, without blocking', async () => {
    vi.mocked(onboardingApi.disconnectSocial).mockResolvedValue(
      makeBootstrap({ status: 'approved', socials: 0 }),
    )
    const { wrapper } = await mount({ status: 'approved', socials: 1, portfolio: 1 })

    // No warning while one social remains.
    expect(wrapper.find('[data-testid="creator-profile-social-warning"]').exists()).toBe(false)

    await wrapper.find('[data-testid="social-remove-instagram"]').trigger('click')
    await flushPromises()

    // Removal proceeded AND the page now warns.
    expect(onboardingApi.disconnectSocial).toHaveBeenCalledWith('instagram')
    expect(wrapper.find('[data-testid="creator-profile-social-warning"]').exists()).toBe(true)
  })

  it('warns when the last portfolio item is removed, without blocking', async () => {
    vi.mocked(onboardingApi.deletePortfolioItem).mockResolvedValue(undefined)
    vi.mocked(onboardingApi.bootstrap)
      .mockResolvedValueOnce(makeBootstrap({ status: 'approved', socials: 1, portfolio: 1 }))
      .mockResolvedValue(makeBootstrap({ status: 'approved', socials: 1, portfolio: 0 }))
    const { wrapper } = await mountAuthPage(CreatorProfilePage, {
      initialRoute: ROUTE,
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    }).then(async (h) => {
      teardown = h.unmount
      await flushPromises()
      return h
    })

    expect(wrapper.find('[data-testid="creator-profile-portfolio-warning"]').exists()).toBe(false)

    await wrapper.find('[data-testid="portfolio-gallery-remove-01HQ0"]').trigger('click')
    await flushPromises()

    expect(onboardingApi.deletePortfolioItem).toHaveBeenCalledWith('01HQ0')
    expect(wrapper.find('[data-testid="creator-profile-portfolio-warning"]').exists()).toBe(true)
  })
})
