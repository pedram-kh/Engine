/**
 * Typed wrapper for the agency creator-roster API (Sprint 4 Chunk 5).
 *
 * Tenant-scoped to the current agency via the `agencyId` (ULID) path
 * segment, mirroring `brands.api.ts`. The HTTP client handles CSRF
 * preflight + Sanctum cookie auth transparently.
 *
 * Endpoint: GET /api/v1/agencies/{agency}/creators
 */

import type { RosterListParams, RosterListResponse } from '@catalyst/api-client'

import { http } from '@/core/api'

function rosterBase(agencyId: string): string {
  return `/agencies/${agencyId}/creators`
}

export const rosterApi = {
  /**
   * List the agency's creators across all relationship statuses, with the
   * status / country / language / category filters that have backing data
   * today (D-c5-1). FTS search, follower/availability filters and talent
   * pools are deferred (Sprint 5/6).
   */
  list(agencyId: string, params: RosterListParams = {}): Promise<RosterListResponse> {
    const query = new URLSearchParams()
    if (params.status !== undefined) query.set('status', params.status)
    if (params.country !== undefined && params.country !== '') query.set('country', params.country)
    if (params.language !== undefined && params.language !== '')
      query.set('language', params.language)
    if (params.category !== undefined && params.category !== '')
      query.set('category', params.category)
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<RosterListResponse>(`${rosterBase(agencyId)}${qs === '' ? '' : `?${qs}`}`)
  },
}
