/**
 * PL-1.2 W5 + W6 (and the board half of W2) — the wave board.
 *
 *  W5  Applied plus eight columns in order, a count chip on each, the label on Invited cards, a card face with name
 *      and label only, no column controls, no element for an empty scripts slot.
 *  W6  A hand move calls the move endpoint; a move into a working column that the server refuses shows the
 *      `selection_requires_acceptance` message and the card goes back.
 *  W2  A normal board's DOM is what it was: no stage names, controls kept, and no comment node added.
 */

import { ApiError } from '@catalyst/api-client'
import type {
  AssignmentStatus,
  BoardCardResource,
  BoardColumnResource,
  BoardWaveStage,
} from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/board.api', () => ({
  boardApi: {
    show: vi.fn(),
    moveCard: vi.fn(),
    movements: vi.fn(),
    createColumn: vi.fn(),
    updateColumn: vi.fn(),
    deleteColumn: vi.fn(),
    reorderColumns: vi.fn(),
    updateAutomation: vi.fn(),
  },
}))
vi.mock('@/modules/campaigns/api/campaigns.api', () => ({
  campaignsApi: { showAssignment: vi.fn(), listApplications: vi.fn() },
}))

import { campaignsApi } from '@/modules/campaigns/api/campaigns.api'

import { boardApi } from '../api/board.api'
import { useBoardStore } from '../stores/useBoardStore'
import BoardCard from './BoardCard.vue'
import BoardColumns from './BoardColumns.vue'
import BoardView from './BoardView.vue'

const mockApi = vi.mocked(boardApi)

const DraggableStub = defineComponent({
  name: 'DraggableStub',
  props: {
    list: { type: Array, default: () => [] },
    modelValue: { type: Array, default: () => [] },
  },
  emits: ['change', 'update:modelValue'],
  setup(props, { slots }) {
    return () => {
      const items = (props.list.length > 0 ? props.list : props.modelValue) as unknown[]
      return h(
        'div',
        items.map((element, index) => slots.item?.({ element, index })),
      )
    }
  },
})
const VSnackbarStub = {
  name: 'VSnackbar',
  props: ['modelValue'],
  template: '<div class="snackbar-stub"><slot /></div>',
}

const STAGES: BoardWaveStage[] = [
  'invited',
  'selected',
  'filming',
  'submitted',
  'in_edits',
  'client_review',
  'approved',
  'dropped',
]
const STAGE_NAMES = [
  'Invited',
  'Selected',
  'Filming',
  'Submitted / in review',
  'In edits',
  'Client review',
  'Approved',
  'Dropped',
]

function col(id: string, position: number, stage?: BoardWaveStage): BoardColumnResource {
  return {
    id,
    type: 'board_columns',
    attributes: {
      name: `stored-${id}`,
      position,
      color_token: 'status-todefine',
      is_terminal_success: false,
      is_terminal_failure: false,
      card_count: null,
      ...(stage ? { stage_key: stage } : {}),
      created_at: 'x',
      updated_at: 'x',
    },
  }
}

function card(
  id: string,
  columnId: string,
  invite?: 'invited' | 'accepted' | 'declined',
  origin?: 'invite' | 'application',
  status?: AssignmentStatus,
): BoardCardResource {
  return {
    id,
    type: 'board_cards',
    ...(invite ? { invite_state: invite } : {}),
    ...(origin ? { invite_origin: origin } : {}),
    attributes: { position: 0, created_at: 'x', updated_at: 'x' },
    relationships: {
      column: { data: { id: columnId, type: 'board_columns' } },
      assignment: {
        data: {
          id: `a-${id}`,
          type: 'campaign_assignments',
          status: status ?? (invite === 'declined' ? 'declined' : (invite ?? 'accepted')),
          deliverables: ['1 reel'],
          posting_due_at: '2030-01-01T00:00:00Z',
          agreed_fee_minor_units: 20_000,
          agreed_fee_currency: 'EUR',
          fee_per: 'script',
          creator: { id: `cr-${id}`, display_name: `Creator ${id}` },
        },
      },
    },
  }
}

