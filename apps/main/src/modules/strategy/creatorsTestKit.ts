/**
 * PL-3.1 — fixtures for the creators web specs: a slot, a market of the creators payload, the payload, a picker candidate.
 */
import type {
  WaveCreatorCandidate,
  WaveCreatorMarket,
  WaveCreatorSlot,
  WaveCreatorsData,
  WaveCreatorStatus,
} from '@catalyst/api-client'

export const SLOT_ID = (n: number): string => `01SLOT${String(n).padStart(20, '0')}`
export const CREATOR_ID = (n: number): string => `01CREA${String(n).padStart(20, '0')}`

export function makeSlot(
  n: number,
  over: Partial<WaveCreatorSlot> = {},
  creator: Partial<NonNullable<WaveCreatorSlot['creator']>> = {},
): WaveCreatorSlot {
  return {
    id: SLOT_ID(n),
    slot: n,
    slot_code: `SK-C${n}`,
    persona: null,
    creator: {
      id: CREATOR_ID(n),
      is_erased: false,
      display_name: `Creator ${n}`,
      avatar_url: null,
      country_code: 'SK',
      region: 'Bratislava',
      age: 28,
      primary_language: 'sk',
      secondary_languages: ['en'],
      categories: ['food', 'tech', 'travel'],
      verified: false,
      example_content_url: null,
      ...creator,
    },
    status: 'shortlisted',
    detail: 'applied',
    decision: null,
    decision_count: 0,
    board: null,
    ...over,
  }
}

export function makeCreatorMarket(
  code: string,
  slots: WaveCreatorSlot[],
  over: Partial<WaveCreatorMarket> = {},
): WaveCreatorMarket {
  const by: Partial<Record<WaveCreatorStatus, number>> = {}
  for (const s of slots) by[s.status] = (by[s.status] ?? 0) + 1
  return {
    market: code,
    code,
    name: code,
    state: slots.length > 0 ? 'in_progress' : 'not_started',
    count: slots.length,
    notes_count: 0,
    summary: {
      total: slots.length,
      by_status: by,
      personas: [],
      warnings: { personas_without_creator: [] },
    },
    slots,
    ...over,
  }
}

export function makeCreatorsData(
  markets: WaveCreatorMarket[],
  over: Partial<WaveCreatorsData> = {},
): WaveCreatorsData {
  return {
    campaign: { linked: true, id: '01CAMPAIGNXXXXXXXXXXXXXXXX', name: 'Bolt Summer Wave Campaign' },
    limits: { per_market: 30, per_wave: 100 },
    state: {
      state: 'in_progress',
      markets_done: 0,
      markets_total: markets.length,
      count: markets.reduce((n, m) => n + m.count, 0),
    },
    markets,
    ...over,
  }
}

export function makeCandidate(
  n: number,
  over: Partial<WaveCreatorCandidate> = {},
): WaveCreatorCandidate {
  return {
    id: CREATOR_ID(100 + n),
    is_erased: false,
    display_name: `Candidate ${n}`,
    avatar_url: null,
    country_code: 'SK',
    region: 'Košice',
    age: 30,
    primary_language: 'sk',
    secondary_languages: [],
    categories: ['food'],
    verified: false,
    example_content_url: null,
    status: 'shortlisted',
    detail: 'applied',
    applied: true,
    invited: false,
    already_in_wave: false,
    in_market: null,
    ...over,
  }
}
