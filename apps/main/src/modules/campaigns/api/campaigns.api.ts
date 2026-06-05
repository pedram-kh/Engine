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
  AgencyAssignmentDetailResponse,
  AttachContractPayload,
  AttachContractResponse,
  CampaignAssignmentListResponse,
  CampaignAssignmentResource,
  CampaignEnvelope,
  CampaignListParams,
  CampaignListResponse,
  ContractMediaCompletePayload,
  ContractMediaCompleteResponse,
  ContractMediaInitPayload,
  ContractMediaInitResponse,
  CreateCampaignPayload,
  InviteAssignmentPayload,
  ProceedWithoutContractResponse,
  RejectDraftPayload,
  ReinviteAssignmentPayload,
  RequestRevisionPayload,
  ReviewActionResponse,
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

  /**
   * The agency-side review detail (Sprint 9 Chunk 2, D-7) — the assignment +
   * its draft version history + posted content (with signed media URLs). Feeds
   * the review drawer.
   */
  showAssignment(
    agencyId: string,
    campaignId: string,
    assignmentId: string,
  ): Promise<AgencyAssignmentDetailResponse> {
    return http.get<AgencyAssignmentDetailResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments/${assignmentId}`,
    )
  },

  /** Approve a submitted draft (D-4/D-5) — draft_submitted → approved. */
  approveDraft(
    agencyId: string,
    campaignId: string,
    assignmentId: string,
  ): Promise<ReviewActionResponse> {
    return http.post<ReviewActionResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments/${assignmentId}/approve`,
      {},
    )
  },

  /** Request changes (D-4/D-5) — draft_submitted → revision_requested. Feedback required. */
  requestRevision(
    agencyId: string,
    campaignId: string,
    assignmentId: string,
    payload: RequestRevisionPayload,
  ): Promise<ReviewActionResponse> {
    return http.post<ReviewActionResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments/${assignmentId}/request-revision`,
      payload,
    )
  },

  /** Reject a submitted draft (D-1/D-4/D-5) — draft_submitted → rejected (terminal). Reason required. */
  rejectDraft(
    agencyId: string,
    campaignId: string,
    assignmentId: string,
    payload: RejectDraftPayload,
  ): Promise<ReviewActionResponse> {
    return http.post<ReviewActionResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments/${assignmentId}/reject`,
      payload,
    )
  },

  /** Initiate a presigned contract PDF upload for an accepted assignment. */
  initContractMedia(
    agencyId: string,
    campaignId: string,
    assignmentId: string,
    payload: ContractMediaInitPayload,
  ): Promise<ContractMediaInitResponse> {
    return http.post<ContractMediaInitResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments/${assignmentId}/contract/media/init`,
      payload,
    )
  },

  completeContractMedia(
    agencyId: string,
    campaignId: string,
    assignmentId: string,
    payload: ContractMediaCompletePayload,
  ): Promise<ContractMediaCompleteResponse> {
    return http.post<ContractMediaCompleteResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments/${assignmentId}/contract/media/complete`,
      payload,
    )
  },

  /** Issue a per-campaign contract to an accepted assignment. */
  attachContract(
    agencyId: string,
    campaignId: string,
    assignmentId: string,
    payload: AttachContractPayload,
  ): Promise<AttachContractResponse> {
    return http.post<AttachContractResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments/${assignmentId}/contract/attach`,
      payload,
    )
  },

  /**
   * Advance an accepted assignment to `contracted` WITHOUT a per-campaign
   * contract (D-7) — agency discretion, available only when the campaign does
   * not require a contract. Throws an `ApiError` with code
   * `assignment.per_campaign_contract_required` when the campaign requires one.
   */
  proceedWithoutContract(
    agencyId: string,
    campaignId: string,
    assignmentId: string,
  ): Promise<ProceedWithoutContractResponse> {
    return http.post<ProceedWithoutContractResponse>(
      `${campaignsBase(agencyId)}/${campaignId}/assignments/${assignmentId}/contract/proceed-without-contract`,
      {},
    )
  },
}
