/**
 * AH-100d — the invitation card as the creator app draws it (Figma frame 10):
 * a brand-initials tile, title, brand and status pill; a Fee / Dates /
 * Deliverables panel built from the payload; the brand's note; and Accept
 * invitation / View brief / Decline. Web polarity beside it: the web keeps its
 * one-line summary and its own labels.
 *
 * Deliberately NOT drawn (no payload field / no backend rule): the footer line
 * "Invitations expire 7 days after they are sent."
 */

import type { CreatorAssignmentResource } from '@catalyst/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mountAuthPage } from '../../../../tests/unit/helpers/mountAuthPage'

const platform = vi.hoisted(() => ({ native: false }))
vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => platform.native,
}))

vi.mock('../assignments.api', () => ({
  creatorAssignmentsApi: { list: vi.fn(), accept: vi.fn(), decline: vi.fn() },
}))

import { creatorAssignmentsApi } from '../assignments.api'
import CreatorAssignmentsPage from './CreatorAssignmentsPage.vue'

function makeAssignment(
  id: string,
  status: CreatorAssignmentResource['attributes']['status'],
  attrs: Partial<CreatorAssignmentResource['attributes']> = {},
  brand: string | null = 'Glow Labs',
): CreatorAssignmentResource {
  return {
    id,
    type: 'campaign_assignment',
    attributes: {
      status,
      agreed_fee_minor_units: 2500,
      agreed_fee_currency: 'EUR',
      fee_per: 'per video',
      offer_description: 'Looking for a calm, natural style.',
      offer_attachment: null,
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      deliverables: ['1 video', '15 to 30 seconds'],
      posting_due_at: null,
      invited_at: '2026-06-01T10:00:00+00:00',
      campaign: {
        id: `01CAMP${id}`,
        name: 'Summer skincare UGC',
        posting_window_starts_at: '2026-09-02T00:00:00+00:00',
        posting_window_ends_at: '2026-09-16T00:00:00+00:00',
        brand_name: brand,
      },
      ...attrs,
    },
  }
}

let teardown: (() => void) | null = null