function payload(columns: BoardColumnResource[], cards: BoardCardResource[]) {
  return {
    data: {
      id: 'board-1',
      type: 'boards',
      attributes: { created_at: 'x', updated_at: 'x' },
      relationships: { campaign: { data: { id: 'campaign-ulid', type: 'campaigns' } } },
      columns,
      automations: [],
      cards,
    },
  }
}

const waveColumns = (): BoardColumnResource[] => STAGES.map((s, i) => col(`w${i}`, i + 1, s))

async function mountView(columns: BoardColumnResource[], cards: BoardCardResource[]) {
  setActivePinia(createPinia())
  mockApi.show.mockResolvedValue(payload(columns, cards) as never)
  vi.mocked(campaignsApi.listApplications).mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 0, pending_total: 0 },
  } as never)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(BoardView, {
    props: {
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      canConfigure: true,
      canAct: true,
      campaignCurrency: 'EUR',
    },
    global: {
      plugins: [i18n, vuetify],
      stubs: { draggable: DraggableStub, VSnackbar: VSnackbarStub, VDialog: true },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

function commentCount(root: Element): number {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_COMMENT)
  let n = 0
  while (walker.nextNode()) n += 1
  return n
}

describe('wave board (PL-1.2 W5)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('draws Applied, then the eight stages in order, each named in the viewer language with a count', async () => {
    const w = await mountView(waveColumns(), [
      card('k1', 'w0', 'invited'),
      card('k2', 'w0', 'accepted'),
    ])
    expect(w.find('[data-test="board-applications-name"]').text()).toBe('Applied')
    const names = STAGES.map((_, i) => w.find(`[data-test="board-column-name-w${i}"]`).text())
    expect(names).toEqual(STAGE_NAMES)
    expect(w.find('[data-test="board-column-count-w0"]').text()).toBe('2')
    expect(w.find('[data-test="board-column-count-w3"]').text()).toBe('0')
    // Never the stored name: a wave board's labels follow the language.
    expect(w.text()).not.toContain('stored-w0')
    w.unmount()
  })

  it('has no column controls and no automations button, even for a configurer', async () => {
    const w = await mountView(waveColumns(), [])
    expect(w.find('[data-test="board-add-column"]').exists()).toBe(false)
    expect(w.find('[data-test="board-automations-open"]').exists()).toBe(false)
    expect(w.find('[data-test="board-column-menu-w0"]').exists()).toBe(false)
    expect(w.find('[data-test="board-column-drag-w0"]').exists()).toBe(false)
    expect(w.findComponent(BoardColumns).props('canEditColumns')).toBe(false)
    w.unmount()
  })

  it('the card face is the name and the label: no fee, no due date, no deliverable text', async () => {
    const w = await mountView(waveColumns(), [card('k1', 'w0', 'accepted')])
    expect(w.find('[data-test="board-card-name-k1"]').text()).toBe('Creator k1')
    expect(w.find('[data-test="board-card-status-k1"]').text()).toBe('Accepted')
    expect(w.find('[data-test="board-card-fee-k1"]').exists()).toBe(false)
    expect(w.find('[data-test="board-card-due-k1"]').exists()).toBe(false)
    expect(w.find('[data-test="board-card-k1"]').text()).not.toMatch(/€|reel|script/)
    w.unmount()
  })

  it('D3: the Invited label says how the creator got there, for each state and each origin', async () => {
    const cases: Array<
      [
        string,
        'invited' | 'accepted' | 'declined',
        'invite' | 'application',
        AssignmentStatus,
        string,
      ]
    > = [
      ['i1', 'invited', 'invite', 'invited', 'Invited'],
      ['i2', 'accepted', 'invite', 'contracted', 'Accepted invite'],
      ['i3', 'declined', 'invite', 'declined', 'Declined'],
      ['a1', 'invited', 'application', 'invited', 'Offer sent'],
      ['a2', 'accepted', 'application', 'contracted', 'Application accepted'],
      ['a3', 'declined', 'application', 'declined', 'Declined'],
    ]
    const w = await mountView(
      waveColumns(),
      cases.map(([id, state, origin, status]) => card(id, 'w0', state, origin, status)),
    )
    for (const [id, , , , label] of cases) {
      expect(w.find(`[data-test="board-card-status-${id}"]`).text(), id).toBe(label)
    }
    w.unmount()
  })

  it('D3: a working column keeps the assignment status label (no origin sent)', async () => {
    const w = await mountView(waveColumns(), [
      card('c1', 'w1', undefined, undefined, 'contracted'),
      card('c2', 'w1', 'accepted', undefined, 'accepted'),
    ])
    expect(w.find('[data-test="board-card-status-c1"]').text()).toBe('Contracted')
    expect(w.find('[data-test="board-card-status-c2"]').text()).toBe('Accepted')
    w.unmount()
  })

  it('an empty scripts slot draws no element of its own', () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
    const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
    const mountSlim = (slots?: Record<string, string>) =>
      mount(BoardCard, {
        props: { card: card('k1', 'w0', 'invited'), slim: true },
        ...(slots ? { slots } : {}),
        global: { plugins: [i18n, vuetify] },
      })
    const bare = mountSlim()
    const body = bare.find('.board-card__body')
    expect(body.element.children).toHaveLength(2) // name row + label row, nothing else
    expect(bare.find('[data-test="scripts"]').exists()).toBe(false)

    // The seam is real: PL-1.3 fills it and it shows up, on a slim card only.
    const filled = mountSlim({ scripts: '<i data-test="scripts">3</i>' })
    expect(filled.find('[data-test="scripts"]').exists()).toBe(true)
  })
})

