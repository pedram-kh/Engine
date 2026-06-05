/**
 * Typed wrapper for the Campaigns module API (Sprint 8 Chunk 1).
 *
 * Tenant-scoped to the current agency via the `agencyId` (ULID) path segment,
 * mirroring `brands.api.ts` / `roster.api.ts`. The HTTP client handles CSRF
 * preflight + Sanctum cookie auth transparently.
 *
 * Endpoint prefix: /api/v1/agencies/{agency}/campaigns
 */

import type {
  CampaignAssignmentListResponse,
  CampaignEnvelope,
  CampaignListParams,
  CampaignListResponse,
  CreateCampaignPayload,
  UpdateCampaignPayload,
} from '@catalyst/api-client'

import { http } from '@/core/api'

function campaignsBase(agencyId: string): string {
  return `/agencies/${agencyId}/campaigns`
}

export const campaignsApi = {
  /** List campaigns with the brand / status / date filters (agency-scoped). */
  list(agencyId: string, params: CampaignListParams = {}): Promise<CampaignListResponse> {
    const query = new URLSearchParams()
    if (params.brand !== undefined && params.brand !== '') query.set('brand', params.brand)
    // `'all'` is the UI's no-filter sentinel — never send it on the wire.
    if (params.status !== undefined && params.status !== 'all') query.set('status', params.status)
    if (params.starts_from !== undefined && params.starts_from !== '')
      query.set('starts_from', params.starts_from)
    if (params.starts_to !== undefined && params.starts_to !== '')
      query.set('starts_to', params.starts_to)
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<CampaignListResponse>(`${campaignsBase(agencyId)}${qs === '' ? '' : `?${qs}`}`)
  },

  show(agencyId: string, campaignId: string): Promise<CampaignEnvelope> {
    return http.get<CampaignEnvelope>(`${campaignsBase(agencyId)}/${campaignId}`)
  },

  create(agencyId: string, payload: CreateCampaignPayload): Promise<CampaignEnvelope> {
    return http.post<CampaignEnvelope>(campaignsBase(agencyId), payload)
  },

  /** Settings edit (admin/manager only — staff 403). */
  update(
    agencyId: string,
    campaignId: string,
    payload: UpdateCampaignPayload,
  ): Promise<CampaignEnvelope> {
    return http.patch<CampaignEnvelope>(`${campaignsBase(agencyId)}/${campaignId}`, payload)
  },

  /** Read-only assignment listing for the Creators tab (Chunk 1). */
  assignments(agencyId: string, campaignId: string): Promise<CampaignAssignmentListResponse> {
    return http.get<CampaignAssignmentListResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments`,
    )
  },
}
