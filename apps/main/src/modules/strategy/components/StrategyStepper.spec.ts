/**
 * PL-2.3 — the stepper: step 4 is live with "n of m approved"; steps 5 and 6 carry no lock and no "Locked until" text,
 * say "Not available yet", and show the counts warning while any market is not fully approved (a message, never a block).
 */
import { afterEach, describe, expect, it } from 'vitest'

import { BRAND_ULID, makeSummary, mountStrategy, WAVE_ULID } from '../strategyTestKit'
import StrategyStepper from './StrategyStepper.vue'

const byMarket = (complete: boolean) => [
  {
    market: 'SK',
    total: 2,
    approved: complete ? 2 : 1,
    awaiting: complete ? 0 : 1,
    changes_requested: 0,
    complete,
    under_minimum: true,
  },
]
const concepts = (approved: number, total: number, complete: boolean) => ({
  total,
  approved,
  awaiting: total - approved,
  changes_requested: 0,
  markets_complete: complete ? 1 : 0,
  by_market: total === 0 ? [] : byMarket(complete),
})

async function mountWith(c: ReturnType<typeof concepts>) {
  return mountStrategy(StrategyStepper, {
    summary: makeSummary({ concepts: c }),
    brandId: BRAND_ULID,
    waveId: WAVE_ULID,
    active: 'concepts',
  })
}

describe('StrategyStepper (PL-2.3)', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('step 4 shows "n of m approved"; with no concept it says so', async () => {
    const w = await mountWith(concepts(1, 2, false))
    expect(w.get('[data-test="strategy-step-concepts-status"]').text()).toBe('1 of 2 approved')
    w.unmount()
    const none = await mountWith(concepts(0, 0, false))
    expect(none.get('[data-test="strategy-step-concepts-status"]').text()).toBe('No concepts yet')
    none.unmount()
  })

  it('steps 5 and 6: no lock, no "Locked until", "Not available yet", and the counts warning only while not all approved', async () => {
    const w = await mountWith(concepts(1, 2, false))
    for (const key of ['creators', 'scripts']) {
      const step = w.get(`[data-test="strategy-step-${key}"]`)
      expect(step.find('.mdi-lock-outline, .mdi-lock').exists()).toBe(false)
      expect(w.get(`[data-test="strategy-step-${key}-status"]`).text()).toContain(
        'Not available yet',
      )
      expect(w.get(`[data-test="strategy-step-${key}-warning"]`).text()).toContain(
        'Concepts: 1 of 2 approved',
      )
    }
    expect(w.text()).not.toContain('Locked until')
    w.unmount()

    const done = await mountWith(concepts(2, 2, true))
    for (const key of ['creators', 'scripts']) {
      expect(done.find(`[data-test="strategy-step-${key}-warning"]`).exists()).toBe(false)
      expect(done.get(`[data-test="strategy-step-${key}"]`).attributes('aria-disabled')).toBe(
        'true',
      )
    }
    done.unmount()
  })
})
