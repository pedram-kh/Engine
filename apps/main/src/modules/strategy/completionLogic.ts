/**
 * PL-2.5 — the pure side of "Mark as complete": which state a step is in (for the whole wave or one market), what the
 * live warnings say, and where "Strategy & Scripts" opens. Everything reads the strategy summary the server sent; nothing
 * here is stored or decided on the client. The warning rules are the server's (`StrategyStepState::warnings`): they
 * never block, and an EMPTY step is not a warning but "nothing to mark" (the server refuses with 422).
 */
import type {
  StrategyLanding,
  StrategyMarketStep,
  StrategyStepState,
  StrategySummaryResource,
} from '@catalyst/api-client'

import type { MarkableStep } from './api/strategy.api'

export type StepName = 'usps' | 'personas' | 'matrix' | 'concepts'

export interface LiveWarning {
  code: 'usps_under_recommended' | 'personas_under_minimum' | 'cells_not_set'
  /** Named values for the message. */
  params: Record<string, number>
}

type Attrs = StrategySummaryResource['attributes']

/** The state of a step for the whole wave. */
export function waveState(a: Attrs, step: StepName): StrategyStepState {
  return a.steps[step].state
}

/** The per-market row of a step, or null for USPs and for a market the summary does not list. */
export function marketRow(a: Attrs, step: StepName, marketId: string): StrategyMarketStep | null {
  if (step === 'usps') return null
  return a.steps[step].markets.find((m) => m.market === marketId) ?? null
}

/** "{done} of {total} markets" figures of a per-market step; null for USPs. */
export function marketsFigures(a: Attrs, step: StepName): { done: number; total: number } | null {
  if (step === 'usps') return null
  const s = a.steps[step]
  return { done: s.markets_done, total: s.markets_total }
}

/** The state shown for the step the person is on: the market's own for a per-market step, the wave's for USPs. */
export function stateFor(a: Attrs, step: StepName, marketId: string | null): StrategyStepState {
  if (step === 'usps' || marketId === null) return waveState(a, step)
  return marketRow(a, step, marketId)?.state ?? 'not_started'
}

/** Records the step has in the scope (USPs of the wave; personas, cells, concepts of the market). */
export function dataCount(a: Attrs, step: StepName, marketId: string | null): number {
  if (step === 'usps') return a.steps.usps.count
  if (marketId === null) return 0
  return marketRow(a, step, marketId)?.count ?? 0
}

/** The live warnings for a step in its scope; `[]` when the step has no data (that is "nothing to mark"). */
export function liveWarnings(a: Attrs, step: MarkableStep, marketId: string | null): LiveWarning[] {
  if (step === 'usps') {
    const n = a.steps.usps.count
    return n > 0 && n < a.limits.usp_warn_below
      ? [
          {
            code: 'usps_under_recommended',
            params: { count: n, recommended: a.limits.usp_warn_below },
          },
        ]
      : []
  }
  if (marketId === null) return []
  if (step === 'personas') {
    const n = marketRow(a, 'personas', marketId)?.count ?? 0
    return n > 0 && n < a.limits.persona_min
      ? [
          {
            code: 'personas_under_minimum',
            params: { count: n, recommended: a.limits.persona_min },
          },
        ]
      : []
  }
  const progress = a.matrix.by_market.find((m) => m.market === marketId)
  const set = progress?.set ?? 0
  const total = progress?.total ?? 0
  return set > 0 && set < total
    ? [{ code: 'cells_not_set', params: { set, total, missing: total - set } }]
    : []
}

/** True when the step is marked complete in the scope (a per-market step needs the market). */
export function isMarked(a: Attrs, step: MarkableStep, marketId: string | null): boolean {
  if (step === 'usps') return a.steps.usps.marked
  return marketId === null ? false : (marketRow(a, step, marketId)?.marked ?? false)
}

/** Who marked it and when, for the line next to the button. */
export function markInfo(
  a: Attrs,
  step: MarkableStep,
  marketId: string | null,
): { name: string | null; former: boolean; at: string } | null {
  const src =
    step === 'usps' ? a.steps.usps : marketId === null ? null : marketRow(a, step, marketId)
  if (src === null || src.completed_by === null || src.completed_at === null) return null
  return { name: src.completed_by.name, former: src.completed_by.former, at: src.completed_at }
}

/** The landing the server computed; the market is its ulid (the web's `?market=`), null for USPs. */
export function landingRoute(landing: StrategyLanding): {
  name: string
  query: Record<string, string>
} {
  const name = `strategy.${landing.step}`
  return {
    name,
    query:
      landing.step !== 'usps' && landing.market_id !== null ? { market: landing.market_id } : {},
  }
}
