import { ApiError } from '@catalyst/api-client'
import type { CreatorResource } from '@catalyst/api-client'
import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    updateProfile: vi.fn(),
    connectSocial: vi.fn(),
    updateTax: vi.fn(),
    clickThroughAccept: vi.fn(),
    pollKycStatus: vi.fn(),
    pollPayoutStatus: vi.fn(),
    pollContractStatus: vi.fn(),
    submit: vi.fn(),
    uploadAvatar: vi.fn(),
    deleteAvatar: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from './useOnboardingStore'

function makeCreator(overrides: Partial<CreatorResource> = {}): CreatorResource {
  const baseAttributes: CreatorResource['attributes'] = {
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
    portfolio: [],
    profile_completeness_score: 0,
    submitted_at: null,
    approved_at: null,
    created_at: '2026-05-14T00:00:00+00:00',
    updated_at: '2026-05-14T00:00:00+00:00',
  }

  return {
    id: '01HQ',
    type: 'creators',
    attributes: { ...baseAttributes, ...(overrides.attributes ?? {}) },
    wizard: overrides.wizard ?? {
      next_step: 'profile',
      is_submitted: false,
      steps: [
        { id: 'profile', is_complete: false },
        { id: 'social', is_complete: false },
        { id: 'portfolio', is_complete: false },
        { id: 'kyc', is_complete: false },
        { id: 'tax', is_complete: false },
        { id: 'payout', is_complete: false },
        { id: 'contract', is_complete: false },
      ],
      weights: {},
      flags: {
        kyc_verification_enabled: false,
        creator_payout_method_enabled: false,
        contract_signing_enabled: false,
      },
    },
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('useOnboardingStore', () => {
  describe('initial state', () => {
    it('starts with creator=null, bootstrapStatus="idle", wasBootstrappedThisSession=false', () => {
      const store = useOnboardingStore()
      expect(store.creator).toBeNull()
      expect(store.bootstrapStatus).toBe('idle')
      expect(store.wasBootstrappedThisSession).toBe(false)
    })
  })

  describe('bootstrap()', () => {
    it('populates creator + transitions bootstrapStatus to "ready" on success', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })
      const store = useOnboardingStore()

      await store.bootstrap()

      expect(store.creator).not.toBeNull()
      expect(store.bootstrapStatus).toBe('ready')
      expect(onboardingApi.bootstrap).toHaveBeenCalledTimes(1)
    })

    it('flips wasBootstrappedThisSession to true on first successful bootstrap (Decision B signal)', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })
      const store = useOnboardingStore()
      expect(store.wasBootstrappedThisSession).toBe(false)

      await store.bootstrap()

      expect(store.wasBootstrappedThisSession).toBe(true)
    })

    it('dedupes concurrent calls into a single backend request', async () => {
      let resolveFn: (() => void) | null = null
      vi.mocked(onboardingApi.bootstrap).mockImplementation(
        () =>
          new Promise((resolve) => {
            resolveFn = () => resolve({ data: makeCreator() })
          }),
      )
      const store = useOnboardingStore()

      const p1 = store.bootstrap()
      const p2 = store.bootstrap()
      resolveFn!()
      await Promise.all([p1, p2])

      expect(onboardingApi.bootstrap).toHaveBeenCalledTimes(1)
    })

    it('transitions bootstrapStatus to "error" on a non-ApiError throw', async () => {
      vi.mocked(onboardingApi.bootstrap).mockRejectedValue(new Error('network broke'))
      const store = useOnboardingStore()

      await expect(store.bootstrap()).rejects.toThrow('network broke')
      expect(store.bootstrapStatus).toBe('error')
      expect(store.creator).toBeNull()
    })

    it('transitions bootstrapStatus to "error" on an ApiError too', async () => {
      vi.mocked(onboardingApi.bootstrap).mockRejectedValue(
        new ApiError({ status: 404, code: 'creator.not_found', message: 'no.' }),
      )
      const store = useOnboardingStore()

      await expect(store.bootstrap()).rejects.toBeInstanceOf(ApiError)
      expect(store.bootstrapStatus).toBe('error')
    })

    it('does NOT flip wasBootstrappedThisSession on bootstrap error (only success flips it)', async () => {
      vi.mocked(onboardingApi.bootstrap).mockRejectedValue(new Error('x'))
      const store = useOnboardingStore()

      await expect(store.bootstrap()).rejects.toThrow()
      expect(store.wasBootstrappedThisSession).toBe(false)
    })
  })

  describe('getters', () => {
    it('isBootstrapped reflects ready+creator state', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })
      const store = useOnboardingStore()
      expect(store.isBootstrapped).toBe(false)
      await store.bootstrap()
      expect(store.isBootstrapped).toBe(true)
    })

    it('nextStep mirrors creator.wizard.next_step', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
        data: makeCreator({
          wizard: {
            next_step: 'kyc',
            is_submitted: false,
            steps: [],
            weights: {},
            flags: {
              kyc_verification_enabled: true,
              creator_payout_method_enabled: false,
              contract_signing_enabled: false,
            },
          },
        }),
      })
      const store = useOnboardingStore()
      await store.bootstrap()
      expect(store.nextStep).toBe('kyc')
    })

    it('stepCompletion exposes per-step booleans by step id', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
        data: makeCreator({
          wizard: {
            next_step: 'kyc',
            is_submitted: false,
            steps: [
              { id: 'profile', is_complete: true },
              { id: 'social', is_complete: true },
              { id: 'portfolio', is_complete: true },
              { id: 'kyc', is_complete: false },
            ],
            weights: {},
            flags: {
              kyc_verification_enabled: true,
              creator_payout_method_enabled: false,
              contract_signing_enabled: false,
            },
          },
        }),
      })
      const store = useOnboardingStore()
      await store.bootstrap()

      expect(store.stepCompletion.profile).toBe(true)
      expect(store.stepCompletion.social).toBe(true)
      expect(store.stepCompletion.portfolio).toBe(true)
      expect(store.stepCompletion.kyc).toBe(false)
      expect(store.stepCompletion.tax).toBe(false)
    })

    it('flags exposes the wizard.flags block from the bootstrap', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
        data: makeCreator({
          wizard: {
            next_step: 'profile',
            is_submitted: false,
            steps: [],
            weights: {},
            flags: {
              kyc_verification_enabled: true,
              creator_payout_method_enabled: false,
              contract_signing_enabled: true,
            },
          },
        }),
      })
      const store = useOnboardingStore()
      await store.bootstrap()

      expect(store.flags).toEqual({
        kyc_verification_enabled: true,
        creator_payout_method_enabled: false,
        contract_signing_enabled: true,
      })
    })

    it('lastActivityAt mirrors creator.attributes.updated_at (Refinement 6)', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
        data: makeCreator({ attributes: { updated_at: '2026-05-13T15:00:00+00:00' } as never }),
      })
      const store = useOnboardingStore()
      await store.bootstrap()
      expect(store.lastActivityAt).toBe('2026-05-13T15:00:00+00:00')
    })

    it('completenessScore mirrors creator.attributes.profile_completeness_score', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
        data: makeCreator({ attributes: { profile_completeness_score: 42 } as never }),
      })
      const store = useOnboardingStore()
      await store.bootstrap()
      expect(store.completenessScore).toBe(42)
    })

    it('applicationStatus mirrors creator.attributes.application_status', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
        data: makeCreator({ attributes: { application_status: 'pending' } as never }),
      })
      const store = useOnboardingStore()
      await store.bootstrap()
      expect(store.applicationStatus).toBe('pending')
    })
  })

  describe('mutation actions refresh creator state', () => {
    beforeEach(async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })
    })

    it('updateProfile refreshes creator from response and clears its loading flag', async () => {
      const store = useOnboardingStore()
      vi.mocked(onboardingApi.updateProfile).mockResolvedValue({
        data: makeCreator({ attributes: { display_name: 'Updated' } as never }),
      })

      const promise = store.updateProfile({ display_name: 'Updated' })
      expect(store.isLoadingProfile).toBe(true)
      await promise
      expect(store.creator?.attributes.display_name).toBe('Updated')
      expect(store.isLoadingProfile).toBe(false)
    })

    it('connectSocial refreshes creator from response', async () => {
      const store = useOnboardingStore()
      vi.mocked(onboardingApi.connectSocial).mockResolvedValue({ data: makeCreator() })
      await store.connectSocial({
        platform: 'instagram',
        handle: '@x',
        profile_url: 'https://instagram.com/x',
      })
      expect(onboardingApi.connectSocial).toHaveBeenCalledOnce()
    })

    it('updateTax refreshes creator from response', async () => {
      const store = useOnboardingStore()
      vi.mocked(onboardingApi.updateTax).mockResolvedValue({ data: makeCreator() })
      await store.updateTax({
        tax_form_type: 'eu_self_employed',
        legal_name: 'X',
        tax_id: 'X',
        address: { country_code: 'IT', city: 'Rome', postal_code: '00100', street: 'A' },
      })
      expect(onboardingApi.updateTax).toHaveBeenCalledOnce()
    })

    it('clickThroughAcceptContract refreshes creator from response', async () => {
      const store = useOnboardingStore()
      vi.mocked(onboardingApi.clickThroughAccept).mockResolvedValue({ data: makeCreator() })
      await store.clickThroughAcceptContract()
      expect(onboardingApi.clickThroughAccept).toHaveBeenCalledOnce()
    })

    it('poll actions surface the saga status payload', async () => {
      const store = useOnboardingStore()
      vi.mocked(onboardingApi.pollKycStatus).mockResolvedValue({
        data: { status: 'pending', transitioned: false },
      })
      vi.mocked(onboardingApi.pollPayoutStatus).mockResolvedValue({
        data: { status: 'pending', transitioned: false },
      })
      vi.mocked(onboardingApi.pollContractStatus).mockResolvedValue({
        data: { status: 'pending', transitioned: false },
      })
      const kyc = await store.pollKycStatus()
      const payout = await store.pollPayoutStatus()
      const contract = await store.pollContractStatus()
      expect(kyc).toEqual({ status: 'pending', transitioned: false })
      expect(payout).toEqual({ status: 'pending', transitioned: false })
      expect(contract).toEqual({ status: 'pending', transitioned: false })
    })

    it('submit refreshes creator from response', async () => {
      const store = useOnboardingStore()
      vi.mocked(onboardingApi.submit).mockResolvedValue({ data: makeCreator() })
      await store.submit()
      expect(store.isSubmitting).toBe(false)
    })

    it('uploadAvatar refreshes creator from response', async () => {
      const store = useOnboardingStore()
      vi.mocked(onboardingApi.uploadAvatar).mockResolvedValue({ data: makeCreator() })
      const file = new File(['x'], 'a.jpg', { type: 'image/jpeg' })
      await store.uploadAvatar(file)
      expect(onboardingApi.uploadAvatar).toHaveBeenCalledWith(file)
      expect(store.isUploadingAvatar).toBe(false)
    })

    it('deleteAvatar refreshes creator from response', async () => {
      const store = useOnboardingStore()
      vi.mocked(onboardingApi.deleteAvatar).mockResolvedValue({ data: makeCreator() })
      await store.deleteAvatar()
      expect(onboardingApi.deleteAvatar).toHaveBeenCalledOnce()
    })

    it('action errors propagate AND clear loading flags via the finally block', async () => {
      const store = useOnboardingStore()
      vi.mocked(onboardingApi.updateProfile).mockRejectedValue(
        new ApiError({ status: 500, code: 'http.unknown_error', message: 'no.' }),
      )
      await expect(store.updateProfile({ display_name: 'X' })).rejects.toBeInstanceOf(ApiError)
      expect(store.isLoadingProfile).toBe(false)
    })
  })

  describe('reset()', () => {
    it('clears creator + bootstrapStatus but preserves wasBootstrappedThisSession (tab-scoped semantic)', async () => {
      vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })
      const store = useOnboardingStore()
      await store.bootstrap()
      expect(store.wasBootstrappedThisSession).toBe(true)

      store.reset()

      expect(store.creator).toBeNull()
      expect(store.bootstrapStatus).toBe('idle')
      // Intentionally NOT cleared — see docblock at the store.
      expect(store.wasBootstrappedThisSession).toBe(true)
    })
  })
})
