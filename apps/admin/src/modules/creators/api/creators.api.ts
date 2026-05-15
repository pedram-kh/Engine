/**
 * Admin-side API surface for the Creator drill-in (Sprint 3 Chunk 3
 * sub-step 9).
 *
 * Endpoint: GET /api/v1/admin/creators/{ulid}
 *
 * The response shape reuses `CreatorResource` with the
 * `admin_attributes` block appended (rejection_reason +
 * kyc_verifications history; PII like decision_data /
 * failure_reason is NEVER surfaced — admin drill-in for those
 * fields ships in Sprint 4+).
 *
 * Authentication: implicit via the admin SPA's `auth:web_admin`
 * session cookie set by UseAdminSessionCookie. The backend
 * additionally gates this route with EnsureMfaForAdmins.
 */

import type { CreatorResourceEnvelope } from '@catalyst/api-client'

import { http } from '@/core/api'

export const adminCreatorsApi = {
  /**
   * Fetch a single Creator by its ULID. Surfaces the
   * `admin_attributes` block on the response (via the backend's
   * `CreatorResource::withAdmin()`). Returns the standard
   * `CreatorResourceEnvelope` shape; the SPA reads the
   * `admin_attributes` block via `as` narrowing where needed.
   */
  show(creatorUlid: string): Promise<CreatorResourceEnvelope> {
    return http.get<CreatorResourceEnvelope>(`/admin/creators/${creatorUlid}`)
  },
}
