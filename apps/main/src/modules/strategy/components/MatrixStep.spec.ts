/**
 * PL-2.2 — step 3, the matrix: the header per persona, the cells and their levels, "Not set", the editor (message-line
 * rule, only changed fields sent, Clear, errors above the buttons), the empty states, and hostile text as plain text.
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@catalyst/api-client'

vi.mock('../api/strategy.api', () => ({
  strategyApi: { matrix: vi.fn(), saveCells: vi.fn() },
}))

import { strategyApi } from '../api/strategy.api'
import {
  AGENCY_ULID,
  BRAND_ULID,
  makeMatrix,
  makeMatrixCell,
  makePersona,
  makeSummary,
  makeUsps,
  mountStrategy,
  overlay,
  WAVE_ULID,
} from '../strategyTestKit'
import MatrixCellEditor from './MatrixCellEditor.vue'
import MatrixStep from './MatrixStep.vue'

const SCOPE = { agencyId: AGENCY_ULID, brandId: BRAND_ULID, waveId: WAVE_ULID }
const HOSTILE = '<img src=x onerror=alert(1)>'
const ROUTE = `/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/matrix`
const usps = makeUsps(3)
const p1 = makePersona(1, 'SK')
const p2 = makePersona(2, 'SK')

async function mountStep(cells = [makeMatrixCell(p1, usps[0]!, 'priority')], personas = [p1, p2]) {
  vi.mocked(strategyApi.matrix).mockResolvedValue({ data: makeMatrix('SK', usps, personas, cells) })
  const w = await mountStrategy(
    MatrixStep,
    { summary: makeSummary({}), markets: ['SK', 'RO'] },
    { route: ROUTE },
  )
  await flushPromises()
  return w
}

async function open(w: Awaited<ReturnType<typeof mountStep>>, index: number) {
  await w.findAll('[data-test="matrix-cell"]')[index]?.trigger('click')
  await flushPromises()
}

async function click(selector: string): Promise<void> {
  const el = overlay(selector)
  if (el === null) throw new Error(`no ${selector}`)
  el.click()
  await flushPromises()
}

async function type(selector: string, value: string): Promise<void> {
  const el = overlay(selector) as HTMLTextAreaElement | HTMLInputElement | null
  if (el === null) throw new Error(`no ${selector}`)
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

describe('MatrixStep (PL-2.2)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('X1: loads the first market, one header per persona, a cell per USP and persona, "Not set" where none', async () => {
    const w = await mountStep()
    expect(strategyApi.matrix).toHaveBeenCalledWith(SCOPE, 'SK')
    expect(w.findAll('[data-test="matrix-persona-header"]')).toHaveLength(2)
    expect(w.findAll('[data-test="matrix-usp"]')).toHaveLength(3)
    expect(w.findAll('[data-test="matrix-cell"]')).toHaveLength(6)
    expect(w.findAll('[data-test="matrix-pill"]')).toHaveLength(6)
    expect(w.findAll('[data-test="matrix-lead-none"]')).toHaveLength(1)
    expect(w.get('[data-test="matrix-legend"]').text()).toContain('Priority')
    w.unmount()
  })

  it('X2: no persona in the market and no USP in the wave each show their own line, not a table', async () => {
    const none = await mountStep([], [])
    expect(none.find('[data-test="matrix-table"]').exists()).toBe(false)
    expect(none.find('[data-test="matrix-no-personas"]').exists()).toBe(true)
    none.unmount()

    vi.mocked(strategyApi.matrix).mockResolvedValue({ data: makeMatrix('SK', [], [p1], []) })
    const w = await mountStrategy(
      MatrixStep,
      { summary: makeSummary({}), markets: ['SK'] },
      { route: ROUTE },
    )
    await flushPromises()
    expect(w.find('[data-test="matrix-no-usps"]').exists()).toBe(true)
    w.unmount()
  })

  it('X3: a failed load shows an error with a retry, not an empty table', async () => {
    vi.mocked(strategyApi.matrix).mockRejectedValue(new Error('x'))
    const w = await mountStrategy(
      MatrixStep,
      { summary: makeSummary({}), markets: ['SK'] },
      { route: ROUTE },
    )
    await flushPromises()
    expect(w.find('[data-test="matrix-error"]').exists()).toBe(true)
    expect(w.find('[data-test="matrix-table"]').exists()).toBe(false)
    w.unmount()
  })

  it('X4: Priority without a message line cannot be saved; the reason is shown and nothing is sent', async () => {
    const w = await mountStep()
    await open(w, 1)
    await click('[data-test="matrix-level-priority"]')
    expect(overlay('[data-test="matrix-line-hint"]')).not.toBeNull()
    await click('[data-test="matrix-save"]')
    expect(strategyApi.saveCells).not.toHaveBeenCalled()
    w.unmount()
  })

  it('X5: Low saves with no message line, and only the level is sent', async () => {
    const w = await mountStep()
    await open(w, 1)
    await click('[data-test="matrix-level-low"]')
    vi.mocked(strategyApi.saveCells).mockResolvedValue({
      data: {
        cells: [makeMatrixCell(p2, usps[0]!, 'low')],
        progress: { cells_set: 2, cells_total: 6, by_market: [] },
      },
    })
    await click('[data-test="matrix-save"]')
    expect(strategyApi.saveCells).toHaveBeenCalledWith(SCOPE, 'SK', [
      { persona: p2.id, usp: usps[0]!.id, level: 'low' },
    ])
    expect(w.emitted('changed')).toHaveLength(1)
    w.unmount()
  })

  it('X6: Save on an unchanged cell sends no request and closes', async () => {
    const w = await mountStep()
    await open(w, 0)
    await click('[data-test="matrix-save"]')
    expect(strategyApi.saveCells).not.toHaveBeenCalled()
    expect(w.findComponent(MatrixCellEditor).props('modelValue')).toBe(false)
    w.unmount()
  })

  it('X7: Clear sends clear:true for that cell and the cell goes back to "Not set"', async () => {
    const w = await mountStep()
    await open(w, 0)
    vi.mocked(strategyApi.saveCells).mockResolvedValue({
      data: {
        cells: [makeMatrixCell(p1, usps[0]!, null)],
        progress: { cells_set: 0, cells_total: 6, by_market: [] },
      },
    })
    await click('[data-test="matrix-clear"]')
    expect(strategyApi.saveCells).toHaveBeenCalledWith(SCOPE, 'SK', [
      { persona: p1.id, usp: usps[0]!.id, clear: true },
    ])
    expect(w.findAll('[data-test="matrix-line"]')).toHaveLength(0)
    w.unmount()
  })

  it('X8: a server error is shown inside the editor, above the buttons, and the editor stays open', async () => {
    const w = await mountStep()
    await open(w, 1)
    await click('[data-test="matrix-level-low"]')
    vi.mocked(strategyApi.saveCells).mockRejectedValue(
      new ApiError({ status: 422, code: 'validation.failed', message: 'Nope', details: [] }),
    )
    await click('[data-test="matrix-save"]')
    const editor = overlay('[data-test="matrix-editor"]')
    expect(editor).not.toBeNull()
    const error = overlay('[data-test="matrix-editor-error"]')
    expect(error).not.toBeNull()
    const buttons = overlay('[data-test="matrix-save"]')
    expect(error!.compareDocumentPosition(buttons!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    w.unmount()
  })

  it('X9: a rationale or message line over 140 characters is refused before any request', async () => {
    const w = await mountStep()
    await open(w, 1)
    await click('[data-test="matrix-level-support"]')
    await type('[data-test="matrix-line-input"]', 'x'.repeat(141))
    expect(overlay('[data-test="matrix-line-error"]')).not.toBeNull()
    await click('[data-test="matrix-save"]')
    expect(strategyApi.saveCells).not.toHaveBeenCalled()
    w.unmount()
  })

  it('X10: hostile text in a rationale and a message line is plain text on the cell', async () => {
    const hostile = makeMatrixCell(p1, usps[0]!, 'priority', {
      rationale: HOSTILE,
      message_line: HOSTILE,
    })
    const w = await mountStep([hostile])
    expect(w.get('[data-test="matrix-rationale"]').text()).toContain(HOSTILE)
    expect(w.get('[data-test="matrix-line"]').text()).toContain(HOSTILE)
    expect(w.find('img').exists()).toBe(false)
    w.unmount()
  })

  it('X11: opening, closing and reopening quickly leaves one clean editor', async () => {
    const w = await mountStep()
    await open(w, 1)
    await click('[data-test="matrix-cancel"]')
    await open(w, 2)
    await click('[data-test="matrix-cancel"]')
    await open(w, 1)
    expect(document.body.querySelectorAll('[data-test="matrix-editor"]')).toHaveLength(1)
    expect((overlay('[data-test="matrix-line-input"]') as HTMLTextAreaElement).value).toBe('')
    w.unmount()
  })
})