describe('wave board moves (PL-1.2 W6)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('a hand move calls the move endpoint with the target column', async () => {
    mockApi.moveCard.mockResolvedValue({ data: card('k1', 'w1', 'accepted') } as never)
    const w = await mountView(waveColumns(), [card('k1', 'w0', 'accepted')])
    w.findComponent(BoardColumns).vm.$emit('move', { cardId: 'k1', toColumnId: 'w1' })
    await flushPromises()
    expect(mockApi.moveCard).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'k1', {
      target_column_id: 'w1',
    })
    w.unmount()
  })

  it('a refused move into a working column shows the acceptance message and the card goes back', async () => {
    mockApi.moveCard.mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'board.selection_requires_acceptance',
        message: 'x',
      }),
    )
    const w = await mountView(waveColumns(), [card('k1', 'w0', 'invited')])
    const store = useBoardStore()
    w.findComponent(BoardColumns).vm.$emit('move', { cardId: 'k1', toColumnId: 'w1' })
    await flushPromises()
    expect(store.cards.find((c) => c.id === 'k1')?.relationships.column.data.id).toBe('w0')
    expect(w.find('.snackbar-stub').text()).toBe(
      'Only a creator who has accepted can be moved into this column.',
    )
    w.unmount()
  })

  it('any other refusal keeps the generic message', async () => {
    mockApi.moveCard.mockRejectedValue(
      new ApiError({ status: 422, code: 'validation.failed', message: 'x' }),
    )
    const w = await mountView(waveColumns(), [card('k1', 'w0', 'invited')])
    w.findComponent(BoardColumns).vm.$emit('move', { cardId: 'k1', toColumnId: 'w1' })
    await flushPromises()
    expect(w.find('.snackbar-stub').text()).toContain("it's back where it was")
    w.unmount()
  })
})

describe('normal board is unchanged (PL-1.2 W2)', () => {
  beforeEach(() => vi.clearAllMocks())

  // Measured on the pre-PL-1.2 board (git HEAD a173a9b2) with these exact props: 55.
  const BASELINE_COMMENTS = 55

  it('keeps its stored names, its controls and the Applications title; adds no comment node', async () => {
    const w = await mountView([col('c1', 1), col('c2', 2)], [card('k1', 'c1', 'invited')])
    expect(w.find('[data-test="board-column-name-c1"]').text()).toBe('stored-c1')
    expect(w.find('[data-test="board-applications-name"]').text()).toBe('Applications')
    expect(w.find('[data-test="board-add-column"]').exists()).toBe(true)
    expect(w.find('[data-test="board-automations-open"]').exists()).toBe(true)
    expect(w.find('[data-test="board-card-fee-k1"]').exists()).toBe(true)
    expect(w.find('[data-test="board-card-due-k1"]').exists()).toBe(true)
    expect(commentCount(w.element)).toBe(BASELINE_COMMENTS)
    w.unmount()
  })
})
