/**
 * Typed wrapper for the CREATOR-side connection-request inbox endpoints
 * (Sprint 6.6b backend; Sprint 6.6c UI consumer — D-d4).
 *
 * All calls are creator-self-scoped — the backend resolves every relation from
 * `$request->user()->creator` by `creator_id`, never a path id, so there is no
 * agency/path parameter here (mirroring `availability.api.ts`, NOT the
 * agency-path-scoped `discovery.api.ts::sendConnectionRequest`, which is the
 * OTHER side of the lifecycle). The HTTP client handles CSRF preflight +
 * Sanctum cookie auth transparently.
 *
 * Endpoint prefix: /api/v1/creators/me/connection-requests
 *
 *   GET                       list the creator's pending requests (flat data[])
 *   POST  {relation}/accept   accept → roster
 *   POST  {relation}/decline  decline → declined
 *
 * ⚠ The `{relation}` segment is the ROW's `id` ULID (the relation ULID), NOT
 *   the agency's id (D-d3) — pass `item.id` straight through.
 */

import type {
  ConnectionRequestActionResponse,
  ConnectionRequestListResponse,
} from '@catalyst/api-client'
import { http } from '@/core/api'

const BASE = '/creators/me/connection-requests'

export const connectionRequestsApi = {
  /**
   * List the creator's incoming pending requests (newest-first). A flat
   * `data: [...]` envelope — there is NO pagination meta (D-d2).
   */
  list(): Promise<ConnectionRequestListResponse> {
    return http.get<ConnectionRequestListResponse>(BASE)
  },

  /**
   * Accept a request (pending_request → roster). `relationUlid` is the row's
   * `id` ULID. Fail-closed server-side: a non-pending row 422s
   * (`connection.not_pending`), a non-owned ULID 404s (`connection.not_found`).
   */
  accept(relationUlid: string): Promise<ConnectionRequestActionResponse> {
    return http.post<ConnectionRequestActionResponse>(`${BASE}/${relationUlid}/accept`)
  },

  /** Decline a request (pending_request → declined). Same `id`-ULID + fail-closed contract as `accept`. */
  decline(relationUlid: string): Promise<ConnectionRequestActionResponse> {
    return http.post<ConnectionRequestActionResponse>(`${BASE}/${relationUlid}/decline`)
  },
}