async function mountIt(native: boolean, rows: CreatorAssignmentResource[]) {
  platform.native = native
  vi.mocked(creatorAssignmentsApi.list).mockResolvedValue({ data: rows })
  const mounted = await mountAuthPage(CreatorAssignmentsPage)
  teardown = mounted.unmount
  await flushPromises()
  return mounted.wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(() => {
  teardown?.()
  teardown = null
  platform.native = false
})

describe('invitations — native card (frame 10)', () => {
  it('draws the brand-initials tile, the title, the brand and the status pill', async () => {
    const wrapper = await mountIt(true, [makeAssignment('A', 'invited')])
    const card = wrapper.find('[data-testid="creator-assignment-A"]')

    expect(card.find('.assignment-card__tile').text()).toBe('GL')
    expect(card.find('.assignment__name').text()).toBe('Summer skincare UGC')
    expect(card.find('.assignment-card__brand').text()).toBe('Glow Labs')
    const pill = card.find('[data-testid="creator-assignment-status-A"]')
    expect(pill.text()).toBe('Invited')
    expect(pill.attributes('data-status')).toBe('invited')
  })

  it('falls back to the campaign name for the tile when there is no brand', async () => {
    const wrapper = await mountIt(true, [makeAssignment('A', 'invited', {}, null)])

    expect(wrapper.find('.assignment-card__tile').text()).toBe('SS')
    expect(wrapper.find('.assignment-card__brand').exists()).toBe(false)
  })

  it('builds the Fee / Dates / Deliverables panel from the payload', async () => {
    const wrapper = await mountIt(true, [makeAssignment('A', 'invited')])

    expect(wrapper.find('[data-testid="creator-assignment-fee-A"]').text()).toContain('Fee')
    expect(wrapper.find('[data-testid="creator-assignment-fee-A"] dd').text()).toBe(
      '€25.00 per video',
    )
    expect(wrapper.find('[data-testid="creator-assignment-dates-A"] dt').text()).toBe('Dates')
    expect(wrapper.find('[data-testid="creator-assignment-dates-A"] dd').text()).toContain('–')
    expect(wrapper.find('[data-testid="creator-assignment-deliverables-A"] dt').text()).toBe(
      'Deliverables',
    )
    expect(wrapper.find('[data-testid="creator-assignment-deliverables-A"] dd').text()).toBe(
      '1 video, 15 to 30 seconds',
    )
  })

  it('leaves the Deliverables row out when the payload has none', async () => {
    const wrapper = await mountIt(true, [
      makeAssignment('A', 'invited', { deliverables: null }),
      makeAssignment('B', 'invited', { deliverables: [] }),
    ])

    expect(wrapper.find('[data-testid="creator-assignment-deliverables-A"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-assignment-deliverables-B"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-assignment-fee-A"]').exists()).toBe(true)
  })

  it('shows the fee without a unit when the agency gave none', async () => {
    const wrapper = await mountIt(true, [makeAssignment('A', 'invited', { fee_per: null })])

    expect(wrapper.find('[data-testid="creator-assignment-fee-A"] dd').text()).toBe('€25.00')
  })

  it('draws the brand note under its label, and omits the bar when there is no note', async () => {
    const wrapper = await mountIt(true, [
      makeAssignment('A', 'invited'),
      makeAssignment('B', 'invited', { offer_description: null }),
    ])

    const note = wrapper.find('[data-testid="creator-assignment-note-A"]')
    expect(note.find('.assignment-card__note-label').text()).toBe('Note from the brand')
    expect(note.text()).toContain('Looking for a calm, natural style.')
    expect(wrapper.find('[data-testid="creator-assignment-note-B"]').exists()).toBe(false)
  })

  it('labels the actions Accept invitation / View brief / Decline, Accept first', async () => {
    const wrapper = await mountIt(true, [makeAssignment('A', 'invited')])

    expect(wrapper.find('[data-testid="creator-assignment-accept-A"]').text()).toBe(
      'Accept invitation',
    )
    expect(wrapper.find('[data-testid="creator-assignment-view-A"]').text()).toBe('View brief')
    const decline = wrapper.find('[data-testid="creator-assignment-decline-A"]')
    expect(decline.text()).toBe('Decline')
    expect(decline.classes()).toContain('text-error')
  })

  /**
   * Pedram's second re-walk: "Accept invitation" was clipped on cards where all
   * three actions render. Frame 10: Accept full-width on its own row, View brief
   * and Decline side by side beneath. The payload has no origin field, so a
   * direct invitation and an applied-then-accepted one are the same markup; both
   * shapes are mounted here (a full offer, and the sparse one an application
   * produces: no note, no deliverables, no unit, no brand). The geometry itself
   * is pinned in the CSS spec (native-invitation-actions.spec.ts); jsdom lays out
   * nothing.
   */
  it('renders all three actions in one actions container on BOTH invitation kinds', async () => {
    const wrapper = await mountIt(true, [
      makeAssignment('DIRECT', 'invited'),
      makeAssignment(
        'APPLIED',
        'invited',
        { offer_description: null, deliverables: null, fee_per: null },
        null,
      ),
    ])

    for (const id of ['DIRECT', 'APPLIED']) {
      const actions = wrapper.find(`[data-testid="creator-assignment-${id}"] .assignment__actions`)
      expect(actions.exists()).toBe(true)
      expect(actions.findAll('.v-btn')).toHaveLength(3)
      expect(actions.find(`[data-testid="creator-assignment-accept-${id}"]`).text()).toBe(
        'Accept invitation',
      )
      expect(actions.find(`[data-testid="creator-assignment-view-${id}"]`).text()).toBe(
        'View brief',
      )
      expect(actions.find(`[data-testid="creator-assignment-decline-${id}"]`).text()).toBe(
        'Decline',
      )
    }
  })

  it('offers no Accept or Decline on an invitation that is no longer open', async () => {
    const wrapper = await mountIt(true, [makeAssignment('A', 'accepted')])

    expect(wrapper.find('[data-testid="creator-assignment-accept-A"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-assignment-decline-A"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="creator-assignment-view-A"]').exists()).toBe(true)
  })

  it('still accepts through the API from the full-width button', async () => {
    vi.mocked(creatorAssignmentsApi.accept).mockResolvedValue({
      data: { type: 'campaign_assignment', id: 'A', attributes: { status: 'accepted' } },
      meta: { code: 'assignment.accepted' },
    })
    const wrapper = await mountIt(true, [makeAssignment('A', 'invited')])

    await wrapper.find('[data-testid="creator-assignment-accept-A"]').trigger('click')
    await flushPromises()

    expect(creatorAssignmentsApi.accept).toHaveBeenCalledWith('A')
  })
})

describe('invitations — web is unchanged', () => {
  it('keeps the one-line summary and the web labels, with none of the native card', async () => {
    const wrapper = await mountIt(false, [makeAssignment('A', 'invited')])
    const card = wrapper.find('[data-testid="creator-assignment-A"]')

    expect(card.find('.assignment-card__tile').exists()).toBe(false)
    expect(card.find('.assignment-card__panel').exists()).toBe(false)
    expect(card.find('.assignment-card__note').exists()).toBe(false)
    expect(card.find('.assignment__info').exists()).toBe(true)
    expect(card.find('[data-testid="creator-assignment-accept-A"]').text()).toBe('Accept')
    expect(card.find('[data-testid="creator-assignment-view-A"]').text()).toBe('View')
  })
})

// ── AH-107 — the campaign's state on the native invitation card ─────────────

function withCampaignStatus(
  id: string,
  campaignStatus: string | undefined,
): CreatorAssignmentResource {
  const base = makeAssignment(id, 'invited')
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

describe('invitations — the campaign status chip (AH-107, native)', () => {
  it.each([
    ['draft', 'Upcoming'],
    ['active', 'Active'],
    ['paused', 'Paused'],
    ['completed', 'Completed'],
    ['cancelled', 'Cancelled'],
  ])('shows the %s campaign as "%s" under the brand', async (campaignStatus, label) => {
    const wrapper = await mountIt(true, [withCampaignStatus('A', campaignStatus)])

    const chip = wrapper.find('[data-testid="creator-assignment-campaign-status-A"]')
    expect(chip.text()).toBe(label)
    // In the head column (under the brand), NOT in the card's top row beside the
    // assignment-status pill: that row is where AH-106's squeeze lived.
    expect(chip.element.closest('.assignment-card__head')).not.toBeNull()
    expect(chip.classes()).toContain('campaign-status-chip')
  })

  it('does not take the assignment-status pill styling hook', async () => {
    const wrapper = await mountIt(true, [withCampaignStatus('A', 'paused')])

    // creatorNative.css styles `[data-testid^='creator-assignment-status-']` as the
    // pill (flex: none, a fixed 22px height). The campaign chip must not match it.
    const chip = wrapper.find('[data-testid="creator-assignment-campaign-status-A"]')
    expect(chip.attributes('data-testid')?.startsWith('creator-assignment-status-')).toBe(false)
    expect(wrapper.find('[data-testid="creator-assignment-status-A"]').text()).toBe('Invited')
  })

  it('shows nothing when the backend sends no status', async () => {
    const wrapper = await mountIt(true, [withCampaignStatus('A', undefined)])

    expect(wrapper.find('[data-testid="creator-assignment-campaign-status-A"]').exists()).toBe(
      false,
    )
  })
})
