/**
 * Typed wrapper for the creator discovery API (Sprint 6.6a — the read path).
 *
 * Tenant-scoped to the current agency via the `agencyId` (ULID) path segment,
 * mirroring `roster.api.ts`. The HTTP client handles CSRF preflight + Sanctum
 * cookie auth transparently.
 *
 *   GET /api/v1/agencies/{agency}/creators/discover            — browse the pool
 *   GET /api/v1/agencies/{agency}/creators/discover/{creator}  — public profile
 *
 * Read-only this chunk (D-9): there is NO send-request action — that, and the
 * `pending_request`/`declined` statuses, is Sprint 6.6b.
 */

import type {
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
}
