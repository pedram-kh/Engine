/**
 * PL-2.5 — the matrix side sheet: it holds the thread only while open, and it closes from the keyboard (Escape)
 * as well as from its button, unless a dialog is open above it.
 */
import { flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { VLayout } from 'vuetify/components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({ strategyApi: { listNotes: vi.fn() } }))

import { strategyApi } from '../api/strategy.api'
import { mountStrategy } from '../strategyTestKit'
import NotesSheet from './NotesSheet.vue'

// A navigation drawer needs a Vuetify layout around it.
const Host = defineComponent({
  props: {
    modelValue: { type: Boolean, default: false },
    market: { type: String, default: null },
    marketName: { type: String, default: '' },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () =>
      h(VLayout, null, () =>
        h(NotesSheet, {
          modelValue: props.modelValue,
          step: 'matrix',
          market: props.market,
          marketName: props.marketName,
          'onUpdate:modelValue': (v: boolean) => emit('update:modelValue', v),
        }),
      )
  },
})

async function mountSheet(open: boolean) {
  vi.mocked(strategyApi.listNotes).mockResolvedValue({ data: [] })
  const w = await mountStrategy(Host, {
    modelValue: open,
    step: 'matrix',
    market: '01MARKET',
    marketName: 'Slovakia',
  })
  await flushPromises()
  return w
}

const esc = () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

describe('NotesSheet (PL-2.5)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('loads the thread only while open', async () => {
    const w = await mountSheet(false)
    expect(strategyApi.listNotes).not.toHaveBeenCalled()
    await w.setProps({ modelValue: true })
    await flushPromises()
    expect(strategyApi.listNotes).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('closes on Escape while open, and stops listening once closed', async () => {
    const w = await mountSheet(true)
    esc()
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual([false])
    await w.setProps({ modelValue: false })
    const count = w.emitted('update:modelValue')?.length ?? 0
    esc()
    expect(w.emitted('update:modelValue')?.length).toBe(count)
    w.unmount()
  })

  it('leaves Escape to an open dialog above it', async () => {
    const w = await mountSheet(true)
    const dialog = document.createElement('div')
    dialog.className = 'v-dialog v-overlay--active'
    document.body.appendChild(dialog)
    esc()
    expect(w.emitted('update:modelValue')).toBeUndefined()
    w.unmount()
  })
})
