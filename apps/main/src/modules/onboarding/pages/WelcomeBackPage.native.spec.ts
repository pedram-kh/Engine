/**
 * AH-100d — Welcome back as the creator app draws it (Figma frame 01): the
 * greeting by first name, the step list inside the card (done / up next / to
 * do), and the closing hint. Web polarity beside it: the web keeps its sentence
 * and its card, none of the list.
 */

import type { CreatorResource } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import { resetWelcomeBackFlag } from '../internal/welcomeBackFlag'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../api/onboarding.api', () => ({ onboardingApi: { bootstrap: vi.fn() } }))

import { onboardingApi } from '../api/onboarding.api'
import { useOnboardingStore } from '../stores/useOnboardingStore'
import WelcomeBackPage from './WelcomeBackPage.vue'

function creator(opts: {
  name?: string
  next: 'profile' | 'social' | 'contract' | 'review'
  done: string[]
  score?: number
  /** `contract_signing_enabled` — false (the default) is the flag-OFF path. */
  contractSigning?: boolean
  /** `click_through_accepted_at` is stamped. */
  accepted?: boolean
}): { data: CreatorResource } {
  const ids = ['profile', 'social', 'portfolio', 'kyc', 'tax', 'payout', 'contract']
  return {
    data: {
      id: '01HQ',
      type: 'creators',
      attributes: {
        display_name: opts.name ?? 'Sofia Marin',
        application_status: 'incomplete',
        profile_completeness_score: opts.score ?? 21,
        submitted_at: null,
        updated_at: '2026-05-14T15:00:00+00:00',
        click_through_accepted_at: opts.accepted ? '2026-05-14T15:00:00+00:00' : null,
        social_accounts: [],
        portfolio: [],
      },
      wizard: {
        next_step: opts.next,
        is_submitted: false,
        steps: ids.map((id) => ({ id, is_complete: opts.done.includes(id) })),
        weights: {},
        flags: {
          kyc_verification_enabled: false,
          creator_payout_method_enabled: false,
          contract_signing_enabled: opts.contractSigning ?? false,
        },
      },
    },
  } as never
}

let teardown: (() => void) | null = null

async function mountIt(native: boolean, data: { data: CreatorResource }) {
  platform.native = native
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(data as never)
  const h = await mountAuthPage(WelcomeBackPage, { initialRoute: { path: '/onboarding' } })
  teardown = h.unmount
  await useOnboardingStore().bootstrap()
  await flushPromises()
  return h.wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  resetWelcomeBackFlag()
})

afterEach(() => {
  teardown?.()
  teardown = null
  platform.native = false
})

const states = (w: Awaited<ReturnType<typeof mountIt>>) =>
  w
    .findAll('.welcome-back__step')
    .map((r) => [
      r.attributes('data-testid'),
      r.classes().find((c) => /--(done|next|todo)$/.test(c)),
    ])

