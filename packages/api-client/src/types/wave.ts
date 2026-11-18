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
    /** PL-1.3: the six strategy steps as a bar. Optional so older fixtures still type. */
    progress?: WaveProgress
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

/** PL-1.3 — one of the six segments of the Brand page and wave page bar. */
export type WaveProgressSegment = 'done' | 'in_progress' | 'empty'

export interface WaveProgress {
  /** Always six entries, in strategy order. */
  segments: WaveProgressSegment[]
  /** `step`: `step_name` is the first strategy step that is not done. */
  label_kind: 'step' | 'complete' | 'not_started'
  step_name: string | null
}

/** PL-1.3 — the three streams of a wave's tracker. */
export type WaveStream = 'ugc_video' | 'statics' | 'stills'

export type WaveStepStatus = 'not_started' | 'in_progress' | 'done'

/** The word a step shows, derived on the server from the due date and "today" (the requester's day). */
export type WaveStepDisplayStatus = 'up_next' | 'in_progress' | 'due_today' | 'delayed' | 'done'

export type WaveStreamStatus = 'on_track' | 'delayed' | 'complete'

export interface WaveStepOwner {
  id: string
  name: string
}

export interface WaveStepResource {
  id: string
  type: 'wave_steps'
  attributes: {
    stream: WaveStream
    /** Null for a custom step; set for the 24 defaults. */
    key: string | null
    is_strategy: boolean
    name: string
    position: number
    status: WaveStepStatus
    status_source: 'system' | 'manual'
    display_status: WaveStepDisplayStatus
    days_overdue: number
    /** Plain `Y-m-d` dates. */
    starts_on: string | null
    due_on: string | null
    completed_on: string | null
    owner: WaveStepOwner | null
    owner_client: boolean
    /** True when the owner is no longer an active member. */
    owner_is_former: boolean
  }
}

export interface WaveStreamSummary {
  stream: WaveStream
  colour: string
  status: WaveStreamStatus
  days_delayed: number
  done: number
  total: number
  next_step: { id: string; name: string; due_on: string | null } | null
  final_delivery: string | null
  owners: WaveStepOwner[]
  has_client: boolean
}

export interface WaveTrackerResource {
  id: string
  type: 'wave_trackers'
  attributes: {
    /** The requester's date, `Y-m-d`. */
    today: string
    streams: WaveStreamSummary[]
    /** Streams with no steps (removed): the Add stream menu lists these. */
    absent_streams: WaveStream[]
    steps: WaveStepResource[]
    health: { status: 'on_track' | 'at_risk'; delayed_streams: number }
    progress: WaveProgress
  }
}

export interface WaveTrackerEnvelope {
  data: WaveTrackerResource
}

export interface WaveStepEnvelope {
  data: WaveStepResource
}

export interface WaveStepCreatePayload {
  stream: WaveStream
  name: string
  owner?: string | null
  owner_client?: boolean
  starts_on?: string | null
  due_on?: string | null
}

/** A partial body. `name` and `stream` are admin and manager only (a staff request that includes one is 403). */
export type WaveStepUpdatePayload = Partial<WaveStepCreatePayload> & { status?: WaveStepStatus }
