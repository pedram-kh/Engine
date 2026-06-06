/**
 * BoardColumnDialog (Sprint 12 Chunk 2, D-6). Pins the add/edit submit + the
 * canonical per-field 422 binding (`extractFieldErrors` → name field), and the
 * generic banner fallback for a non-field error.
 */

import { ApiError } from '@catalyst/api-client'
import type { BoardColumnResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/board.api', () => ({
  boardApi: {
    show: vi.fn(),
    createColumn: vi.fn(),
    updateColumn: vi.fn(),
  },
}))

import { boardApi } from '../api/board.api'
import { useBoardStore } from '../stores/useBoardStore'
import BoardColumnDialog from './BoardColumnDialog.vue'

const mockApi = vi.mocked(boardApi)

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

function emptyBoard() {
  return {
    data: {
      id: 'board-1',
      type: 'boards',
      attributes: { created_at: 'x', updated_at: 'x' },
      relationships: { campaign: { data: { id: 'campaign-ulid', type: 'campaigns' } } },
      columns: [],
      automations: [],
      cards: [],
    },
  }
}

async function mountDialog(column: BoardColumnResource | null = null) {
  setActivePinia(createPinia())
  mockApi.show.mockResolvedValue(emptyBoard() as never)
  const store = useBoardStore()
  await store.load('agency-ulid', 'campaign-ulid')

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(BoardColumnDialog, {
    props: { modelValue: true, column },
    global: { plugins: [i18n, vuetify], stubs: { VDialog: VDialogStub } },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('BoardColumnDialog', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.restoreAllMocks())

  it('creates a column and emits saved on success', async () => {
    mockApi.createColumn.mockResolvedValue({
      data: { id: 'c9', type: 'board_columns', attributes: {} },
    } as never)
    const wrapper = await mountDialog(null)

    await wrapper.find('[data-test="board-column-name"] input').setValue('Producing')
    await wrapper.find('[data-test="board-column-save"]').trigger('click')
    await flushPromises()

    expect(mockApi.createColumn).toHaveBeenCalledWith(
      'agency-ulid',
      'campaign-ulid',
      expect.objectContaining({ name: 'Producing' }),
    )
    expect(wrapper.emitted('saved')).toHaveLength(1)
    wrapper.unmount()
  })

  it('binds a 422 onto the name field via extractFieldErrors', async () => {
    mockApi.createColumn.mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'validation.failed',
        message: 'Validation failed.',
        details: [
          {
            detail: 'The name is already taken.',
            source: { pointer: '/data/attributes/name' },
            meta: { field: 'name' },
          },
        ],
      }),
    )
    const wrapper = await mountDialog(null)

    await wrapper.find('[data-test="board-column-name"] input').setValue('Dup')
    await wrapper.find('[data-test="board-column-save"]').trigger('click')
    await flushPromises()

    const field = wrapper.findComponent({ name: 'VTextField' })
    expect(field.props('errorMessages')).toContain('The name is already taken.')
    expect(wrapper.find('[data-test="board-column-form-error"]').exists()).toBe(false)
    expect(wrapper.emitted('saved')).toBeUndefined()
    wrapper.unmount()
  })

  it('shows the generic banner for a non-field error', async () => {
    mockApi.createColumn.mockRejectedValue(
      new ApiError({ status: 500, code: 'server.error', message: 'boom' }),
    )
    const wrapper = await mountDialog(null)

    await wrapper.find('[data-test="board-column-name"] input').setValue('X')
    await wrapper.find('[data-test="board-column-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="board-column-form-error"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
