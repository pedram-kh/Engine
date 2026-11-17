/** PL-1.1 W4 — MarketPicker: add, remove, no duplicates, order kept, code badge. */
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { mountWithApp } from '../waveTestKit'
import MarketPicker from './MarketPicker.vue'

const body = (sel: string): HTMLElement | null => document.body.querySelector(sel)

async function open(wrapper: Awaited<ReturnType<typeof mountWithApp>>): Promise<void> {
  await wrapper.get('[data-test="market-add"]').trigger('click')
  await flushPromises()
}

// A failed test must not leave its teleported dialog behind for the next one.
afterEach(() => {
  document.body.innerHTML = ''
})

describe('MarketPicker (PL-1.1 W4)', () => {
  it('shows a code badge and the country name for each picked market, in the order given', async () => {
    const w = await mountWithApp(MarketPicker, { modelValue: ['PT', 'ES'] })
    const chips = w.findAll('[data-test^="market-chip-"]')
    expect(chips.map((c) => c.attributes('data-test'))).toEqual([
      'market-chip-PT',
      'market-chip-ES',
    ])
    expect(w.get('[data-test="market-code-PT"]').text()).toBe('PT')
    expect(chips[0]?.text()).toContain('Portugal')
    w.unmount()
  })

  it('adds a market at the end and keeps the order picked', async () => {
    const w = await mountWithApp(MarketPicker, { modelValue: ['PT', 'ES'] })
    await open(w)
    body('[data-test="market-option-FR"]')?.click()
    await flushPromises()
    expect(w.emitted('update:modelValue')?.[0]).toEqual([['PT', 'ES', 'FR']])
    w.unmount()
  })

  it('does not offer a market that is already picked, so a duplicate cannot be added', async () => {
    const w = await mountWithApp(MarketPicker, { modelValue: ['ES'] })
    await open(w)
    expect(body('[data-test="market-option-ES"]')).toBeNull()
    expect(body('[data-test="market-option-PT"]')).not.toBeNull()
    w.unmount()
  })

  it('removes a market', async () => {
    const w = await mountWithApp(MarketPicker, { modelValue: ['PT', 'ES', 'FR'] })
    await w.get('[data-test="market-chip-ES"] .v-chip__close').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([['PT', 'FR']])
    w.unmount()
  })

  it('stops offering "Add market" at 20', async () => {
    const twenty = [
      'AT',
      'BE',
      'BG',
      'HR',
      'CY',
      'CZ',
      'DK',
      'EE',
      'FI',
      'FR',
      'DE',
      'GR',
      'HU',
      'IE',
      'IT',
      'LV',
      'LT',
      'LU',
      'MT',
      'NL',
    ]
    const w = await mountWithApp(MarketPicker, { modelValue: twenty })
    expect(w.get('[data-test="market-add"]').classes()).toContain('v-chip--disabled')
    w.unmount()
  })
})
