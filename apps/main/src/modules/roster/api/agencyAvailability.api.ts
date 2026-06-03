/**
 * Typed wrapper for the AGENCY-side creator availability read endpoint
 * (Sprint 5 Chunk A backend; Sprint 6 Chunk 2a consumer — closes the
 * Sprint-5 deferral loop, the endpoint built standalone now has its reader).
 *
 * Distinct from the creator-self `availability.api.ts`:
 *   - it is agency/path-scoped (`/agencies/{agency}/creators/{creator}`),
 *   - it is READ-ONLY (no create/update/delete — the agency only views),
 *   - the response shape OMITS `reason` (creator-only) via the dedicated
 *     `AgencyAvailability*` types, mirroring the backend's dedicated
 *     `AgencyAvailabilityResource`.
 *
 * Endpoint: GET /api/v1/agencies/{agency}/creators/{creator}/availability
 *   ?from=&to=   list expanded occurrences (effective window in meta.window)
 */

import type { AgencyAvailabilityListResponse } from '@catalyst/api-client'

import { http } from '@/core/api'

export interface AgencyAvailabilityListParams {
  /** ISO 8601 instant — inclusive window start. */
  from: string
  /** ISO 8601 instant — requested window end (silently clamped to 366d). */
  to: string
}

export const agencyAvailabilityApi = {
  /**
   * List a roster creator's expanded availability for a window. As with the
   * creator-self endpoint, the requested `to` may be silently truncated by the
   * backend's 366-day clamp — always read the effective range from
   * `meta.window` (D-b6 / D-2a-9).
   */
  list(
    agencyId: string,
    creatorUlid: string,
    params: AgencyAvailabilityListParams,
  ): Promise<AgencyAvailabilityListResponse> {
    const query = new URLSearchParams({ from: params.from, to: params.to })
    return http.get<AgencyAvailabilityListResponse>(
      `/agencies/${agencyId}/creators/${creatorUlid}/availability?${query.toString()}`,
    )
  },
}
