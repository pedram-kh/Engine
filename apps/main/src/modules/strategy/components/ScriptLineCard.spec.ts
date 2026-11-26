/**
 * PL-3.2b — one line card: open (text, CTA, B-roll with codes, images), collapsed (quote, shots, image chip, chevron), the CTA
 * chip, what a save sends, and the "deleted elsewhere" card that keeps the person's text.
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({ strategyApi: { saveLine: vi.fn() } }))

import { strategyApi } from '../api/strategy.api'
import { makeBroll, makeImage, makeLine, makeScript } from '../scriptsTestKit'
import { mountStrategy } from '../strategyTestKit'
import ScriptLineCard from './ScriptLineCard.vue'

const SCOPE = { agencyId: 'A', brandId: 'B', waveId: 'W' } as never
const ok = () => Promise.resolve(null)

async function mountCard(line = makeLine(1), over: Record<string, unknown> = {}) {
  const w = await mountStrategy(ScriptLineCard, {
    line,
    scriptId: 'S',
    scope: SCOPE,
    languages: ['en'],
    view: { mode: 'single', language: 'en' },
    index: 0,
    count: 3,
    initiallyOpen: true,
    textMax: 2000,
    brollMax: 500,
    brollUsed: line.broll.length,
    brollLimit: 12,
    canAddLine: true,
    imageLimits: { images: 10, bytes: 300 * 1024 * 1024 },
    scriptBytes: 0,
    dragging: null,
    over: null,
    ghost: false,
    removeFn: ok,
    addBrollFn: ok,
    removeBrollFn: ok,
    moveBrollFn: ok,
    debounceMs: 5,
    ...over,
  })
  await flushPromises()
  return w
}
const id = (l = makeLine(1)) => l.id
const bodyShut = (w: Awaited<ReturnType<typeof mountCard>>) =>
  (w.get('.lcard__body').element as HTMLElement).style.display === 'none'

describe('ScriptLineCard (PL-3.2b)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('open: "Line 2" for the first line, its B-roll with codes from the line number, no CTA chip', async () => {
    const line = makeLine(1, { broll: [makeBroll(2, 1), makeBroll(2, 2)] })
    const w = await mountCard(line)
    expect(w.get(`[data-test="script-line-name-${id()}"]`).text()).toBe('Line 2')
    expect(w.findAll('[data-test^="script-broll-code-"]').map((n) => n.text())).toEqual([
      '2_1',
      '2_2',
    ])
    expect(w.find(`[data-test="script-line-cta-chip-${id()}"]`).exists()).toBe(false)
  })

  it('a CTA line wears the chip next to its name', async () => {
    const w = await mountCard(makeLine(1, { cta: true }))
    expect(w.find(`[data-test="script-line-cta-chip-${id()}"]`).exists()).toBe(true)
  })

  it('collapsed: the quoted text, the shots count and the image chip; the body is hidden', async () => {
    const line = makeLine(1, {
      texts: { en: 'Hello there' },
      broll: [makeBroll(2, 1), makeBroll(2, 2), makeBroll(2, 3), makeBroll(2, 4)],
      images: [makeImage(1)],
    })
    const w = await mountCard(line, { initiallyOpen: false })
    expect(w.get(`[data-test="script-line-summary-${line.id}"]`).text()).toContain('Hello there')
    expect(w.get(`[data-test="script-line-shots-${line.id}"]`).text()).toContain('4')
    expect(w.find(`[data-test="script-line-imgchip-${line.id}"]`).exists()).toBe(true)
    expect(bodyShut(w)).toBe(true)
  })

  it('the toggle opens and shuts the card', async () => {
    const w = await mountCard(makeLine(1), { initiallyOpen: false })
    expect(bodyShut(w)).toBe(true)
    await w.get(`[data-test="script-line-toggle-${id()}"]`).trigger('click')
    expect(bodyShut(w)).toBe(false)
    await w.get(`[data-test="script-line-toggle-${id()}"]`).trigger('click')
    expect(bodyShut(w)).toBe(true)
  })

  it('typing saves the line with its revision after the pause, and only the text that changed', async () => {
    const line = makeLine(1)
    const saved = makeScript(1, { lines: [{ ...line, texts: { en: 'New words' }, revision: 2 }] })
    vi.mocked(strategyApi.saveLine).mockResolvedValue({ data: saved })
    const w = await mountCard(line)
    const area = w.get(`[data-test="script-line-text-${line.id}"] textarea`)
    ;(area.element as HTMLTextAreaElement).value = 'New words'
    await area.trigger('input')
    await vi.waitFor(() => expect(strategyApi.saveLine).toHaveBeenCalledTimes(1))
    const call = vi.mocked(strategyApi.saveLine).mock.calls[0]!
    expect(call[2]).toBe(line.id)
    expect(JSON.stringify(call[3])).toContain('New words')
    expect((call[3] as { revision: number }).revision).toBe(1)
    await flushPromises()
    expect(w.emitted('saved')?.[0]?.[0]).toEqual(saved)
  })

  it('a line deleted elsewhere keeps the typed text on screen: the box says so, Close tells the list', async () => {
    const line = makeLine(1, { texts: { en: 'typed text' } })
    const w = await mountCard(line, { ghost: true })
    expect(w.find(`[data-test="script-line-gone-${line.id}"]`).exists()).toBe(true)
    expect(
      (w.get(`[data-test="script-line-text-${line.id}"] textarea`).element as HTMLTextAreaElement)
        .value,
    ).toBe('typed text')
    await w.get(`[data-test="script-line-gone-close-${line.id}"]`).trigger('click')
    expect(w.emitted('dismiss')).toHaveLength(1)
  })
})
