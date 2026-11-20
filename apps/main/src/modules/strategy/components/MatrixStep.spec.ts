/**
 * PL-2.2 — step 3, the matrix: the header per persona, the cells and their levels, "Not set", the editor (message-line
 * rule, only changed fields sent, Clear, errors above the buttons), the empty states, and hostile text as plain text.
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@catalyst/api-client'
import type { MatrixManualAngle } from '@catalyst/api-client'

vi.mock('../api/strategy.api', () => ({
  strategyApi: { matrix: vi.fn(), saveCells: vi.fn(), useMatrixLine: vi.fn() },
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

async function mountStep(
  cells = [makeMatrixCell(p1, usps[0]!, 'priority')],
  personas = [p1, p2],
  manual: MatrixManualAngle[] = [],
) {
  vi.mocked(strategyApi.matrix).mockResolvedValue({
    data: makeMatrix('SK', usps, personas, cells, manual),
  })
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

  it('X11: opening, closing and reopening quickly leaves one clean editor, nothing carried over', async () => {
    const w = await mountStep()
    await open(w, 1)
    await click('[data-test="matrix-level-support"]')
    await type('[data-test="matrix-line-input"]', 'left over')
    await click('[data-test="matrix-cancel"]')
    await open(w, 2)
    await click('[data-test="matrix-cancel"]')
    await open(w, 3)
    expect(document.body.querySelectorAll('[data-test="matrix-editor"]')).toHaveLength(1)
    expect((overlay('[data-test="matrix-line-input"]') as HTMLTextAreaElement).value).toBe('')
    expect(overlay('[data-test="matrix-level-support"]')?.getAttribute('aria-checked')).toBe(
      'false',
    )
    w.unmount()
  })
})

describe('PL-2.2 ruling change: manual angle and cell must not disagree unseen', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })
  const hand = (text: string, persona = p1, usp = usps[0]!): MatrixManualAngle => ({
    persona: persona.id,
    usp: usp.id,
    angle: 'ANGLE000000000000000000001',
    text,
  })

  it('R1: an EMPTY message line is prefilled with the manual angle text, and saving sends it as the line', async () => {
    const low = makeMatrixCell(p1, usps[0]!, 'low')
    const w = await mountStep([low], [p1, p2], [hand('Mine, by hand')])
    await open(w, 0)
    const input = overlay('[data-test="matrix-line-input"]') as HTMLTextAreaElement
    expect(input.value).toBe('Mine, by hand')
    expect(overlay('[data-test="matrix-line-prefilled"]')).not.toBeNull()
    await click('[data-test="matrix-level-support"]')
    vi.mocked(strategyApi.saveCells).mockResolvedValue({
      data: {
        cells: [
          makeMatrixCell(p1, usps[0]!, 'support', {
            message_line: 'Mine, by hand',
            has_manual_angle: true,
            manual_angle: 'ANGLE000000000000000000001',
            manual_text: 'Mine, by hand',
          }),
        ],
        progress: { cells_set: 1, cells_total: 6, by_market: [] },
      },
    })
    await click('[data-test="matrix-save"]')
    expect(strategyApi.saveCells).toHaveBeenCalledWith(SCOPE, 'SK', [
      { persona: p1.id, usp: usps[0]!.id, level: 'support', message_line: 'Mine, by hand' },
    ])
    w.unmount()
  })

  it('R1: a cell that is not set yet is prefilled too; an existing message line is never replaced', async () => {
    const notSet = await mountStep([], [p1, p2], [hand('Mine, by hand')])
    await open(notSet, 0)
    expect((overlay('[data-test="matrix-line-input"]') as HTMLTextAreaElement).value).toBe(
      'Mine, by hand',
    )
    notSet.unmount()
    document.body.innerHTML = ''

    const withLine = makeMatrixCell(p1, usps[0]!, 'priority', { message_line: 'Already there' })
    const w = await mountStep([withLine], [p1, p2], [hand('Mine, by hand')])
    await open(w, 0)
    expect((overlay('[data-test="matrix-line-input"]') as HTMLTextAreaElement).value).toBe(
      'Already there',
    )
    expect(overlay('[data-test="matrix-line-prefilled"]')).toBeNull()
    w.unmount()
  })

  it('R1: opening the editor never changes the angle: no request until Save', async () => {
    const w = await mountStep([makeMatrixCell(p1, usps[0]!, 'low')], [p1, p2], [hand('Mine')])
    await open(w, 0)
    await click('[data-test="matrix-cancel"]')
    expect(strategyApi.saveCells).not.toHaveBeenCalled()
    expect(strategyApi.useMatrixLine).not.toHaveBeenCalled()
    w.unmount()
  })

  it('R2: texts differ on a Priority cell: the note says so and offers "Use the matrix line"; equal texts show nothing', async () => {
    const differ = makeMatrixCell(p1, usps[0]!, 'priority', { message_line: 'From the cell' })
    const w = await mountStep([differ], [p1, p2], [hand('Mine')])
    expect(w.find('[data-test="matrix-conflict-note"]').exists()).toBe(true)
    expect(w.get('[data-test="matrix-use-line"]').text()).toBe('Use the matrix line')
    w.unmount()

    const same = makeMatrixCell(p1, usps[0]!, 'priority', { message_line: 'Same words' })
    const eq = await mountStep([same], [p1, p2], [hand('Same words')])
    expect(eq.find('[data-test="matrix-conflict-note"]').exists()).toBe(false)
    expect(eq.find('[data-test="matrix-manual-note"]').exists()).toBe(false)
    expect(eq.find('[data-test="matrix-use-line"]').exists()).toBe(false)
    eq.unmount()
  })

  it('R2: a Low cell keeps the plain "kept as written" note and no action', async () => {
    const low = makeMatrixCell(p1, usps[0]!, 'low')
    const w = await mountStep([low], [p1, p2], [hand('Mine')])
    expect(w.find('[data-test="matrix-manual-note"]').exists()).toBe(true)
    expect(w.find('[data-test="matrix-use-line"]').exists()).toBe(false)
    expect(w.get('[data-test="matrix-manual-note"]').text()).toContain('manually added')
    w.unmount()
  })

  it('R2: the action asks first, shows BOTH texts as plain text, then converts and the note goes away', async () => {
    const differ = makeMatrixCell(p1, usps[0]!, 'priority', { message_line: 'From the cell' })
    const w = await mountStep([differ], [p1, p2], [hand(HOSTILE)])
    vi.mocked(strategyApi.useMatrixLine).mockResolvedValue({} as never)
    await w.get('[data-test="matrix-use-line"]').trigger('click')
    await flushPromises()
    expect(strategyApi.useMatrixLine).not.toHaveBeenCalled()
    expect(overlay('[data-test="use-line-manual"]')?.textContent).toBe(HOSTILE)
    expect(overlay('[data-test="use-line-matrix"]')?.textContent).toBe('From the cell')
    expect(overlay('[data-test="use-line-manual"] img')).toBeNull()

    await click('[data-test="confirm-ok"]')
    expect(strategyApi.useMatrixLine).toHaveBeenCalledWith(
      SCOPE,
      p1.id,
      'ANGLE000000000000000000001',
    )
    expect(w.emitted('changed')).toHaveLength(1)
    expect(w.find('[data-test="matrix-conflict-note"]').exists()).toBe(false)
    w.unmount()
  })

  it('R2: the dialog can be cancelled, closed and reopened quickly without sending anything', async () => {
    const differ = makeMatrixCell(p1, usps[0]!, 'priority', { message_line: 'From the cell' })
    const w = await mountStep([differ], [p1, p2], [hand('Mine')])
    for (let i = 0; i < 3; i++) {
      await w.get('[data-test="matrix-use-line"]').trigger('click')
      await flushPromises()
      expect(overlay('[data-test="use-line-texts"]')).not.toBeNull()
      await click('[data-test="confirm-cancel"]')
    }
    expect(strategyApi.useMatrixLine).not.toHaveBeenCalled()
    w.unmount()
  })

  it('R2: a refusal stays in the dialog and the angle is not treated as converted', async () => {
    const differ = makeMatrixCell(p1, usps[0]!, 'priority', { message_line: 'From the cell' })
    const w = await mountStep([differ], [p1, p2], [hand('Mine')])
    vi.mocked(strategyApi.useMatrixLine).mockRejectedValue(
      new ApiError({ status: 422, code: 'angle.nothing_to_resolve', message: 'x' }),
    )
    await w.get('[data-test="matrix-use-line"]').trigger('click')
    await flushPromises()
    await click('[data-test="confirm-ok"]')
    expect(overlay('[data-test="confirm-error"]')).not.toBeNull()
    expect(w.find('[data-test="matrix-conflict-note"]').exists()).toBe(true)
    expect(w.emitted('changed')).toBeUndefined()
    w.unmount()
  })

  it('P4: while Save is off because a Priority cell has no message line, the reason sits beside the field', async () => {
    const w = await mountStep()
    await open(w, 1)
    await click('[data-test="matrix-level-priority"]')
    const hint = overlay('[data-test="matrix-line-hint"]')
    expect(hint?.textContent).toContain('message line')
    expect(overlay('[data-test="matrix-save"]')?.hasAttribute('disabled')).toBe(true)
    // The field is in its error state while the rule is unmet (the red hint and border are asserted in the browser).
    expect(overlay('[data-test="matrix-line-input"]')?.getAttribute('aria-invalid')).toBe('true')
    expect(overlay('[data-test="matrix-save"]')?.getAttribute('aria-describedby')).toBe(
      'mx-line-hint',
    )
    w.unmount()
  })

  it('P5: an empty market shows a proper empty state with a primary "Add personas" to step 2 on that market', async () => {
    const w = await mountStep([], [])
    const box = w.get('[data-test="matrix-no-personas"]')
    expect(box.find('.v-icon').exists()).toBe(true)
    expect(box.find('h3').exists()).toBe(true)
    const cta = w.get('[data-test="matrix-no-personas-cta"]')
    expect(cta.text()).toBe('Add personas')
    expect(cta.attributes('href')).toContain('/strategy/personas')
    expect(cta.attributes('href')).toContain('market=SK')
    w.unmount()
  })
})
