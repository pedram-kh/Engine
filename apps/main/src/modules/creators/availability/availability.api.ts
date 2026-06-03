/**
 * Typed wrapper for the creator availability calendar endpoints
 * (Sprint 5 Chunk A backend; Chunk B UI consumer).
 *
 * All calls are creator-self-scoped — the backend resolves every row from
 * `$request->user()->creator`, never a path id, so there is no agency/path
 * parameter here (unlike the brands API). The HTTP client handles CSRF
 * preflight + Sanctum cookie auth transparently.
 *
 * Endpoint prefix: /api/v1/creators/me/availability
 *
 *   GET    ?from=&to=    list expanded occurrences (window in meta.window)
 *   POST                 create a block
 *   PATCH  {ulid}        full-resource replace
 *   DELETE {ulid}        delete the whole block (series-level, D-b8)
 */

import type {
  AvailabilityListResponse,
  CreateAvailabilityBlockPayload,
  SingleAvailabilityResponse,
  UpdateAvailabilityBlockPayload,
} from '@catalyst/api-client'
import { http } from '@/core/api'

const BASE = '/creators/me/availability'

export interface AvailabilityListParams {
  /** ISO 8601 instant — inclusive window start. */
  from: string
  /** ISO 8601 instant — requested window end (silently clamped to 366d). */
  to: string
}

export const availabilityApi = {
  /**
   * List expanded occurrences for a window. The requested `to` may be
   * silently truncated by the backend's 366-day clamp — always read the
   * effective range from the response `meta.window` (D-b6).
   */
  list(params: AvailabilityListParams): Promise<AvailabilityListResponse> {
    const query = new URLSearchParams({ from: params.from, to: params.to })
    return http.get<AvailabilityListResponse>(`${BASE}?${query.toString()}`)
  },

  create(payload: CreateAvailabilityBlockPayload): Promise<SingleAvailabilityResponse> {
    return http.post<SingleAvailabilityResponse>(BASE, payload)
  },

  /**
   * Update is a FULL-RESOURCE REPLACE — the payload must carry every
   * field, not a diff. Editing any occurrence of a recurring block edits
   * the WHOLE series (the backend has no per-occurrence exception path,
   * D-b8).
   */
  update(
    ulid: string,
    payload: UpdateAvailabilityBlockPayload,
  ): Promise<SingleAvailabilityResponse> {
    return http.patch<SingleAvailabilityResponse>(`${BASE}/${ulid}`, payload)
  },

  /** Delete the whole block (series-level). Resolves on 204 No Content. */
  delete(ulid: string): Promise<void> {
    return http.delete<void>(`${BASE}/${ulid}`)
  },
}
