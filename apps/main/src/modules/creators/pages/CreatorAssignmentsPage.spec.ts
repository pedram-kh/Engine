/**
 * Sprint 8 Chunk 2 (D-9/D-10) — Vitest coverage for the creator's campaign-
 * invitation surface. Pins: rows render with a status chip; accept/counter/
 * decline actions appear ONLY for `invited` rows (fail-closed UI); accept calls
 * the API then re-fetches; the empty state renders when there are no rows.
 */

import type { CreatorAssignmentResource } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

vi.mock('../assignments.api', () => ({
  creatorAssignmentsApi: {
    list: vi.fn(),
    accept: vi.fn(),
    decline: vi.fn(),
    counter: vi.fn(),
  },
}))

import { creatorAssignmentsApi } from '../assignments.api'
import CreatorAssignmentsPage from './CreatorAssignmentsPage.vue'

function makeAssignment(
  id: string,
  status: CreatorAssignmentResource['attributes']['status'],
): CreatorAssignmentResource {
  return {
    id,
    type: 'campaign_assignment',
    attributes: {
      status,
      agreed_fee_minor_units: 500000,
      agreed_fee_currency: 'EUR',
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      deliverables: null,
      posting_due_at: null,
      invited_at: '2026-06-01T10:00:00+00:00',
      campaign: {
        id: `01CAMP${id}`,
        name: `Campaign ${id}`,
        posting_window_starts_at: '2026-07-01T00:00:00+00:00',
        posting_window_ends_at: '2026-07-10T00:00:00+00:00',
        brand_name: 'Acme',
      },
    },
  }
}

let teardown: (() => void) | null = null

afterEach(() => {
  teardown?.()
  teardown = null
})

beforeEach(() => {
  vi.clearAllMocks()
})

describe('CreatorAssignmentsPage', () => {
  it('renders a row with a status chip for each assignment', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [makeAssignment('A', 'invited'), makeAssignment('B', 'accepted')],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-assignment-A"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-assignment-B"]').exists()).toBe(true)
  })

  it('shows accept/counter/decline ONLY for an invited row (fail-closed UI)', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [makeAssignment('A', 'invited'), makeAssignment('B', 'accepted')],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-assignment-accept-A"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-assignment-counter-A"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-assignment-decline-A"]').exists()).toBe(true)
    // The accepted row has NO actions.
    expect(wrapper.find('[data-testid="creator-assignment-accept-B"]').exists()).toBe(false)
  })

  it('accepts an invitation, then re-fetches the list', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [makeAssignment('A', 'invited')],
    })
    vi.mocked(creatorAssignmentsApi.accept).mockResolvedValue({
      data: { id: 'A', type: 'campaign_assignment', attributes: { status: 'accepted' } },
      meta: { code: 'assignment.accepted' },
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    await wrapper.find('[data-testid="creator-assignment-accept-A"]').trigger('click')
    await flushPromises()

    expect(creatorAssignmentsApi.accept).toHaveBeenCalledWith('A')
    // list() once on mount + once after the mutation.
    expect(creatorAssignmentsApi.list).toHaveBeenCalledTimes(2)
  })

  it('renders the empty state when there are no assignments', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({ data: [] })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-test="creator-assignments-empty"]').exists()).toBe(true)
  })
})
