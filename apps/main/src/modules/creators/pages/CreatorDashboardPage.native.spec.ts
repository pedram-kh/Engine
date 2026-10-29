/**
 * AH-100c — the approved dashboard inside the Capacitor creator app
 * (Figma frame 07).
 *
 * Pins: on native + approved the page draws the welcome header, the
 * "You're approved" banner, the Profile-strength card (with a row naming the
 * first incomplete step when there is one), the campaign-invitation card with
 * its "New" pill, and the requests count. Every native assertion has its web
 * polarity beside it, so the web dashboard is pinned unchanged; and a native
 * creator who is NOT approved keeps today's markup.
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../../onboarding/api/onboarding.api', () => ({
  onboardingApi: { bootstrap: vi.fn(), reopen: vi.fn() },
}))
vi.mock('../connectionRequests.api', () => ({
  connectionRequestsApi: { list: vi.fn(), accept: vi.fn(), decline: vi.fn() },
}))
vi.mock('../assignments.api', () => ({
  creatorAssignmentsApi: { list: vi.fn(), accept: vi.fn(), decline: vi.fn(), counter: vi.fn() },
}))

import { onboardingApi } from '../../onboarding/api/onboarding.api'
import { useOnboardingStore } from '../../onboarding/stores/useOnboardingStore'
import { connectionRequestsApi } from '../connectionRequests.api'
import { creatorAssignmentsApi } from '../assignments.api'
import CreatorDashboardPage from './CreatorDashboardPage.vue'

type Status = 'pending' | 'approved'
type Step = { id: string; is_complete: boolean }

function bootstrap(status: Status, score: number, steps: Step[] = []): never {
  return {
    data: {
      id: '01',
      type: 'creators',
      attributes: {
        display_name: 'Alessia',
        application_status: status,
        profile_completeness_score: score,
        submitted_at: '2026-05-14T00:00:00+00:00',
        rejection_reason: null,
      },
      wizard: { next_step: 'review', is_submitted: true, steps, weights: {}, flags: {} },
    },
  } as never
}

const ALL_DONE: Step[] = [
  { id: 'profile', is_complete: true },
  { id: 'social', is_complete: true },
  { id: 'portfolio', is_complete: true },
  { id: 'review', is_complete: true },
]

function invited(n: number): never {
  return {
    data: Array.from({ length: n }, (_, i) => ({
      id: `a${i}`,
      type: 'assignments',
      attributes: { status: 'invited' },
    })),
  } as never
}

let teardown: (() => void) | null = null

/** The shared harness stubs RouterLink empty; this one keeps the slot and the target. */
const RouterLinkStub = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [Object, String], default: null } },
  template: '<a class="router-link-stub"><slot /></a>',
})

async function mountDashboard(native: boolean, payload: never) {
  platform.native = native
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(payload)
  const mounted = await mountAuthPage(CreatorDashboardPage, {
    initialRoute: { path: '/creator/dashboard' },
    stubs: { RouterLink: RouterLinkStub },
    beforeMount: async () => {
      await useOnboardingStore().bootstrap()
    },
  })
  teardown = mounted.unmount
  await flushPromises()
  return mounted.wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(connectionRequestsApi.list).mockResolvedValue({ data: [] })
  vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({ data: [] })
})

afterEach(() => {
  teardown?.()
  teardown = null
  platform.native = false
})

