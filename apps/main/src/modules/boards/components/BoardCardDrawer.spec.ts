/**
 * BoardCardDrawer (Sprint 12 Chunk 2, D-9). Pins: the on-open load of the
 * assignment detail + movements in parallel, the detail face, and the null-safe movement
 * history — a since-deleted column id renders "(removed)", and an empty feed
 * shows the empty note.
 */

import type { BoardCardMovementResource, BoardCardResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/board.api', () => ({
  boardApi: { show: vi.fn(), movements: vi.fn() },
}))
vi.mock('@/modules/campaigns/api/campaigns.api', () => ({
  campaignsApi: { showAssignment: vi.fn() },
}))
// The Messages tab (campaign-messages chunk) builds an agency chat transport;
// stub the factory so the ChatPanel stub receives a truthy transport without
// touching the network.
vi.mock('@/modules/messaging/api/messaging.api', () => ({
  agencyChatTransport: vi.fn(() => ({ __transport: true })),
}))

// Stub ChatPanel — its own thread-fetch/poll is out of scope for the drawer's
// wiring test; we only assert it mounts with a transport.
const ChatPanelStub = {
  name: 'ChatPanel',
  props: ['transport', 'title'],
  template: '<div data-test="chat-panel-stub" />',
}

import { campaignsApi } from '@/modules/campaigns/api/campaigns.api'
import { boardApi } from '../api/board.api'
import { useBoardStore } from '../stores/useBoardStore'
import BoardCardDrawer from './BoardCardDrawer.vue'

const mockBoard = vi.mocked(boardApi)
const mockCampaigns = vi.mocked(campaignsApi)

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

function card(
  assignmentId: string | null,
  overrides: Partial<NonNullable<BoardCardResource['relationships']['assignment']['data']>> = {},
): BoardCardResource {
  return {
    id: 'k1',
    type: 'board_cards',
    attributes: { position: 0, created_at: 'x', updated_at: 'x' },
    relationships: {
      column: { data: { id: 'c1', type: 'board_columns' } },
      assignment: {
        data:
          assignmentId === null
            ? null
            : {
                id: assignmentId,
                type: 'campaign_assignments',
                status: 'posted',
                deliverables: null,
                posting_due_at: null,
                creator: { id: 'cr1', display_name: 'Jane Q' },
                ...overrides,
              },
      },
    },
  }
}

function movement(
  id: string,
  attrs: Partial<BoardCardMovementResource['attributes']> = {},
): BoardCardMovementResource {
  return {
    id,
    type: 'board_card_movements',
    attributes: {
      from_column_id: 'c1',
      to_column_id: 'c2',
      triggered_by: 'user',
      triggered_event_key: null,
      reason: null,
      created_at: '2026-06-01T00:00:00+00:00',
      ...attrs,
    },
  }
}

async function seedStore() {
  mockBoard.show.mockResolvedValue({
    data: {
      id: 'board-1',
      type: 'boards',
      attributes: { created_at: 'x', updated_at: 'x' },
      relationships: { campaign: { data: { id: 'campaign-ulid', type: 'campaigns' } } },
      columns: [
        {
          id: 'c1',
          type: 'board_columns',
          attributes: {
            name: 'Todo',
            position: 1,
            color_token: 'status-todefine',
            is_terminal_success: false,
            is_terminal_failure: false,
            card_count: null,
            created_at: 'x',
            updated_at: 'x',
          },
        },
        {
          id: 'c2',
          type: 'board_columns',
          attributes: {
            name: 'Done',
            position: 2,
            color_token: 'status-paid',
            is_terminal_success: true,
            is_terminal_failure: false,
            card_count: null,
            created_at: 'x',
            updated_at: 'x',
          },
        },
      ],
      automations: [],
      cards: [],
    },
  } as never)
  const store = useBoardStore()
  await store.load('agency-ulid', 'campaign-ulid')
}

