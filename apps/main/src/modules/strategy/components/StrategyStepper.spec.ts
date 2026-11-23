/**
 * PL-2.3 — the stepper: step 4 is live with "n of m approved"; steps 5 and 6 carry no lock and no "Locked until" text,
 * say "Not available yet", and show the counts warning while any market is not fully approved (a message, never a block).
 */
import { afterEach, describe, expect, it } from 'vitest'

import { BRAND_ULID, makeSummary, mountStrategy, WAVE_ULID } from '../strategyTestKit'
import StrategyStepper from './StrategyStepper.vue'

/** A summary shaped like the server's: one entry per market of the wave, empty markets included. [total, approved, changes requested] */
type Tuple = [number, number, number]
const MARKETS = ['SK', 'RO', 'CZ']
const concepts = (...markets: Tuple[]) => {
  const by = markets.map(([total, approved, changes], i) => ({
    market: MARKETS[i] as string,
    total,
    approved,
    awaiting: total - approved - changes,
    changes_requested: changes,
    complete: total > 0 && approved === total,
    under_minimum: total < 3,
  }))
  const sum = (k: 'total' | 'approved' | 'awaiting' | 'changes_requested') =>
    by.reduce((n, m) => n + m[k], 0)
  return {
    total: sum('total'),
    approved: sum('approved'),
    awaiting: sum('awaiting'),
    changes_requested: sum('changes_requested'),
    markets_complete: by.filter((m) => m.complete).length,
    by_market: by,
  }
}

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
    const w = await mountWith(concepts([2, 1, 0]))
    expect(w.get('[data-test="strategy-step-concepts-status"]').text()).toBe('1 of 2 approved')
    w.unmount()
    const none = await mountWith(concepts([0, 0, 0]))
    expect(none.get('[data-test="strategy-step-concepts-status"]').text()).toBe('No concepts yet')
    none.unmount()
  })

  it('steps 5 and 6: no lock, no "Locked until", "Not available yet", and the counts warning only while not all approved', async () => {
    const w = await mountWith(concepts([2, 1, 0]))
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

    const done = await mountWith(concepts([2, 2, 0]))
    for (const key of ['creators', 'scripts']) {
      expect(done.find(`[data-test="strategy-step-${key}-warning"]`).exists()).toBe(false)
      expect(done.get(`[data-test="strategy-step-${key}"]`).attributes('aria-disabled')).toBe(
        'true',
      )
    }
    done.unmount()
  })

  // D1: the warning appears only when there is no concept, or at least one concept is not approved. Markets combine by
  // summing: a market with no concept is not counted against the others.
  const COMBOS: { name: string; markets: Tuple[]; step4: string; warning: string | null }[] = [
    {
      name: 'no concepts, one market',
      markets: [[0, 0, 0]],
      step4: 'No concepts yet',
      warning: 'No concepts yet',
    },
    {
      name: 'no concepts, three markets',
      markets: [
        [0, 0, 0],
        [0, 0, 0],
        [0, 0, 0],
      ],
      step4: 'No concepts yet',
      warning: 'No concepts yet',
    },
    {
      name: 'some approved',
      markets: [[3, 1, 0]],
      step4: '1 of 3 approved',
      warning: 'Concepts: 1 of 3 approved',
    },
    {
      name: 'none approved',
      markets: [[2, 0, 0]],
      step4: '0 of 2 approved',
      warning: 'Concepts: 0 of 2 approved',
    },
    {
      name: 'all approved, one market',
      markets: [[2, 2, 0]],
      step4: '2 of 2 approved',
      warning: null,
    },
    {
      name: 'one changes requested',
      markets: [[3, 2, 1]],
      step4: '2 of 3 approved',
      warning: 'Concepts: 2 of 3 approved',
    },
    {
      name: 'all approved in one market, the others empty (walk Wave 2)',
      markets: [
        [2, 2, 0],
        [0, 0, 0],
        [0, 0, 0],
      ],
      step4: '2 of 2 approved',
      warning: null,
    },
    {
      name: 'two markets, both all approved',
      markets: [
        [2, 2, 0],
        [4, 4, 0],
      ],
      step4: '6 of 6 approved',
      warning: null,
    },
    {
      name: 'two markets, one all approved and one not',
      markets: [
        [2, 2, 0],
        [3, 1, 1],
      ],
      step4: '3 of 5 approved',
      warning: 'Concepts: 3 of 5 approved',
    },
    {
      name: 'three markets: approved, changes requested, empty',
      markets: [
        [2, 2, 0],
        [2, 0, 2],
        [0, 0, 0],
      ],
      step4: '2 of 4 approved',
      warning: 'Concepts: 2 of 4 approved',
    },
  ]
  for (const c of COMBOS) {
    it(`D1 combination: ${c.name}`, async () => {
      const w = await mountWith(concepts(...c.markets))
      expect(w.get('[data-test="strategy-step-concepts-status"]').text()).toBe(c.step4)
      for (const key of ['creators', 'scripts']) {
        expect(w.get(`[data-test="strategy-step-${key}-status"]`).text()).toContain(
          'Not available yet',
        )
        const warn = w.find(`[data-test="strategy-step-${key}-warning"]`)
        if (c.warning === null) expect(warn.exists()).toBe(false)
        else expect(warn.text()).toBe(c.warning)
      }
      // Nothing else on steps 5 and 6: no lock, never openable here (built in PL-3).
      expect(w.find('.mdi-lock-outline, .mdi-lock').exists()).toBe(false)
      expect(w.get('[data-test="strategy-step-creators"]').attributes('aria-disabled')).toBe('true')
      w.unmount()
    })
  }

  it('PL-2.5: each step shows its real state (done with a check, in progress with its number, not started empty) and its status line', async () => {
    const summary = makeSummary({ usp_count: 5, persona_count: 3, concepts: concepts([0, 0, 0]) })
    summary.attributes.steps = {
      usps: {
        state: 'done',
        count: 5,
        marked: true,
        warnings: [],
        completed_by: null,
        completed_at: null,
      },
      personas: { state: 'in_progress', markets_done: 1, markets_total: 2, markets: [] },
      matrix: { state: 'not_started', markets_done: 0, markets_total: 2, markets: [] },
      concepts: { state: 'not_started', markets_done: 0, markets_total: 2, markets: [] },
    }
    const w = await mountStrategy(StrategyStepper, {
      summary,
      brandId: BRAND_ULID,
      waveId: WAVE_ULID,
      active: 'usps',
    })
    const state = (k: string) => w.get(`[data-test="strategy-step-${k}"]`).attributes('data-state')
    expect([state('usps'), state('personas'), state('matrix')]).toEqual([
      'done',
      'in_progress',
      'not_started',
    ])
    expect(w.find('[data-test="strategy-step-usps"] .strategy-step__circle i').exists()).toBe(true)
    expect(w.get('[data-test="strategy-step-personas"] .strategy-step__circle').text()).toBe('2')
    expect(w.find('[data-test="strategy-step-matrix"] .strategy-step__circle i').exists()).toBe(
      false,
    )
    expect(w.get('[data-test="strategy-step-usps-status"]').text()).toBe('Complete · 5 USPs')
    expect(w.get('[data-test="strategy-step-personas-status"]').text()).toBe(
      '3 personas · 1 of 2 markets',
    )
    w.unmount()
  })

  it('PL-2.5 walk N4: an in-progress per-market step shows its counts AND its markets; done says what it shows; not started keeps the empty wording', async () => {
    const summary = makeSummary({
      usp_count: 5,
      persona_count: 3,
      matrix: { cells_set: 12, cells_total: 24, by_market: [] },
      concepts: concepts([3, 2, 0]),
    })
    summary.attributes.steps = {
      usps: {
        state: 'in_progress',
        count: 5,
        marked: false,
        warnings: [],
        completed_by: null,
        completed_at: null,
      },
      personas: { state: 'in_progress', markets_done: 1, markets_total: 2, markets: [] },
      matrix: { state: 'in_progress', markets_done: 1, markets_total: 2, markets: [] },
      concepts: { state: 'in_progress', markets_done: 1, markets_total: 2, markets: [] },
    }
    const w = await mountStrategy(StrategyStepper, {
      summary,
      brandId: BRAND_ULID,
      waveId: WAVE_ULID,
      active: 'usps',
    })
    const line = (k: string) => w.get(`[data-test="strategy-step-${k}-status"]`).text()
    expect(line('usps')).toBe('5 USPs')
    expect(line('personas')).toBe('3 personas · 1 of 2 markets')
    expect(line('matrix')).toBe('12 of 24 cells set · 1 of 2 markets')
    expect(line('concepts')).toBe('2 of 3 approved · 1 of 2 markets')
    w.unmount()

    summary.attributes.steps.personas = {
      state: 'done',
      markets_done: 2,
      markets_total: 2,
      markets: [],
    }
    summary.attributes.steps.matrix = {
      state: 'done',
      markets_done: 2,
      markets_total: 2,
      markets: [],
    }
    summary.attributes.steps.concepts = {
      state: 'done',
      markets_done: 2,
      markets_total: 2,
      markets: [],
    }
    const done = await mountStrategy(StrategyStepper, {
      summary,
      brandId: BRAND_ULID,
      waveId: WAVE_ULID,
      active: 'usps',
    })
    const d = (k: string) => done.get(`[data-test="strategy-step-${k}-status"]`).text()
    expect(d('personas')).toBe('Complete · 3 personas')
    expect(d('matrix')).toBe('Complete · 12 of 24 cells set')
    expect(d('concepts')).toBe('Complete · 2 of 3 approved')
    done.unmount()

    // One market: the "n of m market" part is left out; the counts stand alone.
    for (const k of ['personas', 'matrix', 'concepts'] as const) {
      summary.attributes.steps[k] = {
        state: 'in_progress',
        markets_done: 0,
        markets_total: 1,
        markets: [],
      }
    }
    const single = await mountStrategy(StrategyStepper, {
      summary,
      brandId: BRAND_ULID,
      waveId: WAVE_ULID,
      active: 'usps',
    })
    const sg = (k: string) => single.get(`[data-test="strategy-step-${k}-status"]`).text()
    expect(sg('personas')).toBe('3 personas')
    expect(sg('matrix')).toBe('12 of 24 cells set')
    expect(sg('concepts')).toBe('2 of 3 approved')
    single.unmount()
    for (const k of ['personas', 'matrix', 'concepts'] as const) {
      summary.attributes.steps[k] = {
        state: 'done',
        markets_done: 2,
        markets_total: 2,
        markets: [],
      }
    }

    const empty = await mountStrategy(StrategyStepper, {
      summary: makeSummary(),
      brandId: BRAND_ULID,
      waveId: WAVE_ULID,
      active: 'usps',
    })
    expect(empty.get('[data-test="strategy-step-personas-status"]').text()).toBe('No personas yet')
    expect(empty.get('[data-test="strategy-step-matrix-status"]').text()).toBe(
      'Add USPs and personas first',
    )
    empty.unmount()
  })

  it('PL-3.1: step 5 is a live link with its own state and "n of m markets" while the flag is on; step 6 stays as PL-2', async () => {
    const w = await mountStrategy(StrategyStepper, {
      summary: makeSummary(),
      brandId: BRAND_ULID,
      waveId: WAVE_ULID,
      active: 'creators',
      creatorsOn: true,
      creators: { state: 'in_progress', markets_done: 1, markets_total: 2, count: 4 },
    })
    const step = w.get('[data-test="strategy-step-creators"]')
    expect(step.attributes('aria-disabled')).not.toBe('true')
    expect(step.attributes('data-state')).toBe('in_progress')
    expect(step.attributes('href') ?? '').toContain('/strategy/creators')
    const line = w.get('[data-test="strategy-step-creators-status"]').text()
    expect(line).toContain('4')
    expect(line).toContain('1 of 2')
    expect(w.get('[data-test="strategy-step-scripts-status"]').text()).toContain(
      'Not available yet',
    )
    expect(w.get('[data-test="strategy-step-scripts"]').attributes('aria-disabled')).toBe('true')
    w.unmount()
  })

  it('PL-3.1: step 5 shows the concepts warning (never a lock) and no creators and no flag is unchanged from PL-2', async () => {
    const warned = await mountStrategy(StrategyStepper, {
      summary: makeSummary({ concepts: concepts([2, 1, 0]) }),
      brandId: BRAND_ULID,
      waveId: WAVE_ULID,
      active: 'creators',
      creatorsOn: true,
      creators: null,
    })
    expect(warned.get('[data-test="strategy-step-creators-warning"]').text()).toBe(
      'Concepts: 1 of 2 approved',
    )
    expect(warned.find('.mdi-lock-outline, .mdi-lock').exists()).toBe(false)
    warned.unmount()

    const off = await mountStrategy(StrategyStepper, {
      summary: makeSummary({ concepts: concepts([2, 1, 0]) }),
      brandId: BRAND_ULID,
      waveId: WAVE_ULID,
      active: 'concepts',
    })
    expect(off.get('[data-test="strategy-step-creators"]').attributes('aria-disabled')).toBe('true')
    expect(off.get('[data-test="strategy-step-creators-status"]').text()).toContain(
      'Not available yet',
    )
    off.unmount()
  })
})
