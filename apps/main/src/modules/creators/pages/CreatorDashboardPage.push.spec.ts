/**
 * AH-102 (C4.1) — who the dashboard offers the push soft-ask to: pending and
 * approved creators, nobody else, on either platform shape. The sheet itself is
 * specced in `PushSoftAskSheet.spec.ts`; here it is replaced by a probe that
 * records the `eligible` flag the page passes it. Separate from the dashboard's
 * existing specs, which stay unedited.
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

const probe = vi.hoisted(() => ({ eligible: [] as boolean[] }))

vi.mock('@/modules/notifications/components/PushSoftAskSheet.vue', async () => {
  const { defineComponent: define } = await import('vue')
  return {
    default: define({
      name: 'PushSoftAskSheet',
      props: { eligible: { type: Boolean, required: true } },
      setup(props) {
        probe.eligible.push(props.eligible)
        return () => null
      },
    }),
  }
})

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

function bootstrap(status: string): never {
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

async function mountWith(status: string) {
  vi.mocked(onboardingApi.bootstrap).mockResolvedValue(bootstrap(status))
  const mounted = await mountAuthPage(CreatorDashboardPage, {
    initialRoute: { path: '/creator/dashboard' },
    stubs: { RouterLink: RouterLinkStub },
    beforeMount: async () => {
      await useOnboardingStore().bootstrap()
    },
  })
  teardown = mounted.unmount
  await flushPromises()
}

beforeEach(() => {
  vi.clearAllMocks()
  probe.eligible.length = 0
  vi.mocked(connectionRequestsApi.list).mockResolvedValue({ data: [] })
  vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({ data: [] })
})

afterEach(() => {
  teardown?.()
  teardown = null
})

describe('CreatorDashboardPage — push soft-ask eligibility', () => {
  it.each([
    ['pending', true],
    ['approved', true],
    ['rejected', false],
    ['incomplete', false],
  ] as const)('status %s → eligible=%s', async (status, eligible) => {
    await mountWith(status)

    expect(probe.eligible.at(-1)).toBe(eligible)
  })

  it('mounts the sheet exactly once on the page', async () => {
    await mountWith('approved')

    expect(probe.eligible.filter((v) => v === true)).toHaveLength(1)
  })
})
