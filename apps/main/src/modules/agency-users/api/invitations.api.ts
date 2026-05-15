/**
 * Typed wrapper for the Invitations API endpoints.
 *
 * Two distinct surface areas:
 *   - Invitation creation (tenant-scoped, agency_admin only)
 *   - Invitation accept (auth:web only, pre-tenancy)
 *   - Invitation preview (unauthenticated, for the accept page UI)
 */

import type {
  AgencyInvitationResource,
  AgencyInvitationStatus,
  CreateInvitationPayload,
  InvitationPreviewEnvelope,
  PaginatedCollection,
} from '@catalyst/api-client'
import { http } from '@/core/api'

export interface SingleInvitationEnvelope {
  data: AgencyInvitationResource
}

export interface AcceptInvitationPayload {
  token: string
}

/**
 * Sprint 3 Chunk 4 sub-step 7 — invitation history listing params.
 */
export interface InvitationHistoryParams {
  page?: number
  per_page?: number
  /** Filter by status. Omit for all statuses. */
  status?: AgencyInvitationStatus
  /** Sort key — backend supports `created_at` only at present. */
  sort?: 'created_at'
  /** Sort direction; defaults to `desc` on the backend. */
  order?: 'asc' | 'desc'
}

export const invitationsApi = {
  /** POST /api/v1/agencies/{agency}/invitations — agency_admin only. */
  create(agencyId: string, payload: CreateInvitationPayload): Promise<SingleInvitationEnvelope> {
    return http.post<SingleInvitationEnvelope>(`/agencies/${agencyId}/invitations`, payload)
  },

  /**
   * POST /api/v1/agencies/{agency}/invitations/accept
   * User must be authenticated; email must match invitation.
   */
  accept(agencyId: string, payload: AcceptInvitationPayload): Promise<SingleInvitationEnvelope> {
    return http.post<SingleInvitationEnvelope>(`/agencies/${agencyId}/invitations/accept`, payload)
  },

  /**
   * GET /api/v1/agencies/{agency}/invitations/preview?token=<unhashed>
   * No authentication required.
   */
  preview(agencyId: string, token: string): Promise<InvitationPreviewEnvelope> {
    return http.get<InvitationPreviewEnvelope>(
      `/agencies/${agencyId}/invitations/preview?token=${encodeURIComponent(token)}`,
    )
  },

  /**
   * GET /api/v1/agencies/{agency}/invitations
   *
   * Paginated invitation history for the agency (pending + accepted +
   * expired). Agency-admin only — the backend's `authorizeAdmin()` is
   * the SOT; the UI's admin-only gate is a UX nicety.
   *
   * Sprint 3 Chunk 4 sub-step 3 (backend) + 7 (frontend).
   */
  list(
    agencyId: string,
    params: InvitationHistoryParams = {},
  ): Promise<PaginatedCollection<AgencyInvitationResource>> {
    const query = new URLSearchParams()
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    if (params.status !== undefined) query.set('status', params.status)
    if (params.sort !== undefined) query.set('sort', params.sort)
    if (params.order !== undefined) query.set('order', params.order)
    const qs = query.toString()
    return http.get<PaginatedCollection<AgencyInvitationResource>>(
      `/agencies/${agencyId}/invitations${qs ? `?${qs}` : ''}`,
    )
  },
}
