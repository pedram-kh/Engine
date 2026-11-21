/** PL-2.4 — the market tabs: the NAME is shown, the countries are in the list, the list opens by tap and keyboard. */
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import MarketCountriesInfo from '@/modules/waves/components/MarketCountriesInfo.vue'
import { mkt, mountStrategy, overlay } from '../strategyTestKit'
import { marketsOf } from '../useMarkets'
import { makeSummary } from '../strategyTestKit'
import MarketTabs from './MarketTabs.vue'

const HOSTILE = '<img src=x onerror=alert(1)>Evil & "co"'

function markets() {
  return marketsOf(
    makeSummary({
      markets: [
        mkt('SK', 0, 'Slovakia'),
        mkt('UKI', 2, 'UK & Ireland', ['GB', 'IE']),
        mkt('HX', 0, HOSTILE, ['FR', 'DE']),
      ],
    }),
  )
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('MarketTabs (PL-2.4)', () => {
  it('shows the market name, not the code, and marks the active tab', async () => {
    const w = await mountStrategy(MarketTabs, { markets: markets(), active: 'UKI' })
    expect(w.get('[data-test="market-name-UKI"]').text()).toBe('UK & Ireland')
    expect(w.get('[data-test="market-tab-UKI"]').attributes('aria-selected')).toBe('true')
    expect(w.get('[data-test="market-tab-SK"]').attributes('aria-selected')).toBe('false')
    expect(w.get('[data-test="market-tab-SK"]').text()).not.toBe('SK')
  })

  it('renders a hostile name as text, never as markup', async () => {
    const w = await mountStrategy(MarketTabs, { markets: markets(), active: 'SK' })
    expect(w.get('[data-test="market-name-HX"]').text()).toBe(HOSTILE)
    expect(w.find('img').exists()).toBe(false)
  })

  it('emits select with the id when a tab is clicked', async () => {
    const w = await mountStrategy(MarketTabs, { markets: markets(), active: 'SK' })
    await w.get('[data-test="market-tab-UKI"]').trigger('click')
    expect(w.emitted('select')?.[0]).toEqual(['UKI'])
  })

  it('selects by the market ULID, never by the short code', async () => {
    const list = markets().map((m, i) => ({ ...m, id: `01ULID${i}XXXXXXXXXXXXXXXXXXXX` }))
    const w = await mountStrategy(MarketTabs, { markets: list, active: list[0]!.id })
    await w.get('[data-test="market-tab-UKI"]').trigger('click')
    expect(w.emitted('select')?.[0]).toEqual(['01ULID1XXXXXXXXXXXXXXXXXXXX'])
    w.unmount()
  })

  it('has an info button per tab with an accessible name that opens the countries list', async () => {
    const w = await mountStrategy(MarketTabs, { markets: markets(), active: 'SK' })
    const info = w.get('[data-test="market-info-UKI"]')
    expect(info.attributes('aria-label')).toContain('UK & Ireland')
    await info.trigger('click')
    await flushPromises()
    const list = overlay('[data-test="market-countries"]')
    expect(list?.textContent).toContain('UK & Ireland')
    expect(list?.querySelector('[data-test="market-country-GB"]')?.textContent).toContain('GB')
    expect(list?.querySelector('[data-test="market-country-IE"]')?.textContent).toContain('Ireland')
    w.unmount()
  })
})

describe('MarketCountriesInfo (PL-2.4)', () => {
  it('lists the full name and every country, as text', async () => {
    const w = await mountStrategy(MarketCountriesInfo, {
      name: HOSTILE,
      countries: ['FR', 'DE'],
      id: 'x',
    })
    await w.get('[data-test="market-info-x"]').trigger('click')
    await flushPromises()
    expect(overlay('[data-test="market-countries-name"]')?.textContent).toBe(HOSTILE)
    expect(overlay('[data-test="market-countries"] img')).toBeNull()
    expect(overlay('[data-test="market-country-FR"]')).not.toBeNull()
    expect(overlay('[data-test="market-country-DE"]')).not.toBeNull()
    w.unmount()
  })
})
