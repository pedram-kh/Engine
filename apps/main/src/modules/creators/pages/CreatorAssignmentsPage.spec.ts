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
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
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

  it('renders the offer description through RichBrief — markdown becomes real HTML, not literal asterisks (AH-081)', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [
        makeAssignment('A', 'invited', {
          offer_description: 'Please shoot **outdoors**, in *natural* light.',
        }),
      ],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    const description = wrapper.find('[data-test="creator-assignment-description-A"]')
    expect(description.find('strong').text()).toBe('outdoors')
    expect(description.find('em').text()).toBe('natural')
  })

  it('renders the empty state when there are no assignments', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({ data: [] })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-test="creator-assignments-empty"]').exists()).toBe(true)
  })
})

// ── AH-107 — the campaign's state on the assignment card (web path) ─────────
//
// Q3: every status shows on an assignment card, Active included; draft reads
// "Upcoming" (Q1). Display only (Q2): the actions below the chip do not change.

function withCampaignStatus(
  id: string,
  assignmentStatus: CreatorAssignmentResource['attributes']['status'],
  campaignStatus: string | undefined,
): CreatorAssignmentResource {
  const base = makeAssignment(id, assignmentStatus)
  const campaign = base.attributes.campaign!
  return {
    ...base,
    attributes: {
      ...base.attributes,
      campaign: {
        ...campaign,
        ...(campaignStatus === undefined ? {} : { status: campaignStatus }),
      } as never,
    },
  }
}

describe('CreatorAssignmentsPage — the campaign status chip (AH-107, web)', () => {
  it.each([
    ['draft', 'Upcoming'],
    ['active', 'Active'],
    ['paused', 'Paused'],
    ['completed', 'Completed'],
    ['cancelled', 'Cancelled'],
  ])('shows the %s campaign as "%s"', async (campaignStatus, label) => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [withCampaignStatus('A', 'accepted', campaignStatus)],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    const chip = wrapper.find('[data-testid="creator-assignment-campaign-status-A"]')
    expect(chip.text()).toBe(label)
    expect(chip.classes()).toContain('campaign-status-chip')
  })

  it('shows no campaign chip when the backend sends no status (older backend)', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [withCampaignStatus('A', 'accepted', undefined)],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-assignment-campaign-status-A"]').exists()).toBe(
      false,
    )
  })

  it('is a separate chip from the assignment status, which is still there', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [withCampaignStatus('A', 'invited', 'paused')],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-assignment-status-A"]').text()).toBe('Invited')
    expect(wrapper.find('[data-testid="creator-assignment-campaign-status-A"]').text()).toBe(
      'Paused',
    )
  })

  it('DISPLAY ONLY (Q2): a paused / cancelled campaign still offers accept and decline', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [
        withCampaignStatus('A', 'invited', 'paused'),
        withCampaignStatus('B', 'invited', 'cancelled'),
      ],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    for (const id of ['A', 'B']) {
      expect(wrapper.find(`[data-testid="creator-assignment-accept-${id}"]`).exists()).toBe(true)
      expect(wrapper.find(`[data-testid="creator-assignment-decline-${id}"]`).exists()).toBe(true)
    }
  })

  it('keeps the campaign chip OUT of the title row that holds the assignment status (AH-106 lesson)', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [withCampaignStatus('A', 'invited', 'paused')],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    const title = wrapper.find('.assignment__title')
    expect(title.find('[data-testid="creator-assignment-status-A"]').exists()).toBe(true)
    expect(title.find('[data-testid="creator-assignment-campaign-status-A"]').exists()).toBe(false)
  })
})

// ── AH-107 — the assignment-status chip is never squeezed (AH-106's defect, second chip) ──
//
// Found in the copy-review screenshots: a long campaign title left the status chip
// in the title row too little room and Vuetify's `min-width: 0; overflow: hidden`
// chip clipped its own label ("Contrac…"). A `toHaveText` matches the DOM and cannot
// see a clipped label, so the guard is the pinning class plus the rule that gives it
// teeth. The real-Chromium measurement is in the AH-107 harness and the E2E leg.

describe('CreatorAssignmentsPage — the assignment-status chip keeps its width (AH-107)', () => {
  it('carries the width-pinning class on the web card', async () => {
    vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({
      data: [withCampaignStatus('A', 'contracted', 'active')],
    })

    const { wrapper, unmount } = await mountAuthPage(CreatorAssignmentsPage)
    teardown = unmount
    await flushPromises()

    expect(wrapper.find('[data-testid="creator-assignment-status-A"]').classes()).toContain(
      'assignment__status-chip',
    )
  })

  it('the class stops the chip from shrinking', () => {
    const source = readFileSync(resolve(__dirname, 'CreatorAssignmentsPage.vue'), 'utf8')
    const rule = /\.assignment__status-chip\s*\{([^}]*)\}/.exec(source)

    expect(rule, 'no .assignment__status-chip rule in the page style').not.toBeNull()
    expect(rule?.[1]).toMatch(/flex:\s*0\s+0\s+auto/)
  })
})
