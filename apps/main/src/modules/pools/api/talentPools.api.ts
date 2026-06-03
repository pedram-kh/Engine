/**
 * Typed wrapper for the Talent Pools module API (Sprint 6 Chunk 2b).
 *
 * Tenant-scoped to the current agency via the `agencyId` (ULID) path segment,
 * mirroring `brands.api.ts`. The HTTP client handles CSRF preflight + Sanctum
 * cookie auth transparently.
 *
 * Endpoint prefix: /api/v1/agencies/{agency}/talent-pools
 */

import type {
  CreateTalentPoolPayload,
  PaginatedCollection,
  TalentPoolMemberResource,
  TalentPoolPickerResponse,
  TalentPoolResource,
  UpdateTalentPoolPayload,
} from '@catalyst/api-client'

import { http } from '@/core/api'

export interface TalentPoolListParams {
  page?: number
  per_page?: number
  /** 'active' (default) | 'archived' | 'all' (active + archived). */
  status?: 'active' | 'archived' | 'all'
}

export interface SingleTalentPoolEnvelope {
  data: TalentPoolResource
}

function poolsBase(agencyId: string): string {
  return `/agencies/${agencyId}/talent-pools`
}

export const talentPoolsApi = {
  list(
    agencyId: string,
    params: TalentPoolListParams = {},
  ): Promise<PaginatedCollection<TalentPoolResource>> {
    const query = new URLSearchParams()
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    if (params.status !== undefined) query.set('status', params.status)
    const qs = query.toString()
    return http.get<PaginatedCollection<TalentPoolResource>>(
      `${poolsBase(agencyId)}${qs ? `?${qs}` : ''}`,
    )
  },

  show(agencyId: string, poolId: string): Promise<SingleTalentPoolEnvelope> {
    return http.get<SingleTalentPoolEnvelope>(`${poolsBase(agencyId)}/${poolId}`)
  },

  create(agencyId: string, payload: CreateTalentPoolPayload): Promise<SingleTalentPoolEnvelope> {
    return http.post<SingleTalentPoolEnvelope>(poolsBase(agencyId), payload)
  },

  update(
    agencyId: string,
    poolId: string,
    payload: UpdateTalentPoolPayload,
  ): Promise<SingleTalentPoolEnvelope> {
    return http.patch<SingleTalentPoolEnvelope>(`${poolsBase(agencyId)}/${poolId}`, payload)
  },

  /** Archive — maps to DELETE on the backend (pure soft-delete, no status flip). */
  archive(agencyId: string, poolId: string): Promise<SingleTalentPoolEnvelope> {
    return http.delete<SingleTalentPoolEnvelope>(`${poolsBase(agencyId)}/${poolId}`)
  },

  /** Restore an archived pool. Idempotent: an already-active pool is a 200 no-op. */
  restore(agencyId: string, poolId: string): Promise<SingleTalentPoolEnvelope> {
    return http.post<SingleTalentPoolEnvelope>(`${poolsBase(agencyId)}/${poolId}/restore`)
  },

  /** Paginated members of a pool (pool DETAIL page). */
  members(
    agencyId: string,
    poolId: string,
    params: { page?: number; per_page?: number } = {},
  ): Promise<PaginatedCollection<TalentPoolMemberResource>> {
    const query = new URLSearchParams()
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<PaginatedCollection<TalentPoolMemberResource>>(
      `${poolsBase(agencyId)}/${poolId}/creators${qs ? `?${qs}` : ''}`,
    )
  },

  /** Add a roster creator to a pool (idempotent). Returns the refreshed pool. */
  addCreator(
    agencyId: string,
    poolId: string,
    creatorUlid: string,
  ): Promise<SingleTalentPoolEnvelope> {
    return http.post<SingleTalentPoolEnvelope>(`${poolsBase(agencyId)}/${poolId}/creators`, {
      creator_id: creatorUlid,
    })
  },

  /** Remove a creator from a pool. Returns the refreshed pool. */
  removeCreator(
    agencyId: string,
    poolId: string,
    creatorUlid: string,
  ): Promise<SingleTalentPoolEnvelope> {
    return http.delete<SingleTalentPoolEnvelope>(
      `${poolsBase(agencyId)}/${poolId}/creators/${creatorUlid}`,
    )
  },

  /**
   * The add-to-pool picker fetch (D-2b-9): the agency's pools each flagged
   * `is_member` for this creator. One query server-side (no N+1).
   */
  poolsForCreator(agencyId: string, creatorUlid: string): Promise<TalentPoolPickerResponse> {
    return http.get<TalentPoolPickerResponse>(
      `/agencies/${agencyId}/creators/${creatorUlid}/talent-pools`,
    )
  },
}
