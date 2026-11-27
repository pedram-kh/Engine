/**
 * PL-3.1 — the creators step of a wave: the slot list per market, the picker, the client's decisions.
 * Endpoint prefix: /api/v1/agencies/{agency}/brands/{brand}/waves/{wave}/strategy/creators (404 `not_found` unless
 * `features.creators_scripts`).
 */

export type WaveCreatorStatus = 'shortlisted' | 'contacted' | 'confirmed' | 'declined'
export type CreatorStepState = 'not_started' | 'in_progress' | 'done'
export type CreatorDecisionStatus = 'approved' | 'suggest_another'

/** The creator's card data. An erased creator has `is_erased: true` and no personal field. */
export interface WaveCreatorCardData {
  id: string
  is_erased: boolean
  display_name: string | null
  avatar_url: string | null
  country_code: string | null
  region: string | null
  age: number | null
  primary_language: string | null
  secondary_languages: string[]
  categories: string[]
  verified: boolean
  example_content_url: string | null
}

export interface WaveCreatorDecisionData {
  id: string
  status: CreatorDecisionStatus
  client_name: string
  note: string | null
  comment: string | null
  recorded_by: string | null
  recorded_at: string
}

export interface WaveCreatorSlot {
  id: string
  slot: number
  slot_code: string
  persona: { id: string; code: string; title: string } | null
  creator: WaveCreatorCardData | null
  status: WaveCreatorStatus
  detail: string
  decision: WaveCreatorDecisionData | null
  decision_count: number
  board: { column: string | null; stage: string | null } | null
}

export interface WaveCreatorMarketSummary {
  total: number
  by_status: Partial<Record<WaveCreatorStatus, number>>
  personas: { id: string; code: string; title: string; count: number }[]
  warnings: { personas_without_creator: string[] }
}

export interface WaveCreatorMarket {
  market: string
  code: string
  name: string
  state: CreatorStepState
  count: number
  notes_count: number
  summary: WaveCreatorMarketSummary
  slots: WaveCreatorSlot[]
}

export interface WaveCreatorsData {
  campaign: { linked: boolean; id: string | null; name: string | null }
  limits: { per_market: number; per_wave: number }
  state: { state: CreatorStepState; markets_done: number; markets_total: number; count: number }
  markets: WaveCreatorMarket[]
}

export interface WaveCreatorsResponse {
  data: WaveCreatorsData
}

export interface WaveCreatorCandidate extends WaveCreatorCardData {
  status: WaveCreatorStatus
  detail: string
  applied: boolean
  invited: boolean
  already_in_wave: boolean
  /** The market ulid the creator is already in. */
  in_market: string | null
  /** Archived scripts this creator has in the market the list was asked for; they come back on Add or Replace, up to three live. */
  archived_scripts: number
}

export interface WaveCreatorCandidatesResponse {
  data: WaveCreatorCandidate[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
    market: string | null
    market_countries: string[]
  }
}

export interface WaveCreatorAddPayload {
  market: string
  creators: string[]
  persona?: string | null
}

export interface WaveCreatorUpdatePayload {
  slot_code?: string
  persona?: string | null
}

export interface WaveCreatorDecisionPayload {
  status: CreatorDecisionStatus
  client_name: string
  note?: string | null
  comment?: string | null
}

export interface WaveCreatorDecideResponse {
  data: WaveCreatorsData
  meta: { card_moved: boolean; card_not_moved: string | null }
}

export interface WaveCreatorDecisionHistoryResponse {
  data: WaveCreatorDecisionData[]
}
