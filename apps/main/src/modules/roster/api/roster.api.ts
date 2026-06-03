/**
 * Typed wrapper for the agency creator-roster API (Sprint 4 Chunk 5).
 *
 * Tenant-scoped to the current agency via the `agencyId` (ULID) path
 * segment, mirroring `brands.api.ts`. The HTTP client handles CSRF
 * preflight + Sanctum cookie auth transparently.
 *
 * Endpoint: GET /api/v1/agencies/{agency}/creators
 */

import type {
  AgencyCreatorDetailEnvelope,
  RosterListParams,
  RosterListResponse,
  UpdateAgencyCreatorRelationPayload,
} from '@catalyst/api-client'

import { http } from '@/core/api'

function rosterBase(agencyId: string): string {
  return `/agencies/${agencyId}/creators`
}

export const rosterApi = {
  /**
   * List the agency's creators across all relationship statuses, with the
   * status / country / language / category filters, name/bio full-text
   * search (`?q=`, Sprint 6 Chunk 1), and the availability range filter
   * (`?available_from=&available_to=`, Sprint 6.5 D-6 — both bounds required).
   * Follower/engagement filters and talent pools remain deferred (inert
   * affordances on the page, D-4).
   */
  list(agencyId: string, params: RosterListParams = {}): Promise<RosterListResponse> {
    const query = new URLSearchParams()
    if (params.status !== undefined) query.set('status', params.status)
    if (params.country !== undefined && params.country !== '') query.set('country', params.country)
    if (params.language !== undefined && params.language !== '')
      query.set('language', params.language)
    if (params.category !== undefined && params.category !== '')
      query.set('category', params.category)
    if (params.q !== undefined && params.q !== '') query.set('q', params.q)
    // Availability window: thread BOTH bounds only when BOTH are set — the
    // backend ignores a one-sided range, so sending a half window is wasted.
    if (
      params.available_from !== undefined &&
      params.available_from !== '' &&
      params.available_to !== undefined &&
      params.available_to !== ''
    ) {
      query.set('available_from', params.available_from)
      query.set('available_to', params.available_to)
    }
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<RosterListResponse>(`${rosterBase(agencyId)}${qs === '' ? '' : `?${qs}`}`)
  },

  /**
   * The per-creator detail view (Sprint 6 Chunk 2a). `creatorUlid` is the
   * creator's ULID (the slim roster row carries it as `creator_id`). 404 when
   * the creator has no relation with this agency (relation-exists tenancy).
   */
  show(agencyId: string, creatorUlid: string): Promise<AgencyCreatorDetailEnvelope> {
    return http.get<AgencyCreatorDetailEnvelope>(`${rosterBase(agencyId)}/${creatorUlid}`)
  },

  /**
   * Edit the relation's rating + notes ONLY (D-2a-3). Admin/manager (a staff
   * member 403s). Returns the re-rendered detail envelope.
   */
  updateRelation(
    agencyId: string,
    creatorUlid: string,
    payload: UpdateAgencyCreatorRelationPayload,
  ): Promise<AgencyCreatorDetailEnvelope> {
    return http.patch<AgencyCreatorDetailEnvelope>(
      `${rosterBase(agencyId)}/${creatorUlid}`,
      payload,
    )
  },
}
