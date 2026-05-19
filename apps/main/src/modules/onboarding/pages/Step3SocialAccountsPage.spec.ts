import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@catalyst/api-client'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../api/onboarding.api', () => ({
  onboardingApi: {
    bootstrap: vi.fn(),
    connectSocial: vi.fn(),
  },
}))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import Step3SocialAccountsPage from './Step3SocialAccountsPage.vue'

let teardown: (() => void) | null = null

function makeBootstrapWith(
  social: ReadonlyArray<{ platform: 'instagram' | 'tiktok' | 'youtube'; handle: string }>,
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
        portfolio: [],
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

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(makeBootstrapWith([]))
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('Step3SocialAccountsPage', () => {
  it('renders three per-platform forms', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step3SocialAccountsPage, {
      initialRoute: { path: '/onboarding/social' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="social-form-instagram"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-form-tiktok"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-form-youtube"]').exists()).toBe(true)
  })

  it('disables the advance button when no accounts are connected', async () => {
    const { wrapper, unmount } = await mountAuthPage(Step3SocialAccountsPage, {
      initialRoute: { path: '/onboarding/social' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const advance = wrapper.find('[data-testid="social-advance"]')
    expect(advance.attributes('disabled')).toBeDefined()
  })

  it('enables the advance button when at least one social account is connected', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrapWith([{ platform: 'instagram', handle: 'creator_x' }]),
    )
    const { wrapper, unmount } = await mountAuthPage(Step3SocialAccountsPage, {
      initialRoute: { path: '/onboarding/social' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    const advance = wrapper.find('[data-testid="social-advance"]')
    expect(advance.attributes('disabled')).toBeUndefined()
  })

  it('renders connected accounts via the shared SocialAccountList', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(
      makeBootstrapWith([
        { platform: 'instagram', handle: 'creator_x' },
        { platform: 'tiktok', handle: 'creator_y' },
      ]),
    )
    const { wrapper, unmount } = await mountAuthPage(Step3SocialAccountsPage, {
      initialRoute: { path: '/onboarding/social' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="social-account-list"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-account-row-instagram"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="social-account-row-tiktok"]').exists()).toBe(true)
  })

  // Sprint 3 stabilization (May 19, 2026): per-platform per-field 422
  // rendering. The previous shortcut `draft.errorKey = error.code`
  // rendered "validation.failed" as a literal string when the backend
  // rejected an invalid handle. Now per-platform extractFieldErrors,
  // with `platform` / `profile_url` violations folded onto the handle
  // input (the creator can only act on the handle).
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

    const { wrapper, unmount } = await mountAuthPage(Step3SocialAccountsPage, {
      initialRoute: { path: '/onboarding/social' },
      beforeMount: async () => {
        await useOnboardingStore().bootstrap()
      },
    })
    teardown = unmount
    await flushPromises()

    // Type something into TikTok so the Connect button is enabled, then click it.
    await wrapper.find('[data-testid="social-handle-tiktok"] input').setValue('badhandle')
    await wrapper.find('[data-testid="social-connect-tiktok"]').trigger('click')
    await flushPromises()

    const html = wrapper.html()
    expect(html).toContain('The handle field is required.')
    // Critically: the literal envelope code must NOT leak as a key.
    expect(html).not.toContain('validation.failed')

    // The error appears on the TikTok row only, not on Instagram / YouTube.
    const tiktokRow = wrapper.find('[data-testid="social-form-tiktok"]').html()
    const instagramRow = wrapper.find('[data-testid="social-form-instagram"]').html()
    expect(tiktokRow).toContain('The handle field is required.')
    expect(instagramRow).not.toContain('The handle field is required.')
  })
})
