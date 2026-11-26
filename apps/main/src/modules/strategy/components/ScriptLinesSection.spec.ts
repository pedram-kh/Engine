/**
 * PL-3.2b — the "Script lines" section: lines in order with their numbers (from 2), the empty text, Add and its limit text (only
 * near the limit), a move that shows at once and sends the WHOLE id list, and a stale list that reloads and says so.
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({
  strategyApi: { addLine: vi.fn(), orderLines: vi.fn(), saveLine: vi.fn() },
}))

import { strategyApi } from '../api/strategy.api'
import { LIMITS, makeBroll, makeLine, makeScript, orderMismatch } from '../scriptsTestKit'
import { mountStrategy } from '../strategyTestKit'
import ScriptLinesSection from './ScriptLinesSection.vue'

const SCOPE = { agencyId: 'A', brandId: 'B', waveId: 'W' } as never

async function mountSection(script = makeScript(1)) {
  const w = await mountStrategy(ScriptLinesSection, {
    script,
    scope: SCOPE,
    view: { mode: 'single', language: 'en' },
    textMax: 2000,
    brollMax: 500,
  })
  await flushPromises()
  return w
}
const withLines = (n: number) =>
  makeScript(1, {
    lines: Array.from({ length: n }, (_, i) => makeLine(i + 1)),
    usage: { hooks: 3, lines: n, broll: 0, image_bytes: 0 },
  })

describe('ScriptLinesSection (PL-3.2b)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('a script with no lines says so and still offers Add line', async () => {
    const w = await mountSection()
    expect(w.find('[data-test="script-lines-empty"]').exists()).toBe(true)
    expect(w.get('[data-test="script-add-line"]').attributes('disabled')).toBeUndefined()
  })

  it('draws the lines in order, numbered from 2, with their B-roll codes', async () => {
    const s = withLines(3)
    s.lines[0]!.broll = [makeBroll(2, 1), makeBroll(2, 2)]
    const w = await mountSection(s)
    expect(w.find('[data-test="script-lines-empty"]').exists()).toBe(false)
    const names = w.findAll('[data-test^="script-line-name-"]').map((n) => n.text())
    expect(names.map((n) => n.replace(/\D/g, ''))).toEqual(['2', '3', '4'])
    const codes = w.findAll('[data-test^="script-broll-code-"]').map((n) => n.text())
    expect(codes).toEqual(['2_1', '2_2'])
  })

  it('Add line sends one request; the answer goes up as the new script', async () => {
    const before = withLines(1)
    const after = withLines(2)
    vi.mocked(strategyApi.addLine).mockResolvedValue({ data: after })
    const w = await mountSection(before)
    await w.get('[data-test="script-add-line"]').trigger('click')
    await flushPromises()
    expect(strategyApi.addLine).toHaveBeenCalledTimes(1)
    expect(w.emitted('replace')?.[0]?.[0]).toEqual(after)
  })

  it('the limit text shows only near the limit, and Add is off at 30 of 30', async () => {
    const few = await mountSection(withLines(24))
    expect(few.find('[data-test="script-lines-usage"]').exists()).toBe(false)
    const near = await mountSection(withLines(25))
    expect(near.find('[data-test="script-lines-usage"]').exists()).toBe(true)
    const full = await mountSection(withLines(LIMITS.lines_per_script))
    expect(full.get('[data-test="script-add-line"]').attributes('disabled')).toBeDefined()
    expect(full.get('[data-test="script-lines-usage"]').text()).toContain('30')
  })

  it('the arrow keys on a grip move the line: numbers follow at once and the whole id list is sent', async () => {
    const s = withLines(3)
    const [a, b, c] = s.lines.map((l) => l.id) as [string, string, string]
    vi.mocked(strategyApi.orderLines).mockResolvedValue({
      data: { ...s, lines: [s.lines[1]!, s.lines[0]!, s.lines[2]!] },
    })
    const w = await mountSection(s)
    await w.get(`[data-test="script-line-grip-${a}"]`).trigger('keydown', { key: 'ArrowDown' })
    await flushPromises()
    // shown at once: the first emitted script already has the new order
    const first = w.emitted('replace')?.[0]?.[0] as ReturnType<typeof withLines>
    expect(first.lines.map((l) => l.id)).toEqual([b, a, c])
    expect(strategyApi.orderLines).toHaveBeenCalledWith(SCOPE, s.id, [b, a, c])
  })

  it('a move off either end of the list sends nothing', async () => {
    const s = withLines(2)
    const w = await mountSection(s)
    await w
      .get(`[data-test="script-line-grip-${s.lines[0]!.id}"]`)
      .trigger('keydown', { key: 'ArrowUp' })
    await flushPromises()
    expect(strategyApi.orderLines).not.toHaveBeenCalled()
  })

  it('a stale list (a line added or removed elsewhere): the script is read again and the person is told; texts are untouched', async () => {
    const s = withLines(2)
    vi.mocked(strategyApi.orderLines).mockRejectedValue(orderMismatch())
    const w = await mountSection(s)
    await w
      .get(`[data-test="script-line-grip-${s.lines[0]!.id}"]`)
      .trigger('keydown', { key: 'ArrowDown' })
    await flushPromises()
    expect(w.emitted('refresh')).toBeDefined()
    expect(w.get('[data-test="script-lines-notice"]').text()).toContain('changed elsewhere')
  })
})
