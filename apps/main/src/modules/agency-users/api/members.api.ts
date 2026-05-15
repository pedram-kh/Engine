/**
 * Typed wrapper for the agency-members listing endpoint.
 *
 * Sprint 3 Chunk 4 sub-step 7 — replaces the Sprint 2 placeholder that
 * rendered `useAgencyStore.memberships` (which is the *current user's*
 * agency memberships, not the *agency's* member list). The new
 * endpoint paginates over `agency_memberships` rows scoped to the
 * `{agency}` path param.
 *
 * Filter / sort / search params mirror the backend's accepted query
 * shape (see `MembershipController::index`).
 */

import type {
  AgencyMembershipResource,
  AgencyRole,
  PaginatedCollection,
} from '@catalyst/api-client'
import { http } from '@/core/api'

export interface MemberListParams {
  page?: number
  per_page?: number
  /** Filter by role. Omit for all roles. */
  role?: AgencyRole
  /** Case-insensitive search across name + email. */
  search?: string
  /** Backend currently supports `name`, `email`, `created_at`. */
  sort?: 'name' | 'email' | 'created_at'
  /** Sort direction; defaults to `desc` on `created_at`, `asc` otherwise. */
  order?: 'asc' | 'desc'
}

function buildQuery(params: MemberListParams): string {
  const query = new URLSearchParams()
  if (params.page !== undefined) query.set('page', String(params.page))
  if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
  if (params.role !== undefined) query.set('role', params.role)
  if (params.search !== undefined && params.search.trim() !== '') {
    query.set('search', params.search.trim())
  }
  if (params.sort !== undefined) query.set('sort', params.sort)
  if (params.order !== undefined) query.set('order', params.order)
  const qs = query.toString()
  return qs ? `?${qs}` : ''
}

export const membersApi = {
  /**
   * GET /api/v1/agencies/{agency}/members
   *
   * Lists members of the agency. Any agency member can read the list;
   * the backend filters out cross-tenant access at the route level.
   */
  list(
    agencyId: string,
    params: MemberListParams = {},
  ): Promise<PaginatedCollection<AgencyMembershipResource>> {
    return http.get<PaginatedCollection<AgencyMembershipResource>>(
      `/agencies/${agencyId}/members${buildQuery(params)}`,
    )
  },
}
