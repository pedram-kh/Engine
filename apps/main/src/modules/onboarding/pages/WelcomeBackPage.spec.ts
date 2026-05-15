import type { CreatorResource } from '@catalyst/api-client'
import type { MockInstance } from 'vitest'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import type { Router } from 'vue-router'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import { __resetWelcomeBackFlag } from '../internal/welcomeBackFlag'

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
import WelcomeBackPage from './WelcomeBackPage.vue'
import { useOnboardingStore } from '../stores/useOnboardingStore'

function makeCreator(
  overrides: Partial<CreatorResource['attributes']> = {},
  wizardOverrides: Partial<CreatorResource['wizard']> = {},
): CreatorResource {
  return {
    id: '01HQ',
    type: 'creators',
    attributes: {
      display_name: 'Test',
      bio: null,
      country_code: 'IT',
      region: null,
      primary_language: 'en',
      secondary_languages: null,
      categories: ['lifestyle'],
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
      profile_completeness_score: 35,
      submitted_at: null,
      approved_at: null,
      created_at: '2026-05-14T00:00:00+00:00',
      updated_at: '2026-05-14T15:00:00+00:00',
      ...overrides,
    },
    wizard: {
      next_step: 'social',
      is_submitted: false,
      steps: [
        { id: 'profile', is_complete: true },
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
      ...wizardOverrides,
    },
  }
}

let teardown: (() => void) | null = null

beforeEach(() => {
  vi.clearAllMocks()
  __resetWelcomeBackFlag()
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('WelcomeBackPage — Decision B (session-vs-fresh hybrid)', () => {
  it('renders the Welcome Back UI on first mount in the tab (fresh page load)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })
    const h = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
    })
    teardown = h.unmount

    const store = useOnboardingStore()
    await store.bootstrap()
    await flushPromises()

    expect(h.wrapper.find('[data-test="welcome-back-page"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="welcome-back-heading"]').text()).toBe('Welcome back')
  })

  it('renders the creator completeness score (35%)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })
    const h = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
    })
    teardown = h.unmount

    const store = useOnboardingStore()
    await store.bootstrap()
    await flushPromises()

    expect(h.wrapper.find('[data-test="welcome-back-completeness"]').text()).toBe('35%')
  })

  it('renders the next-step prompt for the creator (Step: Social accounts)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })
    const h = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
    })
    teardown = h.unmount

    const store = useOnboardingStore()
    await store.bootstrap()
    await flushPromises()

    expect(h.wrapper.find('[data-test="welcome-back-next-step-prompt"]').text()).toContain(
      'Social accounts',
    )
  })

  it('renders the all-complete prompt when nextStep is "review"', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
      data: makeCreator({}, { next_step: 'review' }),
    })
    const h = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
    })
    teardown = h.unmount

    const store = useOnboardingStore()
    await store.bootstrap()
    await flushPromises()

    expect(h.wrapper.find('[data-test="welcome-back-all-complete-prompt"]').exists()).toBe(true)
    expect(h.wrapper.find('[data-test="welcome-back-next-step-prompt"]').exists()).toBe(false)
  })

  it('auto-advances on the SECOND mount in the same tab (priorBootstrap=true)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })

    // First mount — flips the flag.
    const first = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
    })
    await useOnboardingStore().bootstrap()
    await flushPromises()
    expect(first.wrapper.find('[data-test="welcome-back-page"]').exists()).toBe(true)
    first.unmount()

    // Second mount — the flag is now `true`, so the page should
    // redirect away and not render the Welcome Back UI. Mirror the
    // real-app chronology by bootstrapping the (fresh-Pinia) store
    // BEFORE mounting — the requireOnboardingAccess guard does the
    // same in production via its awaited `bootstrap()` call. Spy on
    // router.replace inside beforeMount so the spy is installed
    // BEFORE onMounted fires the auto-advance navigation.
    let replaceSpy: MockInstance<Router['replace']> | null = null
    const second = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
      beforeMount: async ({ router }) => {
        await useOnboardingStore().bootstrap()
        replaceSpy = vi.spyOn(router, 'replace')
      },
    })
    teardown = second.unmount
    await flushPromises()

    expect(second.wrapper.find('[data-test="welcome-back-page"]').exists()).toBe(false)
    expect(replaceSpy).not.toBeNull()
    expect(replaceSpy!).toHaveBeenCalledWith({ name: 'onboarding.social' })
  })

  it('redirects submitted creators to /creator/dashboard (defensive guard fall-through)', async () => {
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
      data: makeCreator(
        { application_status: 'pending', submitted_at: '2026-05-13T10:00:00+00:00' },
        { is_submitted: true },
      ),
    })
    let replaceSpy: MockInstance<Router['replace']> | null = null
    const h = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
      beforeMount: async ({ router }) => {
        await useOnboardingStore().bootstrap()
        replaceSpy = vi.spyOn(router, 'replace')
      },
    })
    teardown = h.unmount
    await flushPromises()

    expect(h.wrapper.find('[data-test="welcome-back-page"]').exists()).toBe(false)
    expect(replaceSpy).not.toBeNull()
    expect(replaceSpy!).toHaveBeenCalledWith({ name: 'creator.dashboard' })
  })

  it('does NOT render the page UI before the store has bootstrapped (no creator data)', async () => {
    // Don't call bootstrap — creator stays null.
    const h = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
    })
    teardown = h.unmount

    // The page only renders when `shouldRender && creator` — both
    // conditions are false here.
    expect(h.wrapper.find('[data-test="welcome-back-page"]').exists()).toBe(false)
  })

  it('renders the subtitle with the approximated time-ago bucket (Refinement 6 contract)', async () => {
    // Refinement 6 (tech-debt entry "lastActivityAt approximated via
    // creator.updated_at"): defends the rendered subtitle's
    // {time_ago} interpolation against silent drift. Two-hours-ago
    // is the cleanest bucket — timeAgoCopy() emits the bare "2h"
    // label for the [1h, 24h) range, which is stable across test
    // reruns (the assertion does not depend on minute boundaries
    // the way "59 min" / "1h" transitions would).
    //
    // Break-revert (#40): temporarily remove "{time_ago}" from the
    // en/creator.json subtitle bundle entry → the substring "2h"
    // disappears from the rendered text → this assertion fails →
    // revert.
    const twoHoursAgoISO = new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString()
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue({
      data: makeCreator({ updated_at: twoHoursAgoISO }),
    })
    const h = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
    })
    teardown = h.unmount

    const store = useOnboardingStore()
    await store.bootstrap()
    await flushPromises()

    const subtitle = h.wrapper.find('[data-test="welcome-back-subtitle"]').text()
    expect(subtitle).toContain('2h')
    expect(subtitle).toContain('Pick up where you left off')
  })

  it('break-revert (#40): when the flag starts true, the fresh-load welcome-back UI is suppressed', async () => {
    // This is the regression spec the flag exists to catch. We
    // manually pre-flip the flag via re-mounting once; subsequent
    // mount renders the auto-advance path.
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue({ data: makeCreator() })

    // First mount flips the flag.
    const first = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
    })
    await useOnboardingStore().bootstrap()
    await flushPromises()
    first.unmount()

    // DO NOT call __resetWelcomeBackFlag() — simulate the bug
    // condition where the flag drifts to always-true.
    const second = await mountAuthPage(WelcomeBackPage, {
      initialRoute: { path: '/onboarding' },
    })
    teardown = second.unmount
    await useOnboardingStore().bootstrap()
    await flushPromises()

    // Fresh-load welcome-back UI is suppressed — auto-advance fires.
    expect(second.wrapper.find('[data-test="welcome-back-page"]').exists()).toBe(false)
  })
})
