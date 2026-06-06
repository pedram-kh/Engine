/**
 * BoardView (Sprint 12 Chunk 2, D-1) — the assembly + lifecycle.
 *
 * Pins the UI half of load-bearing #1: a rejected drag reverts the card to its
 * origin AND raises the error toast. Plus: the store lifecycle (load on mount,
 * the 30s poll tick → refresh, reset on unmount) and the role gate (no
 * automations button / column config for a non-configurer).
 */

import { ApiError } from '@catalyst/api-client'
import type { BoardCardResource, BoardColumnResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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
  campaignsApi: { showAssignment: vi.fn() },
}))

import { boardApi } from '../api/board.api'
import { useBoardStore } from '../stores/useBoardStore'
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

function col(id: string, position: number): BoardColumnResource {
  return {
    id,
    type: 'board_columns',
    attributes: {
      name: id,
      position,
      color_token: 'status-todefine',
      is_terminal_success: false,
      is_terminal_failure: false,
      card_count: null,
      created_at: 'x',
      updated_at: 'x',
    },
  }
}

function card(id: string, columnId: string): BoardCardResource {
  return {
    id,
    type: 'board_cards',
    attributes: { position: 0, created_at: 'x', updated_at: 'x' },
    relationships: {
      column: { data: { id: columnId, type: 'board_columns' } },
      assignment: { data: null },
    },
  }
}

function boardPayload(cards: BoardCardResource[]) {
  return {
    data: {
      id: 'board-1',
      type: 'boards',
      attributes: { created_at: 'x', updated_at: 'x' },
      relationships: { campaign: { data: { id: 'campaign-ulid', type: 'campaigns' } } },
      columns: [col('c1', 1), col('c2', 2)],
      automations: [],
      cards,
    },
  }
}

async function mountView(canConfigure = true) {
  setActivePinia(createPinia())
  mockApi.show.mockResolvedValue(boardPayload([card('k1', 'c1')]) as never)

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(BoardView, {
    props: { agencyId: 'agency-ulid', campaignId: 'campaign-ulid', canConfigure },
    global: {
      plugins: [i18n, vuetify],
      stubs: { draggable: DraggableStub, VSnackbar: VSnackbarStub, VDialog: true },
    },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('BoardView', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.restoreAllMocks())

  it('loads the board on mount and renders the columns', async () => {
    const wrapper = await mountView()
    expect(mockApi.show).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid')
    expect(wrapper.find('[data-test="board-view"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="board-column-c1"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('reverts the card AND raises the toast when the server rejects a drag (load-bearing #1)', async () => {
    mockApi.moveCard.mockRejectedValue(
      new ApiError({ status: 422, code: 'validation.failed', message: 'no' }),
    )
    const wrapper = await mountView()
    const store = useBoardStore()

    wrapper.findComponent(BoardColumns).vm.$emit('move', { cardId: 'k1', toColumnId: 'c2' })
    await flushPromises()

    // Reverted to origin column.
    expect(store.cards.find((c) => c.id === 'k1')?.relationships.column.data.id).toBe('c1')
    // And the error toast is shown.
    expect(wrapper.find('.snackbar-stub').text()).toContain("it's back where it was")
    wrapper.unmount()
  })

  it('opens the drawer when a card is opened', async () => {
    const wrapper = await mountView()
    wrapper.findComponent(BoardColumns).vm.$emit('open-card', card('k1', 'c1'))
    await flushPromises()
    expect(wrapper.findComponent({ name: 'BoardCardDrawer' }).props('modelValue')).toBe(true)
    wrapper.unmount()
  })

  it('hides the automations button + column config for a non-configurer (staff)', async () => {
    const wrapper = await mountView(false)
    expect(wrapper.find('[data-test="board-automations-open"]').exists()).toBe(false)
    expect(wrapper.findComponent(BoardColumns).props('canEditColumns')).toBe(false)
    wrapper.unmount()
  })

  it('polls the board every 30s while mounted, and resets the store on unmount', async () => {
    vi.useFakeTimers()
    setActivePinia(createPinia())
    mockApi.show.mockResolvedValue(boardPayload([card('k1', 'c1')]) as never)

    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
    const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
    const wrapper = mount(BoardView, {
      props: { agencyId: 'agency-ulid', campaignId: 'campaign-ulid', canConfigure: true },
      global: {
        plugins: [i18n, vuetify],
        stubs: { draggable: DraggableStub, VSnackbar: VSnackbarStub, VDialog: true },
      },
      attachTo: document.createElement('div'),
    })
    await vi.advanceTimersByTimeAsync(0)
    expect(mockApi.show).toHaveBeenCalledTimes(1) // initial load

    await vi.advanceTimersByTimeAsync(30000)
    expect(mockApi.show).toHaveBeenCalledTimes(2) // poll tick → refresh

    const store = useBoardStore()
    wrapper.unmount()
    expect(store.columns).toHaveLength(0) // reset on unmount

    await vi.advanceTimersByTimeAsync(90000)
    expect(mockApi.show).toHaveBeenCalledTimes(2) // poll stopped — no background polling
    vi.useRealTimers()
  })
})
