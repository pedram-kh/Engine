/** PL-2.4 — the market builder on its own: the limit of 40 countries per market, as text and as a disabled add. */
import { afterEach, describe, expect, it } from 'vitest'

import { COUNTRY_OPTIONS } from '@catalyst/api-client'
import { emptyDraft, MAX_COUNTRIES } from '../marketDrafts'
import { mountWithApp } from '../waveTestKit'
import MarketBuilder from './MarketBuilder.vue'

afterEach(() => {
  document.body.innerHTML = ''
})

describe('MarketBuilder (PL-2.4)', () => {
  it('stops at 40 countries: the add chip is disabled and the limit is said', async () => {
    const d = emptyDraft()
    d.name = 'Everywhere'
    d.code = 'ALL'
    d.countries = COUNTRY_OPTIONS.slice(0, MAX_COUNTRIES).map((c) => c.code)
    const w = await mountWithApp(MarketBuilder, { modelValue: [d], errors: {} })
    expect(MAX_COUNTRIES).toBe(40)
    expect(w.get('[data-test="market-add-country"]').classes()).toContain('v-chip--disabled')
    expect(w.text()).toContain('40')
    w.unmount()
  })

  it('below 40 the add chip is enabled', async () => {
    const d = emptyDraft()
    d.countries = ['SE']
    const w = await mountWithApp(MarketBuilder, { modelValue: [d], errors: {} })
    expect(w.get('[data-test="market-add-country"]').classes()).not.toContain('v-chip--disabled')
    w.unmount()
  })
})
