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
  CreateInvitationPayload,
  InvitationPreviewEnvelope,
} from '@catalyst/api-client'
import { http } from '@/core/api'

export interface SingleInvitationEnvelope {
  data: AgencyInvitationResource
}

export interface AcceptInvitationPayload {
  token: string
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
}