describe('CreatorDashboardPage — native, approved (frame 07)', () => {
  it('draws the welcome header and the approved banner (not a v-alert)', async () => {
    const wrapper = await mountDashboard(true, bootstrap('approved', 100, ALL_DONE))

    expect(wrapper.find('h1').text()).toBe('Welcome, Alessia')
    expect(wrapper.find('header p').text()).toBe("Here's what needs your attention today.")

    const banner = wrapper.find('[data-testid="dashboard-banner-approved"]')
    expect(banner.classes()).toContain('dash-banner')
    expect(banner.classes()).not.toContain('v-alert')
    expect(banner.text()).toContain("You're approved")
    expect(banner.text()).toContain('Invitations and messages from agencies will appear here.')
    expect(banner.text()).not.toMatch(/soon|coming/i)
  })

  it('draws the strength card, not the web CompletenessBar', async () => {
    const wrapper = await mountDashboard(true, bootstrap('approved', 97, ALL_DONE))

    expect(wrapper.find('[data-testid="dashboard-strength"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="completeness-bar"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="dashboard-strength-value"]').text()).toBe('97%')
    expect(wrapper.find('.v-progress-linear').attributes('aria-valuenow')).toBe('97')
  })

  it('names the first incomplete step; the Edit-profile steps are links', async () => {
    const wrapper = await mountDashboard(
      true,
      bootstrap('approved', 80, [
        { id: 'profile', is_complete: true },
        { id: 'social', is_complete: false },
        { id: 'review', is_complete: true },
      ]),
    )

    const row = wrapper.find('[data-testid="dashboard-strength-next"]')
    expect(row.text()).toContain('To reach 100%, finish this step: Social accounts')
    expect(row.element.tagName).toBe('A')
    const link = wrapper
      .findAllComponents(RouterLinkStub)
      .find((w) => w.attributes('data-testid') === 'dashboard-strength-next')
    expect(link?.props('to')).toEqual({ name: 'creator.profile' })
  })

  it('renders a step the Edit profile page cannot finish as plain text, not a link', async () => {
    const wrapper = await mountDashboard(
      true,
      bootstrap('approved', 85, [
        { id: 'profile', is_complete: true },
        { id: 'kyc', is_complete: false },
        { id: 'review', is_complete: true },
      ]),
    )

    const row = wrapper.find('[data-testid="dashboard-strength-next"]')
    expect(row.text()).toContain('Identity verification')
    expect(row.element.tagName).toBe('P')
  })

  it('has no "reach 100%" row when nothing is incomplete (the review step is never named)', async () => {
    const wrapper = await mountDashboard(
      true,
      bootstrap('approved', 97, [
        { id: 'profile', is_complete: true },
        { id: 'review', is_complete: false },
      ]),
    )

    expect(wrapper.find('[data-testid="dashboard-strength-next"]').exists()).toBe(false)
  })

  it('draws the invitation card with a count title and the "New" pill', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue(invited(1))
    const wrapper = await mountDashboard(true, bootstrap('approved', 100, ALL_DONE))

    const card = wrapper.find('[data-testid="dashboard-assignments-teaser"]')
    expect(card.classes()).toContain('dash-invites')
    expect(card.text()).toContain('1 campaign invitation')
    expect(card.text()).toContain('Waiting for your response')
    expect(wrapper.find('[data-testid="dashboard-invitations-new"]').text()).toBe('New')
    expect(wrapper.find('[data-testid="dashboard-assignments-cta"]').exists()).toBe(true)
  })

  it('pluralises the invitation title', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue(invited(3))
    const wrapper = await mountDashboard(true, bootstrap('approved', 100, ALL_DONE))

    expect(wrapper.find('[data-testid="dashboard-assignments-teaser"]').text()).toContain(
      '3 campaign invitations',
    )
  })

  it('with no invitations: the card stays (the way in), without the pill', async () => {
    const wrapper = await mountDashboard(true, bootstrap('approved', 100, ALL_DONE))

    const card = wrapper.find('[data-testid="dashboard-assignments-teaser"]')
    expect(card.classes()).toContain('dash-invites')
    expect(card.text()).toContain('Campaign invitations')
    expect(card.text()).toContain('No invitations awaiting your response.')
    expect(wrapper.find('[data-testid="dashboard-invitations-new"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="dashboard-assignments-cta"]').exists()).toBe(true)
  })

  it('shows the pending-request count beside the "Connection requests" heading', async () => {
    const wrapper = await mountDashboard(true, bootstrap('approved', 100, ALL_DONE))
    expect(wrapper.find('[data-testid="dashboard-requests-count"]').text()).toBe('0')
  })
})

describe('CreatorDashboardPage — native, NOT approved', () => {
  it('keeps the existing markup: v-alert banner, CompletenessBar, "Your application"', async () => {
    const wrapper = await mountDashboard(true, bootstrap('pending', 100, ALL_DONE))

    expect(wrapper.find('h1').text()).toBe('Your application')
    expect(wrapper.find('[data-testid="dashboard-banner-pending"]').classes()).toContain('v-alert')
    expect(wrapper.find('[data-testid="completeness-bar"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-strength"]').exists()).toBe(false)
  })
})

describe('CreatorDashboardPage — web (unchanged)', () => {
  it('approved: v-alert banner, CompletenessBar, the original teaser, no native markup', async () => {
    const wrapper = await mountDashboard(false, bootstrap('approved', 97, ALL_DONE))

    expect(wrapper.find('h1').text()).toBe('Your application')
    expect(wrapper.find('header p').text()).toBe('Welcome, Alessia.')
    expect(wrapper.find('[data-testid="dashboard-banner-approved"]').classes()).toContain('v-alert')
    expect(wrapper.find('[data-testid="completeness-bar"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-assignments-teaser"]').classes()).toContain(
      'v-alert',
    )
    for (const native of [
      '.dash-banner',
      '.dash-strength',
      '.dash-invites',
      '[data-testid="dashboard-requests-count"]',
    ]) {
      expect(wrapper.find(native).exists(), native).toBe(false)
    }
  })
})
