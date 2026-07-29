/**
 * BoardColumns + BoardColumn (Sprint 12 Chunk 2, D-5/D-6) — the two DnD
 * surfaces. Real drag is not exercisable in jsdom, so `vuedraggable` is stubbed
 * with a passthrough that renders the `#item` slot; the move/reorder TRIGGERS
 * are driven through the components' exposed change handlers (the same code the
 * real `@change` wires to). Asserts the two surfaces emit the right payloads and
 * use SEPARATE groups (no nested-draggable fight).
 *
 * AH-059 (D4) adds a THIRD thing on this surface which is pointedly not a DnD
 * surface at all: the Applications pseudo-column. Its §5.34 negatives live here,
 * because "it is not part of the drag machinery" is a claim about THIS file's
 * template, not about the column's own behaviour — it is the first child of the
 * scroll root and a member of neither draggable group.
 */

import type { BoardCardResource, BoardColumnResource } from '@catalyst/api-client'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

// The pseudo-column fetches its own applications on mount; this spec is about
// the columns container, so the list is stubbed empty.
vi.mock('@/modules/campaigns/api/campaigns.api', () => ({
  campaignsApi: {
    listApplications: vi.fn().mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 0, pending_total: 0 },
    }),
  },
}))

import BoardApplicationsColumn from './BoardApplicationsColumn.vue'
import BoardColumn from './BoardColumn.vue'
import BoardColumns from './BoardColumns.vue'

// A passthrough stub for vuedraggable: renders the `#item` slot for each entry
// in `list` (BoardColumn) or `modelValue` (BoardColumns). It records its `group`
// so the test can assert the two surfaces don't share one.
const DraggableStub = defineComponent({
  name: 'DraggableStub',
  props: {
    list: { type: Array, default: () => [] },
    modelValue: { type: Array, default: () => [] },
    group: { type: String, default: '' },
  },
  emits: ['change', 'update:modelValue'],
  setup(props, { slots }) {
    return () => {
      const items = (props.list.length > 0 ? props.list : props.modelValue) as unknown[]
      return h(
        'div',
        { 'data-group': props.group },
        items.map((element, index) => slots.item?.({ element, index })),
      )
    }
  },
})

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
      created_at: '2026-06-01T00:00:00+00:00',
      updated_at: '2026-06-01T00:00:00+00:00',
    },
  }
}

function card(id: string, columnId: string): BoardCardResource {
  return {
    id,
    type: 'board_cards',
    attributes: {
      position: 0,
      created_at: '2026-06-01T00:00:00+00:00',
      updated_at: '2026-06-01T00:00:00+00:00',
    },
    relationships: {
      column: { data: { id: columnId, type: 'board_columns' } },
      assignment: {
        data: {
          id: `a-${id}`,
          type: 'campaign_assignments',
          status: 'invited',
          deliverables: null,
          posting_due_at: null,
          creator: { id: `cr-${id}`, display_name: `C ${id}` },
        },
      },
    },
  }
}

function mountColumns(canEditColumns = true) {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  return mount(BoardColumns, {
    props: {
      columns: [col('c1', 1), col('c2', 2)],
      cardsByColumn: { c1: [card('k1', 'c1')], c2: [] },
      canEditColumns,
      agencyId: 'agency-ulid',
      campaignId: 'campaign-ulid',
      canAct: true,
      campaignCurrency: 'EUR',
    },
    global: { plugins: [i18n, vuetify], stubs: { draggable: DraggableStub } },
  })
}

