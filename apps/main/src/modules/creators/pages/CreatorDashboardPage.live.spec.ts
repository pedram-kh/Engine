/**
 * AH-100d (item 8, layer b) — the under-review page goes live inside the
 * Capacitor creator app.
 *
 * Pins: while a native creator sits on the pending dashboard the status is
 * re-polled; approval flips the page to the approved branch with no reload and
 * loads that branch's data; the poll stops on its own after the flip and on
 * unmount. Web polarity beside each: the web dashboard never polls. And the
 * other path to the same flip — the resume listener refreshing the store — is
 * pinned to land on the same page.
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'
import { APPLICATION_STATUS_POLL_MS } from '../../onboarding/composables/useApplicationStatusPoll'

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

function payload(status: 'pending' | 'approved'): never {
  return {
    data: {
      id: '01',
      type: 'creators',
      attributes: {
        display_name: 'Alessia',
        application_status: status,
        profile_completeness_score: 100,
        submitted_at: '2026-05-14T00:00:00+00:00',
        rejection_reason: null,
      },
      wizard: { next_step: 'review', is_submitted: true, steps: [], weights: {}, flags: {} },
    },
  } as never
}

const RouterLinkStub = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [Object, String], default: null } },
  template: '<a class="router-link-stub"><slot /></a>',
})

let teardown: (() => void) | null = null

async function mountPending(native: boolean) {
  platform.native = native
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(payload('pending'))
  const mounted = await mountAuthPage(CreatorDashboardPage, {
    initialRoute: { path: '/creator/dashboard' },
    stubs: { RouterLink: RouterLinkStub },
    beforeMount: async () => {
      await useOnboardingStore().bootstrap()
    },
  })
  teardown = mounted.unmount
  await flushPromises()
  vi.mocked(onboardingApi.bootstrap).mockClear()
  return mounted.wrapper
}

async function tick(times = 1) {
  for (let i = 0; i < times; i += 1) {
    await vi.advanceTimersByTimeAsync(APPLICATION_STATUS_POLL_MS)
  }
  await flushPromises()
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
  vi.clearAllMocks()
  vi.mocked(connectionRequestsApi.list).mockResolvedValue({ data: [] })
  vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({ data: [] })
})

afterEach(() => {
  teardown?.()
  teardown = null
  platform.native = false
  vi.useRealTimers()
})

describe('CreatorDashboardPage — the under-review page goes live (native)', () => {
  it('re-polls the status, and approval flips the page to the approved branch with no reload', async () => {
    const wrapper = await mountPending(true)
    expect(wrapper.find('[data-testid="dashboard-banner-pending"]').exists()).toBe(true)
    expect(connectionRequestsApi.list).not.toHaveBeenCalled()

    // Still pending on the first tick — nothing changes, the poll keeps going.
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(payload('pending'))
    await tick()
    expect(onboardingApi.bootstrap).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="dashboard-banner-pending"]').exists()).toBe(true)

    // The admin approves; the next tick sees it.
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(payload('approved'))
    await tick()

    expect(wrapper.find('[data-testid="dashboard-banner-pending"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="dashboard-banner-approved"]').exists()).toBe(true)
    expect(wrapper.find('h1').text()).toBe('Welcome, Alessia')
    // The approved branch's data was loaded on the flip (onMounted never did).
    expect(connectionRequestsApi.list).toHaveBeenCalledTimes(1)
    expect(creatorAssignmentsApi.list).toHaveBeenCalledTimes(1)
  })

  it('stops polling by itself once the status leaves pending', async () => {
    await mountPending(true)
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(payload('approved'))
    await tick()
    expect(onboardingApi.bootstrap).toHaveBeenCalledTimes(1)

    await tick(3)

    expect(onboardingApi.bootstrap).toHaveBeenCalledTimes(1)
  })

  it('stops polling when the page unmounts', async () => {
    await mountPending(true)
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(payload('pending'))
    await tick()
    expect(onboardingApi.bootstrap).toHaveBeenCalledTimes(1)

    teardown?.()
    teardown = null
    await tick(3)

    expect(onboardingApi.bootstrap).toHaveBeenCalledTimes(1)
  })

  it('lands on the same approved page when the resume listener refreshes the store instead', async () => {
    const wrapper = await mountPending(true)
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(payload('approved'))

    // What the app-resume listener ends up calling.
    await useOnboardingStore().refresh()
    await flushPromises()

    expect(wrapper.find('[data-testid="dashboard-banner-approved"]').exists()).toBe(true)
    expect(connectionRequestsApi.list).toHaveBeenCalledTimes(1)
  })
})

describe('CreatorDashboardPage — the web dashboard never polls', () => {
  it('makes no status request on its own, however long the page stays open', async () => {
    const wrapper = await mountPending(false)
    vi.mocked(onboardingApi.bootstrap).mockResolvedValue(payload('approved'))

    await tick(5)

    expect(onboardingApi.bootstrap).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="dashboard-banner-pending"]').exists()).toBe(true)
  })
})
