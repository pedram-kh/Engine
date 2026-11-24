/**
 * PL-3.2a — the brief block: persona and USPs read live, the angle source line and "Use the persona's angle", the over-limit
 * counter, the duration menu (opened, closed, reopened) and what each save sends (only what changed).
 */
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api/strategy.api', () => ({ strategyApi: { saveBrief: vi.fn() } }))

import { strategyApi } from '../api/strategy.api'
import { makeScript } from '../scriptsTestKit'
import { BRAND_ULID, mountStrategy, WAVE_ULID } from '../strategyTestKit'
import ScriptBrief from './ScriptBrief.vue'

const route = (name: string) => ({
  name,
  params: { ulid: BRAND_ULID, waveUlid: WAVE_ULID },
  query: { market: 'SK' },
})
const SCOPE = { agencyId: 'A', brandId: 'B', waveId: 'W' } as never

async function mountBrief(script = makeScript(1)) {
  return mountStrategy(ScriptBrief, {
    script,
    scope: SCOPE,
    matrixTo: route('strategy.matrix'),
    creatorsTo: route('strategy.creators'),
    slotCode: 'SK-C1',
    angleMax: 300,
  })
}
const field = (w: Awaited<ReturnType<typeof mountBrief>>) =>
  w.get('[data-test="script-angle"] textarea').element as HTMLTextAreaElement
async function typeAngle(w: Awaited<ReturnType<typeof mountBrief>>, v: string) {
  const el = field(w)
  el.value = v
  el.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

describe('ScriptBrief (PL-3.2a)', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
  })

  it('the source line says where the angle comes from; the persona line carries the slot code', async () => {
    const w = await mountBrief()
    expect(w.get('[data-test="script-angle-source"]').text()).not.toBe('')
    expect(w.get('[data-test="script-brief-persona"]').text()).toContain('SK-C1')
    expect(w.find('[data-test="script-angle-live"]').exists()).toBe(false)
  })

  it('an own angle shows "Use the persona\'s angle"; clicking it saves null and takes the live line back', async () => {
    const s = makeScript(1)
    s.brief.angle = { value: 'my own angle', source: 'script', custom: true }
    const w = await mountBrief(s)
    const back = makeScript(1)
    back.revision = 2
    back.brief.revision = 2
    vi.mocked(strategyApi.saveBrief).mockResolvedValue({ data: back })
    await w.get('[data-test="script-angle-live"]').trigger('click')
    await flushPromises()
    expect(strategyApi.saveBrief).toHaveBeenCalledTimes(1)
    expect(vi.mocked(strategyApi.saveBrief).mock.calls[0]?.[2]).toEqual({
      angle: null,
      revision: 1,
    })
    await w.setProps({ script: back })
    expect(field(w).value).toBe('The persona angle')
  })

  it('typing an angle sends only the angle and the revision, after the debounce', async () => {
    const w = await mountBrief()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    const saved = makeScript(1)
    saved.brief = {
      ...saved.brief,
      revision: 2,
      angle: { value: 'new angle', source: 'script', custom: true },
    }
    vi.mocked(strategyApi.saveBrief).mockResolvedValue({ data: saved })
    await typeAngle(w, 'new angle')
    expect(strategyApi.saveBrief).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1600)
    expect(vi.mocked(strategyApi.saveBrief).mock.calls[0]?.[2]).toEqual({
      angle: 'new angle',
      revision: 1,
    })
  })

  it('the counter turns to the over state past 300; the text is kept (the server decides)', async () => {
    const w = await mountBrief()
    await typeAngle(w, 'x'.repeat(301))
    expect(w.get('[data-test="script-angle-count"]').text()).toContain('301 / 300')
    expect(w.get('[data-test="script-angle-count"]').classes()).toContain('sbrief__count--over')
    expect(field(w).value).toHaveLength(301)
  })

  it('duration menu: lists 15, 20, 30, 45, 60; opened, closed and quickly reopened; picking saves only the duration', async () => {
    const w = await mountBrief()
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    for (let i = 0; i < 2; i++) {
      await w.get('[data-test="script-duration"]').trigger('click')
      await flushPromises()
      expect(
        document.body.querySelectorAll(
          '[data-test^="script-duration-"]:not([data-test="script-duration-menu"])',
        ),
      ).toHaveLength(5)
      if (i === 0) {
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await flushPromises()
      }
    }
    const saved = makeScript(1)
    saved.brief = { ...saved.brief, revision: 2, duration_seconds: 45 }
    vi.mocked(strategyApi.saveBrief).mockResolvedValue({ data: saved })
    document.body.querySelector<HTMLElement>('[data-test="script-duration-45"]')?.click()
    await vi.advanceTimersByTimeAsync(1600)
    expect(vi.mocked(strategyApi.saveBrief).mock.calls[0]?.[2]).toEqual({
      duration_seconds: 45,
      revision: 1,
    })
  })

  it('the estimate shows seconds with text, and a quiet line without', async () => {
    const w = await mountBrief()
    expect(w.get('[data-test="script-estimate"]').text()).toContain('16')
    const empty = makeScript(1, { estimate: { words: 0, seconds: 0 } })
    await w.setProps({ script: empty })
    expect(w.get('[data-test="script-estimate"]').text()).not.toContain('16')
  })
})
