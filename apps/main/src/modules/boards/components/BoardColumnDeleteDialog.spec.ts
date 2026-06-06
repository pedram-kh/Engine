/**
 * BoardColumnDeleteDialog (Sprint 12 Chunk 2, D-7 / §14.3) — load-bearing #3.
 *
 * Pins the delete safeguard: the destination dropdown appears only for a
 * non-empty column (read from the store's bucketed counts), and the two server
 * refusals surface as `ApiError.code` BANNERS — NOT extractFieldErrors
 * field-pointers (there is no form field on a confirm dialog to pin them to).
 */

import { ApiError } from '@catalyst/api-client'
import type { BoardCardResource, BoardColumnResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/board.api', () => ({
  boardApi: { show: vi.fn(), deleteColumn: vi.fn() },
}))

import { boardApi } from '../api/board.api'
import { useBoardStore } from '../stores/useBoardStore'
import BoardColumnDeleteDialog from './BoardColumnDeleteDialog.vue'

const mockApi = vi.mocked(boardApi)

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
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

async function mountDelete(targetColumnId: string, cards: BoardCardResource[]) {
  setActivePinia(createPinia())
  mockApi.show.mockResolvedValue({
    data: {
      id: 'board-1',
      type: 'boards',
      attributes: { created_at: 'x', updated_at: 'x' },
      relationships: { campaign: { data: { id: 'campaign-ulid', type: 'campaigns' } } },
      columns: [col('c1', 1), col('c2', 2)],
      automations: [],
      cards,
    },
  } as never)
  const store = useBoardStore()
  await store.load('agency-ulid', 'campaign-ulid')

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const target = store.columns.find((c) => c.id === targetColumnId) ?? null
  const wrapper = mount(BoardColumnDeleteDialog, {
    props: { modelValue: true, column: target },
    global: { plugins: [i18n, vuetify], stubs: { VDialog: VDialogStub } },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('BoardColumnDeleteDialog', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.restoreAllMocks())

  it('omits the destination dropdown for an empty column and deletes with no destination', async () => {
    mockApi.deleteColumn.mockResolvedValue(undefined as never)
    mockApi.show.mockResolvedValue({
      data: {
        id: 'board-1',
        type: 'boards',
        attributes: { created_at: 'x', updated_at: 'x' },
        relationships: { campaign: { data: { id: 'campaign-ulid', type: 'campaigns' } } },
        columns: [col('c1', 1), col('c2', 2)],
        automations: [],
        cards: [],
      },
    } as never)
    const wrapper = await mountDelete('c1', [])

    expect(wrapper.find('[data-test="board-column-delete-destination"]').exists()).toBe(false)
    await wrapper.find('[data-test="board-column-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(mockApi.deleteColumn).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'c1', {})
    expect(wrapper.emitted('deleted')).toHaveLength(1)
    wrapper.unmount()
  })

  it('shows the destination dropdown for a non-empty column (bucketed count > 0)', async () => {
    const wrapper = await mountDelete('c1', [card('k1', 'c1'), card('k2', 'c1')])
    expect(wrapper.find('[data-test="board-column-delete-destination"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="board-column-delete-body-nonempty"]').text()).toContain('2')
    wrapper.unmount()
  })

  it('surfaces board.column.last_column as a code BANNER (not a field error)', async () => {
    mockApi.deleteColumn.mockRejectedValue(
      new ApiError({ status: 422, code: 'board.column.last_column', message: 'no' }),
    )
    const wrapper = await mountDelete('c1', [])

    await wrapper.find('[data-test="board-column-delete-confirm"]').trigger('click')
    await flushPromises()

    const banner = wrapper.find('[data-test="board-column-delete-banner"]')
    expect(banner.exists()).toBe(true)
    expect(banner.text()).toBe('A board must keep at least one column.')
    expect(wrapper.emitted('deleted')).toBeUndefined()
    wrapper.unmount()
  })

  it('surfaces board.column.destination_required as a code BANNER', async () => {
    mockApi.deleteColumn.mockRejectedValue(
      new ApiError({ status: 422, code: 'board.column.destination_required', message: 'no' }),
    )
    const wrapper = await mountDelete('c1', [card('k1', 'c1')])

    await wrapper.find('[data-test="board-column-delete-confirm"]').trigger('click')
    await flushPromises()

    const banner = wrapper.find('[data-test="board-column-delete-banner"]')
    expect(banner.exists()).toBe(true)
    expect(banner.text()).toContain('choose a destination column')
    wrapper.unmount()
  })
})
