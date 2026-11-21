/**
 * PL-2.4 — the markets of the wave the strategy screens are on: ULID (the identity), name, short code and countries, read
 * from the strategy summary. A step calls {@link provideMarkets} with its summary; the dialogs under it call
 * {@link useMarkets}, so a market is printed by NAME everywhere and no component spells a code where a name belongs.
 */
import { computed, inject, provide, type ComputedRef, type InjectionKey } from 'vue'

import type { StrategySummaryResource } from '@catalyst/api-client'

export interface StrategyMarket {
  id: string
  name: string
  code: string
  countries: string[]
  personaCount: number
}

export interface MarketDirectory {
  list: ComputedRef<StrategyMarket[]>
  ids: ComputedRef<string[]>
  find: (id: string | null | undefined) => StrategyMarket | undefined
  /** The market's name, or an empty string for an id that is not one of the wave's markets. */
  name: (id: string | null | undefined) => string
}

const KEY: InjectionKey<MarketDirectory> = Symbol('strategy-markets')

export function marketsOf(summary: StrategySummaryResource): StrategyMarket[] {
  return summary.attributes.markets.map((m) => ({
    id: m.market,
    name: m.name,
    code: m.code,
    countries: m.countries,
    personaCount: m.persona_count,
  }))
}

function directory(list: ComputedRef<StrategyMarket[]>): MarketDirectory {
  const find = (id: string | null | undefined): StrategyMarket | undefined =>
    id == null ? undefined : list.value.find((m) => m.id === id)
  return {
    list,
    ids: computed(() => list.value.map((m) => m.id)),
    find,
    name: (id) => find(id)?.name ?? '',
  }
}

/** Build the directory from a summary and make it available to every component below. */
export function provideMarkets(summary: () => StrategySummaryResource): MarketDirectory {
  const dir = directory(computed(() => marketsOf(summary())))
  provide(KEY, dir)
  return dir
}

/** The directory a step provided; an empty one (every name blank) when none was. */
export function useMarkets(): MarketDirectory {
  return inject(KEY, null) ?? directory(computed(() => []))
}
