/**
 * PL-3.1 — the pure side of the creators step: the status order and tone, the summary the row draws, what the picker may
 * select, the first-letter fallbacks. Nothing here is decided on the client: the status is derived on the server from the
 * campaign, the eligible set is the server's, and the caps are the server's (the page only shows them before the request).
 */
import type {
  CreatorStepState,
  WaveCreatorCandidate,
  WaveCreatorMarket,
  WaveCreatorStatus,
} from '@catalyst/api-client'

export const STATUS_ORDER: WaveCreatorStatus[] = [
  'shortlisted',
  'contacted',
  'confirmed',
  'declined',
]

export type StatusTone = 'neutral' | 'info' | 'success' | 'danger'

/** Shortlisted is neutral, Contacted blue, Confirmed green, Declined red (frame 13:1953's pills). */
export function statusTone(status: WaveCreatorStatus): StatusTone {
  switch (status) {
    case 'contacted':
      return 'info'
    case 'confirmed':
      return 'success'
    case 'declined':
      return 'danger'
    default:
      return 'neutral'
  }
}

/** The status pills of the summary row, in order, only the statuses that have a creator (the frame draws no empty pill). */
export function statusPills(
  market: WaveCreatorMarket,
): { status: WaveCreatorStatus; count: number }[] {
  return STATUS_ORDER.flatMap((status) => {
    const count = market.summary.by_status[status] ?? 0
    return count > 0 ? [{ status, count }] : []
  })
}

/** Persona codes with no creator who is still in play (a declined creator does not cover a persona). */
export function personasWithoutCreator(market: WaveCreatorMarket): string[] {
  return market.summary.warnings.personas_without_creator
}

/** A candidate can be ticked unless the server already placed them in the wave or the account is erased. */
export function selectable(c: WaveCreatorCandidate): boolean {
  return !c.already_in_wave && !c.is_erased
}

/** How many more creators a market takes before the per-market cap, never below zero. */
export function roomInMarket(market: WaveCreatorMarket | undefined, perMarket: number): number {
  return Math.max(0, perMarket - (market?.count ?? 0))
}

/** How many more creators the wave takes before the per-wave cap. */
export function roomInWave(total: number, perWave: number): number {
  return Math.max(0, perWave - total)
}

/** The whole wave's creator count across markets. */
export function waveCount(markets: WaveCreatorMarket[]): number {
  return markets.reduce((n, m) => n + m.count, 0)
}

/** The 0 / 1 list the template loops over where a `v-if` would leave a comment node in a flag-off DOM. */
export function zeroOrOne(on: boolean): number[] {
  return on ? [1] : []
}

export function isDone(state: CreatorStepState): boolean {
  return state === 'done'
}
