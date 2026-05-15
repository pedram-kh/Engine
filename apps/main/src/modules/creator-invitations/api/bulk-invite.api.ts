/**
 * Agency-side bulk creator-invitation API client.
 *
 * Sprint 3 Chunk 4 sub-step 11. Wraps two backend endpoints:
 *
 *   POST /api/v1/agencies/{agency}/creators/invitations/bulk
 *     multipart/form-data { file: CSV }
 *     202 → { data: { id, type }, meta: { row_count, exceeds_soft_warning, errors }, links: { self } }
 *
 *   GET /api/v1/jobs/{jobId}
 *     200 → TrackedJobResource
 *
 * The bulk endpoint is uniformly 202 + queued (Decision B reinterpreted
 * as single async path at plan-pause-time, Q-pause-PC6 = (α)). The SPA
 * polls the tracked-job endpoint at 3s cadence until the status is
 * terminal (`complete` or `failed`).
 *
 * CSV contract mirrors `BulkInviteCsvParser` exactly: only the `email`
 * column is required + validated; other columns are accepted but
 * ignored. The pre-upload preview the SPA renders (parse client-side)
 * validates only the email column for parity.
 */

import { http } from '@/core/api'

export type BulkInviteJobStatus = 'queued' | 'processing' | 'complete' | 'failed'

export interface BulkInviteParseError {
  row: number
  code: string
  detail: string
}

export interface BulkInviteSubmitMeta {
  row_count: number
  exceeds_soft_warning: boolean
  errors: BulkInviteParseError[]
}

export interface BulkInviteSubmitResponse {
  data: { id: string; type: 'bulk_creator_invitation' }
  meta: BulkInviteSubmitMeta
  links: { self: string }
}

export interface BulkInviteFailureRow {
  email: string
  reason: string
}

export interface BulkInviteResult {
  stats: {
    invited: number
    already_invited: number
    failed: number
  }
  failures: BulkInviteFailureRow[]
}

export interface TrackedJobResponse {
  data: {
    id: string
    type: string
    status: BulkInviteJobStatus
    progress: number
    started_at: string | null
    completed_at: string | null
    estimated_completion_at: string | null
    result: BulkInviteResult | null
    failure_reason: string | null
  }
}

export const bulkInviteApi = {
  /**
   * Submit a CSV file for bulk-invite processing. Returns the tracked
   * job ulid + parse meta on 202 Accepted. The caller polls
   * {@link getJob} on the returned id to await completion.
   */
  submit(agencyUlid: string, file: File): Promise<BulkInviteSubmitResponse> {
    const form = new FormData()
    form.append('file', file)
    return http.post<BulkInviteSubmitResponse>(
      `/agencies/${agencyUlid}/creators/invitations/bulk`,
      form,
    )
  },

  /**
   * Poll the tracked-job endpoint for a single status snapshot.
   * Returns the full TrackedJobResource shape; callers narrow on
   * `data.status.isTerminal()`-style logic upstream.
   */
  getJob(jobUlid: string): Promise<TrackedJobResponse> {
    return http.get<TrackedJobResponse>(`/jobs/${jobUlid}`)
  },
}
