/**
 * BoardAutomationDialog (Sprint 12 Chunk 2, D-8). Pins: a row per automation,
 * the target dropdown ("No automation" + columns), the move/none action_type
 * derivation, the enable toggle, and the broken-state affordance for an enabled
 * move automation whose target was deleted (§14.4).
 */

import type { BoardAutomationResource } from '@catalyst/api-client'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

vi.mock('../api/board.api', () => ({
  boardApi: { show: vi.fn(), updateAutomation: vi.fn() },
}))

import { boardApi } from '../api/board.api'
import { useBoardStore } from '../stores/useBoardStore'
import BoardAutomationDialog from './BoardAutomationDialog.vue'

const mockApi = vi.mocked(boardApi)

const VDialogStub = {
  name: 'VDialog',
  props: ['modelValue'],
  template: '<div class="vdialog-stub"><slot /></div>',
}

function automation(
  id: string,
  attrs: Partial<BoardAutomationResource['attributes']> = {},
): BoardAutomationResource {
  return {
    id,
    type: 'board_automations',
    attributes: {
      event_key: 'assignment.contracted',
      action_type: 'move_to_column',
      is_enabled: true,
      condition: null,
      target_column_id: 'c1',
      created_at: 'x',
      updated_at: 'x',
      ...attrs,
    },
  }
}

async function mountDialog(automations: BoardAutomationResource[]) {
  setActivePinia(createPinia())
  mockApi.show.mockResolvedValue({
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
      ],
      automations,
      cards: [],
    },
  } as never)
  const store = useBoardStore()
  await store.load('agency-ulid', 'campaign-ulid')

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const wrapper = mount(BoardAutomationDialog, {
    props: { modelValue: true },
    global: { plugins: [i18n, vuetify], stubs: { VDialog: VDialogStub } },
    attachTo: document.createElement('div'),
  })
  await flushPromises()
  return wrapper
}

describe('BoardAutomationDialog', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.restoreAllMocks())

  it('renders a row per automation', async () => {
    const wrapper = await mountDialog([automation('a1'), automation('a2')])
    expect(wrapper.find('[data-test="board-automation-row-a1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="board-automation-row-a2"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('updates target → move_to_column + the picked column', async () => {
    mockApi.updateAutomation.mockResolvedValue({ data: automation('a1') } as never)
    const wrapper = await mountDialog([
      automation('a1', { target_column_id: null, action_type: 'none' }),
    ])

    const select = wrapper.findComponent({ name: 'VSelect' })
    select.vm.$emit('update:modelValue', 'c1')
    await flushPromises()

    expect(mockApi.updateAutomation).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'a1', {
      target_column_id: 'c1',
      action_type: 'move_to_column',
    })
    wrapper.unmount()
  })

  it('selecting "No automation" sends action_type none + null target', async () => {
    mockApi.updateAutomation.mockResolvedValue({ data: automation('a1') } as never)
    const wrapper = await mountDialog([automation('a1')])

    wrapper.findComponent({ name: 'VSelect' }).vm.$emit('update:modelValue', null)
    await flushPromises()

    expect(mockApi.updateAutomation).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'a1', {
      target_column_id: null,
      action_type: 'none',
    })
    wrapper.unmount()
  })

  it('toggles enable through updateAutomation', async () => {
    mockApi.updateAutomation.mockResolvedValue({ data: automation('a1') } as never)
    const wrapper = await mountDialog([automation('a1')])

    wrapper.findComponent({ name: 'VSwitch' }).vm.$emit('update:modelValue', false)
    await flushPromises()

    expect(mockApi.updateAutomation).toHaveBeenCalledWith('agency-ulid', 'campaign-ulid', 'a1', {
      is_enabled: false,
    })
    wrapper.unmount()
  })

  it('flags the broken state for an enabled move automation with a null target', async () => {
    const wrapper = await mountDialog([
      automation('a1', { is_enabled: true, action_type: 'move_to_column', target_column_id: null }),
    ])
    expect(wrapper.find('[data-test="board-automation-broken-a1"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('does NOT flag broken when the automation is disabled', async () => {
    const wrapper = await mountDialog([
      automation('a1', {
        is_enabled: false,
        action_type: 'move_to_column',
        target_column_id: null,
      }),
    ])
    expect(wrapper.find('[data-test="board-automation-broken-a1"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
