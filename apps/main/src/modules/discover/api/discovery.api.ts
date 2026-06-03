/**
 * Typed wrapper for the creator discovery API.
 *
 * Tenant-scoped to the current agency via the `agencyId` (ULID) path segment,
 * mirroring `roster.api.ts`. The HTTP client handles CSRF preflight + Sanctum
 * cookie auth transparently.
 *
 *   GET  /api/v1/agencies/{agency}/creators/discover            — browse the pool
 *   GET  /api/v1/agencies/{agency}/creators/discover/{creator}  — public profile
 *   POST /api/v1/agencies/{agency}/creators/discover/{creator}/connection-request
 *        — send (or re-send) a connection request (Sprint 6.6b, D-7/D-10).
 */

import type {
  ConnectionRequestResponse,
  CreatorPublicProfileEnvelope,
  DiscoveryListParams,
  DiscoveryListResponse,
} from '@catalyst/api-client'

import { http } from '@/core/api'

function discoverBase(agencyId: string): string {
  return `/agencies/${agencyId}/creators/discover`
}

export const discoveryApi = {
  /**
   * Browse/search the global creator pool. Only approved + discoverable
   * creators are returned (the backend's fail-closed gate). Each row carries
   * the calling agency's own "already-connected" status (never another
   * agency's).
   */
  list(agencyId: string, params: DiscoveryListParams = {}): Promise<DiscoveryListResponse> {
    const query = new URLSearchParams()
    if (params.country !== undefined && params.country !== '') query.set('country', params.country)
    if (params.language !== undefined && params.language !== '')
      query.set('language', params.language)
    if (params.category !== undefined && params.category !== '')
      query.set('category', params.category)
    if (params.q !== undefined && params.q !== '') query.set('q', params.q)
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<DiscoveryListResponse>(`${discoverBase(agencyId)}${qs === '' ? '' : `?${qs}`}`)
  },

  /**
   * The public profile of a discovered creator. Does NOT 404 when the agency
   * has no relation with the creator (D-6) — but DOES 404 for a creator who
   * isn't discoverable (the fail-closed gate). `creatorUlid` is the creator's
   * ULID (a discovery card carries it as its `id`).
   */
  show(agencyId: string, creatorUlid: string): Promise<CreatorPublicProfileEnvelope> {
    return http.get<CreatorPublicProfileEnvelope>(`${discoverBase(agencyId)}/${creatorUlid}`)
  },

  /**
   * Send (or re-send) a connection request to a discovered creator
   * (Sprint 6.6b, D-7/D-10). Admin/manager only (the backend 403s staff).
   * Creates the relation in `pending_request` from no-relation, re-requests
   * from `declined` (D-4), and is an idempotent no-op surfacing the existing
   * state for `pending_request`/`roster`. The response carries the resulting
   * `relationship_status` so the caller can re-derive the button state.
   */
  sendConnectionRequest(agencyId: string, creatorUlid: string): Promise<ConnectionRequestResponse> {
    return http.post<ConnectionRequestResponse>(
      `${discoverBase(agencyId)}/${creatorUlid}/connection-request`,
    )
  },
}
