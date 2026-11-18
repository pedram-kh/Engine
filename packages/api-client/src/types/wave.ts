/**
 * PL-1.1 — waves of a brand. Mirrors `WaveResource` / `WaveController` in `apps/api`
 * (`MOD/Waves`). Ulids only: no internal id and no `agency_id` is ever sent.
 */

import type { CampaignStatus, CampaignType } from './campaign'

/** One case in PL-1.1; later chunks add cases. */
export type WaveStatus = 'draft'

export interface WaveBrandRelationship {
  data: { id: string; type: 'brands'; attributes: { name: string } }
}

export interface WaveCampaignRelationship {
  data: {
    id: string
    type: 'campaigns'
    attributes: { name: string; status: CampaignStatus; campaign_type?: CampaignType }
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
    /** PL-1.2 (P5): true while a card of the linked campaign sits in a working column. */
    link_locked?: boolean
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
  /** Absent or null = no link. Never together with `new_campaign`. */
  campaign_id?: string | null
  /** PL-1.2 (P3): create a for-a-wave campaign with the wave, in one step. Create only. */
  new_campaign?: { name: string }
}

/** A partial body. `campaign_id` sets, changes or (null) removes the link while it is not locked. */
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

/** One row of `GET campaigns/{campaign}/wave-options` (PL-1.2): this brand's waves with no campaign. */
export interface CampaignWaveOption {
  id: string
  type: 'wave_options'
  attributes: { name: string; go_live_on: string }
}

export interface CampaignWaveOptionsResponse {
  data: CampaignWaveOption[]
}