describe('welcome back — native (frame 01)', () => {
  it('greets the creator by first name and says everything is saved', async () => {
    const w = await mountIt(true, creator({ next: 'profile', done: [] }))

    expect(w.find('[data-test="welcome-back-heading"]').text()).toBe('Welcome back, Sofia')
    expect(w.find('[data-test="welcome-back-subtitle"]').text()).toBe(
      'Pick up where you left off. Everything you entered has been saved.',
    )
  })

  it('lists the steps: account done, the next one marked "Up next", the rest to do', async () => {
    const w = await mountIt(true, creator({ next: 'profile', done: [] }))

    expect(states(w)).toEqual([
      ['welcome-back-step-account_created', 'welcome-back__step--done'],
      ['welcome-back-step-profile', 'welcome-back__step--next'],
      ['welcome-back-step-connections', 'welcome-back__step--todo'],
      ['welcome-back-step-contract', 'welcome-back__step--todo'],
      ['welcome-back-step-review', 'welcome-back__step--todo'],
    ])
    expect(w.find('[data-testid="welcome-back-step-profile"] .welcome-back__pill').text()).toBe(
      'Up next',
    )
    expect(w.findAll('.welcome-back__pill')).toHaveLength(1)
    expect(w.find('[data-testid="welcome-back-step-profile"]').text()).toContain('Profile basics')
  })

  it('moves the checks and the pill along as steps are completed', async () => {
    const w = await mountIt(
      true,
      creator({ next: 'contract', done: ['profile', 'social', 'portfolio'], score: 70 }),
    )

    expect(states(w).map(([, s]) => s)).toEqual([
      'welcome-back__step--done',
      'welcome-back__step--done',
      'welcome-back__step--done',
      'welcome-back__step--next',
      'welcome-back__step--todo',
    ])
  })

  it('keeps the merged Social & portfolio step open until both halves are done', async () => {
    const w = await mountIt(true, creator({ next: 'social', done: ['profile', 'social'] }))

    expect(states(w)[2]).toEqual(['welcome-back-step-connections', 'welcome-back__step--next'])
  })

  it('replaces the "You\'re on Step" sentence with the list, and adds the closing hint', async () => {
    const w = await mountIt(true, creator({ next: 'profile', done: [] }))

    expect(w.find('[data-test="welcome-back-next-step-prompt"]').exists()).toBe(false)
    expect(w.find('[data-testid="welcome-back-hint"]').text()).toBe(
      'You can go back and edit any earlier step at any time.',
    )
    expect(w.find('[data-test="welcome-back-continue-btn"]').text()).toBe(
      'Continue to Profile basics',
    )
  })

  it('falls back to the plain greeting when the creator has no name', async () => {
    const w = await mountIt(true, creator({ name: '   ', next: 'profile', done: [] }))

    expect(w.find('[data-test="welcome-back-heading"]').text()).toBe('Welcome back')
  })

  it('keeps "Let\'s get started" for a creator who has done nothing yet', async () => {
    const w = await mountIt(true, creator({ next: 'profile', done: [], score: 0 }))

    expect(w.find('[data-test="welcome-back-heading"]').text()).toBe("Let's get started")
  })
})

/**
 * The real flag-OFF payload: the backend reports the contract step
 * `is_complete: true` on EVERY account (a pseudo-completion, nothing was
 * signed). Pedram's second re-walk saw "Master agreement" ticked on a brand-new
 * 0% account, with Profile basics "Up next".
 */
describe('welcome back — the Master agreement tick is earned (AH-100d)', () => {
  const contractRow = (w: Awaited<ReturnType<typeof mountIt>>) =>
    w.find('[data-testid="welcome-back-step-contract"]').classes()

  it('a fresh account is unchecked even though the flag-OFF backend reports it complete', async () => {
    const w = await mountIt(
      true,
      creator({ next: 'profile', done: ['contract'], score: 5, accepted: false }),
    )

    expect(contractRow(w)).toContain('welcome-back__step--todo')
    expect(contractRow(w)).not.toContain('welcome-back__step--done')
    expect(states(w)[1]).toEqual(['welcome-back-step-profile', 'welcome-back__step--next'])
  })

  it('is checked once the creator has accepted the click-through', async () => {
    const w = await mountIt(
      true,
      creator({
        next: 'review',
        done: ['profile', 'social', 'portfolio', 'contract'],
        accepted: true,
      }),
    )

    expect(contractRow(w)).toContain('welcome-back__step--done')
  })

  it('with signing ON, a signed contract is checked', async () => {
    const w = await mountIt(
      true,
      creator({ next: 'review', done: ['profile', 'contract'], contractSigning: true }),
    )

    expect(contractRow(w)).toContain('welcome-back__step--done')
  })

  it('with signing ON, an unsigned contract is not', async () => {
    const w = await mountIt(
      true,
      creator({ next: 'contract', done: ['profile'], contractSigning: true }),
    )

    expect(contractRow(w)).toContain('welcome-back__step--next')
    expect(contractRow(w)).not.toContain('welcome-back__step--done')
  })
})

describe('welcome back — web is unchanged', () => {
  it('keeps the plain heading, the sentence and the card, with no step list or hint', async () => {
    const w = await mountIt(false, creator({ next: 'profile', done: [] }))

    expect(w.find('[data-test="welcome-back-heading"]').text()).toBe('Welcome back')
    expect(w.find('[data-test="welcome-back-subtitle"]').text()).toContain(
      'Pick up where you left off.',
    )
    expect(w.find('[data-test="welcome-back-subtitle"]').text()).not.toContain('has been saved')
    expect(w.find('[data-test="welcome-back-next-step-prompt"]').exists()).toBe(true)
    expect(w.find('.welcome-back__steps').exists()).toBe(false)
    expect(w.find('[data-testid="welcome-back-hint"]').exists()).toBe(false)
  })
})
