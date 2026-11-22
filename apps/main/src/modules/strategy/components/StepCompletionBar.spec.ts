/**
 * PL-2.5 — the completion bar: the state it shows, Mark as complete / Reopen, warnings shown in a confirm that never
 * blocks, the refusal for an empty step, and no button on Concepts (automatic).
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({
  strategyApi: { markComplete: vi.fn(), reopen: vi.fn() },
}))

import { strategyApi } from '../api/strategy.api'
import { makeSummary, mkt, mountStrategy, overlay, overlayText } from '../strategyTestKit'
import StepCompletionBar from './StepCompletionBar.vue'

type Summary = ReturnType<typeof makeSummary>

function uspsSummary(count: number, marked = false): Summary {
  const s = makeSummary({ usp_count: count, markets: [mkt('SK', 0), mkt('RO', 0)] })
  s.attributes.steps.usps = {
    state: marked ? 'done' : count > 0 ? 'in_progress' : 'not_started',
    count,
    marked,
    warnings: [],
    completed_by: marked ? { name: 'Eva Lead', former: false } : null,
    completed_at: marked ? '2026-11-20T10:00:00+00:00' : null,
  }
  return s
}

const DATA = {
  steps: makeSummary().attributes.steps,
  landing: makeSummary().attributes.landing,
  note_counts: makeSummary().attributes.note_counts,
}

async function mountBar(summary: Summary, step: 'usps' | 'concepts' = 'usps') {
  const w = await mountStrategy(StepCompletionBar, {
    summary,
    step,
    market: null,
    marketName: 'Slovakia',
  })
  await flushPromises()
  return w
}

describe('StepCompletionBar (PL-2.5)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('marks a clean step at once, with no dialog', async () => {
    vi.mocked(strategyApi.markComplete).mockResolvedValue({ data: DATA })
    const w = await mountBar(uspsSummary(5))
    await w.get('[data-test="completion-mark"]').trigger('click')
    await flushPromises()
    expect(strategyApi.markComplete).toHaveBeenCalledTimes(1)
    expect(overlay('[data-test="completion-dialog"]')).toBeNull()
    expect(w.emitted('completed')).toHaveLength(1)
    w.unmount()
  })

  it('shows the warnings in a confirm and marks only after "Mark as complete anyway"', async () => {
    vi.mocked(strategyApi.markComplete).mockResolvedValue({ data: DATA })
    const w = await mountBar(uspsSummary(2))
    await w.get('[data-test="completion-mark"]').trigger('click')
    await flushPromises()
    expect(strategyApi.markComplete).not.toHaveBeenCalled()
    expect(overlayText()).toContain('2 USPs so far')
    overlay('[data-test="confirm-ok"]')?.click()
    await flushPromises()
    expect(strategyApi.markComplete).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('refuses an empty step without calling the server, and offers no confirm button', async () => {
    const w = await mountBar(uspsSummary(0))
    await w.get('[data-test="completion-mark"]').trigger('click')
    await flushPromises()
    expect(overlayText()).toContain('Add at least one USP')
    expect(overlay('[data-test="confirm-ok"]')).toBeNull()
    await flushPromises()
    expect(strategyApi.markComplete).not.toHaveBeenCalled()
    w.unmount()
  })

  it('a marked step shows who marked it and a Reopen button that reopens', async () => {
    vi.mocked(strategyApi.reopen).mockResolvedValue({ data: DATA })
    const w = await mountBar(uspsSummary(5, true))
    expect(w.get('[data-test="completion-marked-by"]').text()).toContain('Eva Lead')
    expect(w.find('[data-test="completion-mark"]').exists()).toBe(false)
    await w.get('[data-test="completion-reopen"]').trigger('click')
    await flushPromises()
    expect(strategyApi.reopen).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('Concepts is automatic: the state and its explanation, and no button', async () => {
    const w = await mountBar(uspsSummary(0), 'concepts')
    expect(w.find('[data-test="completion-auto"]').exists()).toBe(true)
    expect(w.find('[data-test="completion-mark"]').exists()).toBe(false)
    expect(w.find('[data-test="completion-reopen"]').exists()).toBe(false)
    w.unmount()
  })
})
