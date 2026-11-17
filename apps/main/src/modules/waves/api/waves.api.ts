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
}
