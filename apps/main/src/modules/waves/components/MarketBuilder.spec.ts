/** PL-2.4 — the market builder on its own: the limit of 40 countries per market, as text and as a disabled add. */
import { afterEach, describe, expect, it } from 'vitest'

import { COUNTRY_OPTIONS } from '@catalyst/api-client'
import { draftsFromWave, emptyDraft, MAX_COUNTRIES } from '../marketDrafts'
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

  describe('A1: the persona codes tick', () => {
    const saved = [{ id: '01ABC', name: 'UK & Ireland', code: 'UKI', countries: ['GB', 'IE'] }]

    async function mountWith(offer: boolean, code: string) {
      const drafts = draftsFromWave(saved as never)
      drafts[0]!.code = code
      return mountWithApp(MarketBuilder, {
        modelValue: [...drafts, { ...emptyDraft(), name: 'Fresh', code: 'FRE', countries: ['FR'] }],
        errors: {},
        offerPersonaCodes: offer,
      })
    }

    it('shows on an existing market whose code changed, unticked, with its note', async () => {
      const w = await mountWith(true, 'UKX')
      const box = w.get('[data-test="market-card-0"] [data-test="market-rename-codes-box"] input')
      expect((box.element as HTMLInputElement).checked).toBe(false)
      expect(
        w.get('[data-test="market-card-0"] [data-test="market-rename-codes"]').text(),
      ).toContain('UKI1')
      w.unmount()
    })

    it('is absent when the code is unchanged, when not offered, and on a new market', async () => {
      for (const [offer, code] of [
        [true, 'UKI'],
        [false, 'UKX'],
      ] as const) {
        const w = await mountWith(offer, code)
        expect(w.find('[data-test="market-rename-codes"]').exists()).toBe(false)
        w.unmount()
      }
      const w = await mountWith(true, 'UKX')
      expect(w.find('[data-test="market-card-1"] [data-test="market-rename-codes"]').exists()).toBe(
        false,
      )
      w.unmount()
    })

    it('ticking sets the draft flag', async () => {
      const w = await mountWith(true, 'UKX')
      await w.get('[data-test="market-rename-codes-box"] input').setValue(true)
      const emitted = w.emitted('update:modelValue') as unknown as {
        renamePersonaCodes: boolean
      }[][][]
      expect(emitted.at(-1)![0]![0]!.renamePersonaCodes).toBe(true)
      w.unmount()
    })
  })
})