describe('BoardColumns / BoardColumn — DnD surfaces', () => {
  it('the card list and the column list use SEPARATE draggable groups', () => {
    const wrapper = mountColumns()
    const groups = wrapper
      .findAll('[data-group]')
      .map((el) => el.attributes('data-group'))
      .filter((g): g is string => g !== undefined && g !== '')
    expect(groups).toContain('board-columns')
    expect(groups).toContain('board-cards')
  })

  it('emits a `move` (cardId + destination column) when a card is added to a column', () => {
    const wrapper = mountColumns()
    const columnComponents = wrapper.findAllComponents(BoardColumn)
    const destination = columnComponents[1]! // c2
    ;(destination.vm as unknown as { onCardChange: (e: unknown) => void }).onCardChange({
      added: { element: card('k1', 'c1'), newIndex: 0 },
    })
    expect(wrapper.emitted('move')?.[0]).toEqual([{ cardId: 'k1', toColumnId: 'c2' }])
  })

  it('ignores `removed` / `moved` change events (only `added` is a move)', () => {
    const wrapper = mountColumns()
    const origin = wrapper.findAllComponents(BoardColumn)[0]!
    ;(origin.vm as unknown as { onCardChange: (e: unknown) => void }).onCardChange({
      removed: { element: card('k1', 'c1'), oldIndex: 0 },
    })
    expect(wrapper.emitted('move')).toBeUndefined()
  })

  it('emits a `reorder` with the full ordered ULID list on a column drag', () => {
    const wrapper = mountColumns()
    const vm = wrapper.vm as unknown as {
      localColumns: BoardColumnResource[]
      onColumnChange: () => void
    }
    vm.localColumns = [col('c2', 1), col('c1', 2)]
    vm.onColumnChange()
    expect(wrapper.emitted('reorder')?.[0]).toEqual([['c2', 'c1']])
  })

  it('shows the Add column button only when board-config is allowed', () => {
    expect(mountColumns(true).find('[data-test="board-add-column"]').exists()).toBe(true)
    expect(mountColumns(false).find('[data-test="board-add-column"]').exists()).toBe(false)
  })

  it('hides the column config menu for non-editors (staff can still drag cards)', () => {
    const wrapper = mountColumns(false)
    expect(wrapper.find('[data-test="board-column-menu-c1"]').exists()).toBe(false)
  })
})

/**
 * D4's §5.34 negatives — the no-drag rule, proved by ABSENCE.
 *
 * A `:disabled` prop would have made these tests assert a flag's value; what
 * they assert instead is that the machinery is not there to disable. Each one
 * fails loudly the day someone "helpfully" moves the pseudo-column inside the
 * draggable, which is exactly the mistake the enforcement style exists to catch.
 */
describe('BoardColumns — the Applications pseudo-column (AH-059, D4)', () => {
  it('renders as the FIRST CHILD of the scroll root, ahead of the real columns', () => {
    const wrapper = mountColumns()
    const root = wrapper.find('.board-columns-root').element
    const first = root.firstElementChild

    expect(first?.getAttribute('data-test')).toBe('board-applications-column')
    // Inside the scroller, so it travels with the board instead of pinning.
    expect(
      wrapper.find('.board-columns-root [data-test="board-applications-column"]').exists(),
    ).toBe(true)
  })

  it('is NOT inside either draggable group — no drag in, no drag out (§5.34)', () => {
    const wrapper = mountColumns()
    const column = wrapper.find('[data-test="board-applications-column"]').element

    // Walk up: no `[data-group]` (the stub's marker for a vuedraggable root)
    // may appear anywhere on the ancestor chain.
    const groups: string[] = []
    for (let node = column.parentElement; node !== null; node = node.parentElement) {
      const group = node.getAttribute('data-group')
      if (group !== null && group !== '') groups.push(group)
    }
    expect(groups).toEqual([])
  })

  it('is absent from `localColumns` — the reorder payload never mentions it', () => {
    const wrapper = mountColumns()
    const vm = wrapper.vm as unknown as {
      localColumns: BoardColumnResource[]
      onColumnChange: () => void
    }

    expect(vm.localColumns.map((c) => c.id)).toEqual(['c1', 'c2'])

    vm.onColumnChange()
    expect(wrapper.emitted('reorder')?.[0]).toEqual([['c1', 'c2']])
  })

  it('carries NO drag handle, unlike every real column', () => {
    const wrapper = mountColumns(true) // editors get handles on the real columns
    expect(wrapper.find('[data-test="board-column-drag-c1"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-test="board-applications-column"] .board-column__drag').exists(),
    ).toBe(false)
  })

  it('receives the campaign context + the invite ability from the board', () => {
    const column = mountColumns().findComponent(BoardApplicationsColumn)
    expect(column.props('agencyId')).toBe('agency-ulid')
    expect(column.props('campaignId')).toBe('campaign-ulid')
    expect(column.props('canAct')).toBe(true)
    expect(column.props('campaignCurrency')).toBe('EUR')
  })
})
