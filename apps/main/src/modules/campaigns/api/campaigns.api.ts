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
  CampaignAssignmentResource,
  CampaignEnvelope,
  CampaignListParams,
  CampaignListResponse,
  CreateCampaignPayload,
  InviteAssignmentPayload,
  ReinviteAssignmentPayload,
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

  /** Assignment listing for the Creators tab. */
  assignments(agencyId: string, campaignId: string): Promise<CampaignAssignmentListResponse> {
    return http.get<CampaignAssignmentListResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments`,
    )
  },

  /**
   * Invite a single creator (Chunk 2, D-3). The execute ability (admin +
   * manager + staff). Resolves to the created assignment (201) or the existing
   * one (200, idempotent). Throws an `ApiError` for the two gate tiers: a 422
   * `assignment.blacklisted` (hard block) or a 409 `assignment.availability_conflict`
   * (soft warn — re-call with `acknowledged: true` to proceed).
   */
  invite(
    agencyId: string,
    campaignId: string,
    payload: InviteAssignmentPayload,
  ): Promise<{ data: CampaignAssignmentResource }> {
    return http.post<{ data: CampaignAssignmentResource }>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments`,
      payload,
    )
  },

  /** The agency re-offer after a counter (Chunk 2, D-7) — the guarded edge. */
  reinvite(
    agencyId: string,
    campaignId: string,
    assignmentId: string,
    payload: ReinviteAssignmentPayload,
  ): Promise<{ data: CampaignAssignmentResource }> {
    return http.post<{ data: CampaignAssignmentResource }>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments/${assignmentId}/reinvite`,
      payload,
    )
  },
}
