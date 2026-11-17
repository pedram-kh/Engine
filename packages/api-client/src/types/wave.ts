/**
 * PL-1.1 — waves of a brand. Mirrors `WaveResource` / `WaveController` in `apps/api`
 * (`MOD/Waves`). Ulids only: no internal id and no `agency_id` is ever sent.
 */

import type { CampaignStatus } from './campaign'

/** One case in PL-1.1; later chunks add cases. */
export type WaveStatus = 'draft'

export interface WaveBrandRelationship {
  data: { id: string; type: 'brands'; attributes: { name: string } }
}

export interface WaveCampaignRelationship {
  data: {
    id: string
    type: 'campaigns'
    attributes: { name: string; status: CampaignStatus }
  }
}

export interface WaveCreatedByRelationship {
  data: { id: string; type: 'users'; attributes: { name: string } }
}

export interface WaveResource {
  id: string
  type: 'waves'
  attributes: {
    name: string
    /** A plain `Y-m-d` date, no time zone. */
    go_live_on: string
    /** Uppercase ISO 3166-1 alpha-2 codes, in the order the user picked them. */
    markets: string[]
    status: WaveStatus
    created_at: string
    updated_at: string
  }
  relationships: {
    brand: WaveBrandRelationship
    /** Null when the wave has no campaign link. */
    campaign: WaveCampaignRelationship | null
    created_by: WaveCreatedByRelationship
  }
}

export interface WaveListResponse {
  data: WaveResource[]
  meta: { total: number; page: number; per_page: number; last_page: number }
}

export interface WaveEnvelope {
  data: WaveResource
}

export interface WaveListParams {
  page?: number
  per_page?: number
}

export interface CreateWavePayload {
  name: string
  go_live_on: string
  markets: string[]
  /** Absent or null = no link. */
  campaign_id?: string | null
}

/** A partial body. `campaign_id` may only SET a link while the wave has none. */
export type UpdateWavePayload = Partial<CreateWavePayload>

/** One row of `GET brands/{brand}/waves/campaign-options`. Cancelled campaigns are never offered. */
export interface WaveCampaignOption {
  id: string
  type: 'campaign_options'
  attributes: { name: string; status: CampaignStatus }
}

export interface WaveCampaignOptionsResponse {
  data: WaveCampaignOption[]
}
