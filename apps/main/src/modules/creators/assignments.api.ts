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
  CreatorAssignmentDetailResponse,
  CreatorAssignmentListResponse,
  CreatorContractAcceptResponse,
  CreatorDraftSubmitResponse,
  CreatorPostedContentResponse,
  DraftMediaCompletePayload,
  DraftMediaCompleteResponse,
  DraftMediaInitPayload,
  DraftMediaInitResponse,
  SubmitDraftPayload,
  SubmitPostedContentPayload,
  UpdatePostedContentPayload,
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

  // ── Submission surface (Sprint 9 Chunk 1) ────────────────────────────────

  /** The per-assignment detail payload (assignment + draft history + posted). */
  show(assignmentUlid: string): Promise<CreatorAssignmentDetailResponse> {
    return http.get<CreatorAssignmentDetailResponse>(`${BASE}/${assignmentUlid}`)
  },

  /** Submit OR resubmit a draft (the backend computes the version). */
  submitDraft(
    assignmentUlid: string,
    payload: SubmitDraftPayload,
  ): Promise<CreatorDraftSubmitResponse> {
    return http.post<CreatorDraftSubmitResponse>(`${BASE}/${assignmentUlid}/drafts`, payload)
  },

  /** Initiate a presigned draft-media upload (returns the S3 PUT URL). */
  initDraftMedia(
    assignmentUlid: string,
    payload: DraftMediaInitPayload,
  ): Promise<DraftMediaInitResponse> {
    return http.post<DraftMediaInitResponse>(`${BASE}/${assignmentUlid}/drafts/media/init`, payload)
  },

  /** Verify a presigned draft-media upload landed; returns its storage path. */
  completeDraftMedia(
    assignmentUlid: string,
    payload: DraftMediaCompletePayload,
  ): Promise<DraftMediaCompleteResponse> {
    return http.post<DraftMediaCompleteResponse>(
      `${BASE}/${assignmentUlid}/drafts/media/complete`,
      payload,
    )
  },

  /** Self-report the published post (approved → posted). */
  submitPostedContent(
    assignmentUlid: string,
    payload: SubmitPostedContentPayload,
  ): Promise<CreatorPostedContentResponse> {
    return http.post<CreatorPostedContentResponse>(
      `${BASE}/${assignmentUlid}/posted-content`,
      payload,
    )
  },

  /**
   * Edit the post URL IN PLACE after a failed auto-verification (verification-
   * resolution chunk, ACT3/D-6). Resets verification → pending + re-arms the
   * verify job; no state transition (stays posted). Fail-closed server-side: a
   * non-posted/non-failed row 422s (`assignment.not_resolvable`).
   */
  updatePostedContent(
    assignmentUlid: string,
    payload: UpdatePostedContentPayload,
  ): Promise<CreatorPostedContentResponse> {
    return http.patch<CreatorPostedContentResponse>(
      `${BASE}/${assignmentUlid}/posted-content`,
      payload,
    )
  },

  /** Accept the per-campaign contract (accepted → contracted). */
  acceptContract(assignmentUlid: string): Promise<CreatorContractAcceptResponse> {
    return http.post<CreatorContractAcceptResponse>(`${BASE}/${assignmentUlid}/contract/accept`)
  },
}
