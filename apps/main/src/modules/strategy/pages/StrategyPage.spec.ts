/**
 * PL-2.1 W1, W2, W3 — the workspace page: guarded by both flags before any request, header and subtitle, the
 * six-step stepper (two live, one not built, three locked), the step the route names.
 */
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/waves/api/waves.api', () => ({
  wavesApi: { show: vi.fn(), list: vi.fn(), create: vi.fn(), update: vi.fn() },
}))
vi.mock('../api/strategy.api', () => ({
  strategyApi: {
    summary: vi.fn(),
    listUsps: vi.fn(),
    listPersonas: vi.fn(),
  },
}))

import { wavesApi } from '@/modules/waves/api/waves.api'
import { strategyApi } from '../api/strategy.api'
import {
  BRAND_ULID,
  makeSummary,
  makeUsps,
  makeWave,
  mountStrategy,
  WAVE_ULID,
} from '../strategyTestKit'
import StrategyPage from './StrategyPage.vue'

function prime(): void {
  vi.mocked(wavesApi.show).mockResolvedValue({ data: makeWave() })
  vi.mocked(strategyApi.summary).mockResolvedValue({
    data: makeSummary({
      usp_count: 3,
      persona_count: 2,
      markets: [
        { market: 'SK', persona_count: 2 },
        { market: 'RO', persona_count: 0 },
      ],
    }),
  })
  vi.mocked(strategyApi.listUsps).mockResolvedValue({ data: makeUsps(3) })
  vi.mocked(strategyApi.listPersonas).mockResolvedValue({ data: [] })
}

describe('StrategyPage (PL-2.1 W1-W3)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('W1: waves off goes to the brand and requests nothing', async () => {
    for (const flag of [false, 'absent'] as const) {
      const w = await mountStrategy(StrategyPage, {}, { flag, strategy: true })
      await flushPromises()
      expect(wavesApi.show).not.toHaveBeenCalled()
      expect(strategyApi.summary).not.toHaveBeenCalled()
      expect(w.vm.$router.currentRoute.value.name).toBe('brands.detail')
      expect(w.find('[data-test="strategy-page"]').exists()).toBe(false)
      w.unmount()
    }
  })

  it('W1: waves on and strategy off goes to the wave page and requests nothing', async () => {
    for (const strategy of [false, undefined]) {
      const w = await mountStrategy(StrategyPage, {}, { flag: true, strategy })
      await flushPromises()
      expect(wavesApi.show).not.toHaveBeenCalled()
      expect(strategyApi.summary).not.toHaveBeenCalled()
      expect(w.vm.$router.currentRoute.value.name).toBe('waves.detail')
      expect(w.vm.$router.currentRoute.value.params.waveUlid).toBe(WAVE_ULID)
      w.unmount()
    }
  })

  it('W2: header, back button to the wave and the "Brand · Wave" subtitle', async () => {
    prime()
    const w = await mountStrategy(StrategyPage, {})
    await flushPromises()
    expect(w.get('[data-test="strategy-heading"]').text()).toBe('Strategy & Scripts')
    expect(w.get('[data-test="strategy-subtitle"]').text()).toBe('Acme Corp · Spring push')
    const back = w.get('[data-test="strategy-back"]')
    expect(back.attributes('href')).toBe(`/brands/${BRAND_ULID}/waves/${WAVE_ULID}`)
    expect(back.attributes('aria-label')).toBe('Back to wave')
    w.unmount()
  })

  it('W3: six steps; USPs and Personas live, Matrix not available, Creators and Scripts locked', async () => {
    prime()
    const w = await mountStrategy(StrategyPage, {})
    await flushPromises()
    const live = ['usps', 'personas']
    for (const key of live) {
      const step = w.get(`[data-test="strategy-step-${key}"]`)
      expect(step.element.tagName).toBe('A')
      expect(step.attributes('aria-disabled')).toBeUndefined()
    }
    for (const key of ['matrix', 'concepts', 'creators', 'scripts']) {
      const step = w.get(`[data-test="strategy-step-${key}"]`)
      expect(step.element.tagName).not.toBe('A')
      expect(step.attributes('aria-disabled')).toBe('true')
      expect(step.attributes('tabindex')).toBeUndefined()
    }
    expect(w.get('[data-test="strategy-step-matrix-status"]').text()).toBe('Not available yet')
    expect(w.get('[data-test="strategy-step-creators-status"]').text()).toBe(
      'Locked until all concepts are approved',
    )
    expect(w.get('[data-test="strategy-step-usps-status"]').text()).toBe('3 USPs')
    w.unmount()
  })

  it('W3: the route picks the step; a failed load shows the not-found state, not a half page', async () => {
    prime()
    const usps = await mountStrategy(StrategyPage, {})
    await flushPromises()
    expect(usps.find('[data-test="usps-step"]').exists()).toBe(true)
    expect(usps.find('[data-test="personas-step"]').exists()).toBe(false)
    usps.unmount()

    const personas = await mountStrategy(
      StrategyPage,
      {},
      {
        route: `/brands/${BRAND_ULID}/waves/${WAVE_ULID}/strategy/personas`,
      },
    )
    await flushPromises()
    expect(personas.find('[data-test="personas-step"]').exists()).toBe(true)
    personas.unmount()

    vi.mocked(strategyApi.summary).mockRejectedValue(new Error('404'))
    const gone = await mountStrategy(StrategyPage, {})
    await flushPromises()
    expect(gone.get('[data-test="strategy-error"]').text()).toBe(
      'This strategy workspace was not found.',
    )
    expect(gone.find('[data-test="strategy-stepper"]').exists()).toBe(false)
    gone.unmount()
  })
})