async function mountDrawer(c: BoardCardResource, movements: BoardCardMovementResource[]) {
  setActivePinia(createPinia())
  await seedStore()
  mockBoard.movements.mockResolvedValue({ data: movements })
  mockCampaigns.showAssignment.mockResolvedValue({
    data: {
      id: 'a1',
      type: 'campaign_assignment',
      attributes: {
        status: 'posted',
        agreed_fee_minor_units: 20000,
        agreed_fee_currency: 'EUR',
        fee_per: 'script',
        offer_description: 'Two hooks, one CTA.',
        offer_attachment: {
          name: 'brief.pdf',
          mime_type: 'application/pdf',
          size_bytes: 2048,
          url: 'https://cdn/brief.pdf',
        },
        invited_at: '2026-06-01T00:00:00+00:00',
        posting_due_at: null,
        submitted_draft_at: '2026-06-03T00:00:00+00:00',
        approved_at: null,
        posted_at: null,
        verified_live_at: null,
        creator: { id: 'cr1', display_name: 'Jane Q' },
        campaign: { id: 'cmp1', name: 'Summer Push', brand_name: 'Acme' },
      },
      relationships: { drafts: [], posted_content: [] },
    },
  } as never)

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(BoardCardDrawer, {
    props: { modelValue: true, agencyId: 'agency-ulid', campaignId: 'campaign-ulid', card: c },
    global: {
      plugins: [i18n, vuetify],
      stubs: { VDialog: VDialogStub, ChatPanel: ChatPanelStub },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('BoardCardDrawer', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.restoreAllMocks())

  it('fetches the assignment detail + movements on open', async () => {
    const wrapper = await mountDrawer(card('a1'), [movement('1')])
    expect(mockCampaigns.showAssignment).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'a1')
    expect(mockBoard.movements).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'k1')
    expect(wrapper.find('[data-test="board-card-drawer-detail"]').text()).toContain('Jane Q')
    wrapper.unmount()
  })

  it('renders movement rows with resolved column names', async () => {
    const wrapper = await mountDrawer(card('a1'), [
      movement('1', { from_column_id: 'c1', to_column_id: 'c2' }),
    ])
    const row = wrapper.find('[data-test="board-card-movement-1"]')
    expect(row.text()).toContain('Todo')
    expect(row.text()).toContain('Done')
    wrapper.unmount()
  })

  it('renders "(removed)" for a since-deleted column id (null-safe)', async () => {
    const wrapper = await mountDrawer(card('a1'), [
      movement('1', { from_column_id: 'gone', to_column_id: null }),
    ])
    const row = wrapper.find('[data-test="board-card-movement-1"]')
    expect(row.text()).toContain('(removed)')
    wrapper.unmount()
  })

  it('shows the empty note when there are no movements', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    expect(wrapper.find('[data-test="board-card-drawer-history-empty"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('does not call showAssignment for a removed (null assignment) card, still loads movements', async () => {
    const wrapper = await mountDrawer(card(null), [movement('1')])
    expect(mockCampaigns.showAssignment).not.toHaveBeenCalled()
    expect(mockBoard.movements).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows the Declined history tag for a re-offered (previously_declined) assignment', async () => {
    const wrapper = await mountDrawer(
      card('a1', { status: 'invited', previously_declined: true }),
      [],
    )
    expect(wrapper.find('[data-test="board-card-drawer-declined-history"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('hides the Declined history tag for a plain assignment', async () => {
    const wrapper = await mountDrawer(card('a1', { status: 'invited' }), [])
    expect(wrapper.find('[data-test="board-card-drawer-declined-history"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('mounts the Messages tab (default) with the per-assignment chat transport', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    expect(wrapper.find('[data-test="board-card-drawer-tab-messages"]').exists()).toBe(true)
    // The ChatPanel is mounted with a truthy transport for a real assignment.
    const panel = wrapper.findComponent(ChatPanelStub)
    expect(panel.exists()).toBe(true)
    expect(panel.props('transport')).toBeTruthy()
    // No "no conversation" fallback for a card with an assignment.
    expect(wrapper.find('[data-test="board-card-drawer-messages-none"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows the "no conversation" note (no ChatPanel) for a removed (null) assignment', async () => {
    const wrapper = await mountDrawer(card(null), [])
    expect(wrapper.find('[data-test="board-card-drawer-messages-none"]').exists()).toBe(true)
    expect(wrapper.findComponent(ChatPanelStub).exists()).toBe(false)
    wrapper.unmount()
  })

  // ── Detail-tab facelift ────────────────────────────────────────────────────

  it('renders the identity header with campaign · brand and the offer terms', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    expect(wrapper.find('[data-test="board-card-drawer-campaign"]').text()).toBe(
      'Summer Push · Acme',
    )
    const fee = wrapper.find('[data-test="board-card-drawer-fee"]')
    expect(fee.text()).toContain('200')
    expect(fee.text()).toContain('script')
    expect(wrapper.find('[data-test="board-card-drawer-offer-description"]').text()).toBe(
      'Two hooks, one CTA.',
    )
    const attachment = wrapper.find('[data-test="board-card-drawer-attachment"]')
    expect(attachment.text()).toContain('brief.pdf')
    expect(attachment.attributes('href')).toBe('https://cdn/brief.pdf')
    wrapper.unmount()
  })

  it('renders the progress timeline with reached steps checked and dates formatted', async () => {
    const wrapper = await mountDrawer(card('a1'), [])
    const timeline = wrapper.find('[data-test="board-card-drawer-timeline"]')
    expect(timeline.exists()).toBe(true)
    // Reached steps carry a formatted date; unreached fall back to the em dash.
    expect(wrapper.find('[data-test="board-card-drawer-step-invited"]').text()).toContain('2026')
    expect(wrapper.find('[data-test="board-card-drawer-step-draft_submitted"]').text()).toContain(
      '2026',
    )
    expect(wrapper.find('[data-test="board-card-drawer-step-approved"]').text()).toContain('—')
    expect(wrapper.find('[data-test="board-card-drawer-step-live_verified"]').text()).toContain('—')
    wrapper.unmount()
  })

  it('renders deliverable chips from the card-face data', async () => {
    const wrapper = await mountDrawer(card('a1', { deliverables: ['1 Reel', '3 Stories'] }), [])
    const chips = wrapper.find('[data-test="board-card-drawer-deliverables"]')
    expect(chips.exists()).toBe(true)
    expect(chips.text()).toContain('1 Reel')
    expect(chips.text()).toContain('3 Stories')
    wrapper.unmount()
  })

  it('formats the movement timestamp instead of dumping the ISO string', async () => {
    const wrapper = await mountDrawer(card('a1'), [
      movement('1', { created_at: '2026-06-01T12:30:00+00:00' }),
    ])
    const row = wrapper.find('[data-test="board-card-movement-1"]')
    expect(row.text()).not.toContain('2026-06-01T12:30:00')
    expect(row.text()).toContain('2026')
    wrapper.unmount()
  })
})
