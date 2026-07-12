/**
 * Sprint 8 Chunk 2 (D-9/D-10) — Vitest coverage for the creator's campaign-
 * invitation surface. Pins: rows render with a status chip; accept/decline
 * actions appear ONLY for `invited` rows (fail-closed UI); accept calls the API
 * then re-fetches; the empty state renders when there are no rows.
 *
 * Countering was removed (re-offer-after-decline chunk): the creator has NO
 * counter action — this spec pins its absence.
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
  },
}))

import { creatorAssignmentsApi } from '../assignments.api'
import CreatorAssignmentsPage from './CreatorAssignmentsPage.vue'

function makeAssignment(
  id: string,
  status: CreatorAssignmentResource['attributes']['status'],
  offer: Partial<CreatorAssignmentResource['attributes']> = {},
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
      ...offer,
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

  it('shows accept/decline ONLY for an invited row, and NEVER a counter action', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [makeAssignment('A', 'invited'), makeAssignment('B', 'accepted')],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-assignment-accept-A"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="creator-assignment-decline-A"]').exists()).toBe(true)
    // Countering is gone (re-offer-after-decline chunk) — the button + dialog
    // must not render for any row.
    expect(wrapper.find('[data-testid="creator-assignment-counter-A"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-assignment-counter-dialog"]').exists()).toBe(false)
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

  it('renders the invite-offer context — fee_per, description, and the attachment chip', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [
        makeAssignment('A', 'invited', {
          fee_per: 'per script',
          offer_description: 'One 60s UGC video, casual tone.',
          offer_attachment: {
            name: 'brief.pdf',
            mime_type: 'application/pdf',
            size_bytes: 2048,
            url: 'https://media.example/signed/brief.pdf',
          },
        }),
        // A plain row must render NONE of the offer extras.
        makeAssignment('B', 'invited'),
      ],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-test="creator-assignment-fee-per-A"]').text()).toContain(
      'per script',
    )
    expect(wrapper.find('[data-test="creator-assignment-description-A"]').text()).toContain(
      'One 60s UGC video, casual tone.',
    )
    const chip = wrapper.find('[data-test="creator-assignment-attachment-A"]')
    expect(chip.exists()).toBe(true)
    expect(chip.text()).toContain('brief.pdf')
    expect(chip.attributes('href')).toBe('https://media.example/signed/brief.pdf')

    expect(wrapper.find('[data-test="creator-assignment-fee-per-B"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="creator-assignment-description-B"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="creator-assignment-attachment-B"]').exists()).toBe(false)
  })

  it('renders the empty state when there are no assignments', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({ data: [] })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-test="creator-assignments-empty"]').exists()).toBe(true)
  })
})
