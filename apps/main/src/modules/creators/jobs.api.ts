/**
 * Typed wrapper for the creator JOBS BOARD (Jobs Board chunk 3, AH-056).
 *
 * Creator-self-scoped like its `assignments.api.ts` sibling: the backend
 * resolves the caller from `$request->user()->creator` and applies the whole
 * visibility predicate itself, so there is no agency parameter here and no
 * client-side filtering to keep in sync.
 *
 * Endpoint prefix: /api/v1/creators/me/jobs
 *
 *   GET                  the paginated board (jobs the caller may see)
 *   GET   {ulid}         one job's detail
 *   POST  {ulid}/apply   apply, with an optional note
 *
 * ⚠ Fail-closed server-side, and the SPA must not paper over it: a job that is
 *   delisted, ended, terminal, un-rostered or brand-hard-blacklisted is a flat
 *   404 on BOTH the detail and the apply route — including between a board
 *   render and the click that follows it. A duplicate apply is a 409 carrying
 *   `job.already_applied` or `job.application_rejected`.
 */

import type {
  ApplyToJobPayload,
  CreatorJobApplyResponse,
  CreatorJobDetailResponse,
  CreatorJobListResponse,
} from '@catalyst/api-client'
import { http } from '@/core/api'

const BASE = '/creators/me/jobs'

export interface CreatorJobListParams {
  page?: number
  perPage?: number
}

export const creatorJobsApi = {
  /** One page of the board. Server-capped `per_page`; newest listing first. */
  list(params: CreatorJobListParams = {}): Promise<CreatorJobListResponse> {
    const query = new URLSearchParams()
    if (params.page !== undefined) {
      query.set('page', String(params.page))
    }
    if (params.perPage !== undefined) {
      query.set('per_page', String(params.perPage))
    }
    const suffix = query.toString()
    return http.get<CreatorJobListResponse>(suffix.length > 0 ? `${BASE}?${suffix}` : BASE)
  },

  show(jobUlid: string): Promise<CreatorJobDetailResponse> {
    return http.get<CreatorJobDetailResponse>(`${BASE}/${jobUlid}`)
  },

  apply(jobUlid: string, payload: ApplyToJobPayload = {}): Promise<CreatorJobApplyResponse> {
    return http.post<CreatorJobApplyResponse>(`${BASE}/${jobUlid}/apply`, payload)
  },
}
