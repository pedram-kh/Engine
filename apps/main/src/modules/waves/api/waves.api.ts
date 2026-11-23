/**
 * Typed wrapper for the wave endpoints (PL-1.1).
 *
 * Endpoint prefix: /api/v1/agencies/{agency}/brands/{brand}/waves. Behind the `waves_enabled` flag (404 while
 * off).
 */

import type {
  CreateWavePayload,
  UpdateWavePayload,
  WaveCampaignOptionsResponse,
  WaveEnvelope,
  WaveListParams,
  WaveListResponse,
  WaveStepCreatePayload,
  WaveStepEnvelope,
  WaveStepUpdatePayload,
  WaveStream,
  WaveTrackerEnvelope,
} from '@catalyst/api-client'
import { http } from '@/core/api'

function base(agencyId: string, brandId: string): string {
  return `/agencies/${agencyId}/brands/${brandId}/waves`
}

export const wavesApi = {
  list(agencyId: string, brandId: string, params: WaveListParams = {}): Promise<WaveListResponse> {
    const query = new URLSearchParams()
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<WaveListResponse>(`${base(agencyId, brandId)}${qs ? `?${qs}` : ''}`)
  },

  create(agencyId: string, brandId: string, payload: CreateWavePayload): Promise<WaveEnvelope> {
    return http.post<WaveEnvelope>(base(agencyId, brandId), payload)
  },

  /** The New wave dialog's campaign list: this brand's, no wave yet, never Cancelled. */
  campaignOptions(agencyId: string, brandId: string): Promise<WaveCampaignOptionsResponse> {
    return http.get<WaveCampaignOptionsResponse>(`${base(agencyId, brandId)}/campaign-options`)
  },

  /** PL-3.1 (A1): create a for-a-wave campaign for a wave that has none, from the Creators step. */
  createCampaign(
    agencyId: string,
    brandId: string,
    waveId: string,
    name: string,
  ): Promise<WaveEnvelope> {
    return http.post<WaveEnvelope>(`${base(agencyId, brandId)}/${waveId}/campaign`, { name })
  },

  show(agencyId: string, brandId: string, waveId: string): Promise<WaveEnvelope> {
    return http.get<WaveEnvelope>(`${base(agencyId, brandId)}/${waveId}`)
  },

  update(
    agencyId: string,
    brandId: string,
    waveId: string,
    payload: UpdateWavePayload,
  ): Promise<WaveEnvelope> {
    return http.patch<WaveEnvelope>(`${base(agencyId, brandId)}/${waveId}`, payload)
  },

  /** PL-1.3 — the tracker. The first call on a wave provisions its default steps. */
  tracker(agencyId: string, brandId: string, waveId: string): Promise<WaveTrackerEnvelope> {
    return http.get<WaveTrackerEnvelope>(`${base(agencyId, brandId)}/${waveId}/tracker`)
  },

  addStep(
    agencyId: string,
    brandId: string,
    waveId: string,
    payload: WaveStepCreatePayload,
  ): Promise<WaveStepEnvelope> {
    return http.post<WaveStepEnvelope>(`${base(agencyId, brandId)}/${waveId}/steps`, payload)
  },

  updateStep(
    agencyId: string,
    brandId: string,
    waveId: string,
    stepId: string,
    payload: WaveStepUpdatePayload,
  ): Promise<WaveStepEnvelope> {
    return http.patch<WaveStepEnvelope>(
      `${base(agencyId, brandId)}/${waveId}/steps/${stepId}`,
      payload,
    )
  },

  removeStep(agencyId: string, brandId: string, waveId: string, stepId: string): Promise<void> {
    return http.delete<void>(`${base(agencyId, brandId)}/${waveId}/steps/${stepId}`)
  },

  /** Returns the whole tracker. */
  removeStream(
    agencyId: string,
    brandId: string,
    waveId: string,
    stream: WaveStream,
  ): Promise<WaveTrackerEnvelope> {
    return http.delete<WaveTrackerEnvelope>(
      `${base(agencyId, brandId)}/${waveId}/streams/${stream}`,
    )
  },

  /** Adds a removed stream back with its default steps. Returns the whole tracker. */
  addStream(
    agencyId: string,
    brandId: string,
    waveId: string,
    stream: WaveStream,
  ): Promise<WaveTrackerEnvelope> {
    return http.post<WaveTrackerEnvelope>(`${base(agencyId, brandId)}/${waveId}/streams/${stream}`)
  },
}
