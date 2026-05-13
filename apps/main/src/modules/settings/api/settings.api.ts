/**
 * Typed wrapper for the Agency Settings API endpoints.
 *
 * GET is open to all members; PATCH is restricted to agency_admin
 * (enforced on the backend; the frontend disables the form for non-admins).
 */

import type { AgencySettingsEnvelope, UpdateAgencySettingsPayload } from '@catalyst/api-client'
import { http } from '@/core/api'

export const settingsApi = {
  /** GET /api/v1/agencies/{agency}/settings */
  show(agencyId: string): Promise<AgencySettingsEnvelope> {
    return http.get<AgencySettingsEnvelope>(`/agencies/${agencyId}/settings`)
  },

  /** PATCH /api/v1/agencies/{agency}/settings — agency_admin only. */
  update(agencyId: string, payload: UpdateAgencySettingsPayload): Promise<AgencySettingsEnvelope> {
    return http.patch<AgencySettingsEnvelope>(`/agencies/${agencyId}/settings`, payload)
  },
}
