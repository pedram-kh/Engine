/**
 * Typed wrapper for the CREATOR-side campaign-assignment endpoints (Sprint 8
 * Chunk 2 backend, D-9; the `/creator/assignments` surface is the UI consumer).
 *
 * All calls are creator-self-scoped — the backend resolves every assignment
 * from `$request->user()->creator` by `creator_id`, never a path id, so there
 * is no agency/path parameter here (mirroring `connectionRequests.api.ts`). The
 * HTTP client handles CSRF preflight + Sanctum cookie auth transparently.
 *
 * Endpoint prefix: /api/v1/creators/me/assignments
 *
 *   GET                        list the creator's assignments (flat data[])
 *   POST  {assignment}/accept  invited → accepted
 *   POST  {assignment}/decline invited → declined
 *   POST  {assignment}/counter invited → countered (a proposed fee)
 *
 * ⚠ The `{assignment}` segment is the assignment ROW's `id` ULID — pass
 *   `item.id` straight through. Fail-closed server-side: a non-invited row 422s
 *   (`assignment.not_invited`), a non-owned ULID 404s (`assignment.not_found`).
 */

import type {
  CounterAssignmentPayload,
  CreatorAssignmentActionResponse,
  CreatorAssignmentListResponse,
} from '@catalyst/api-client'
import { http } from '@/core/api'

const BASE = '/creators/me/assignments'

export const creatorAssignmentsApi = {
  /** List the creator's assignments across all agencies (flat `data: [...]`). */
  list(): Promise<CreatorAssignmentListResponse> {
    return http.get<CreatorAssignmentListResponse>(BASE)
  },

  accept(assignmentUlid: string): Promise<CreatorAssignmentActionResponse> {
    return http.post<CreatorAssignmentActionResponse>(`${BASE}/${assignmentUlid}/accept`)
  },

  decline(assignmentUlid: string): Promise<CreatorAssignmentActionResponse> {
    return http.post<CreatorAssignmentActionResponse>(`${BASE}/${assignmentUlid}/decline`)
  },

  counter(
    assignmentUlid: string,
    payload: CounterAssignmentPayload,
  ): Promise<CreatorAssignmentActionResponse> {
    return http.post<CreatorAssignmentActionResponse>(`${BASE}/${assignmentUlid}/counter`, payload)
  },
}
