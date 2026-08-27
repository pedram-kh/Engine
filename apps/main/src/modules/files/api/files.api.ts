/**
 * Typed wrapper for the Agency Files library API endpoints (AH-091, chunk A).
 *
 * All calls are tenant-scoped to the current agency via the `agencyId`
 * parameter (the agency's ULID). Endpoint prefix:
 * /api/v1/agencies/{agency}/files
 *
 * The presigned-upload dance is two calls here plus the vendor PUT in
 * between (the `AttachContractDialog` / `ChatPanel` precedent):
 *   1. `initUpload()`   → a presigned PUT + `upload_id`.
 *   2. `uploadToPresignedUrl()` (from `@catalyst/api-client`) → the raw PUT.
 *   3. `completeUpload()` → verifies the landed object and creates the row.
 */

import type {
  AgencyFileEnvelope,
  AgencyFileListParams,
  AgencyFileListResponse,
  AgencyFileUploadCompletePayload,
  AgencyFileUploadInitPayload,
  AgencyFileUploadInitResponse,
} from '@catalyst/api-client'
import { http } from '@/core/api'

function filesBase(agencyId: string): string {
  return `/agencies/${agencyId}/files`
}

export const filesApi = {
  list(agencyId: string, params: AgencyFileListParams = {}): Promise<AgencyFileListResponse> {
    const query = new URLSearchParams()
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<AgencyFileListResponse>(`${filesBase(agencyId)}${qs ? `?${qs}` : ''}`)
  },

  initUpload(
    agencyId: string,
    payload: AgencyFileUploadInitPayload,
  ): Promise<AgencyFileUploadInitResponse> {
    return http.post<AgencyFileUploadInitResponse>(`${filesBase(agencyId)}/uploads/init`, payload)
  },

  completeUpload(
    agencyId: string,
    payload: AgencyFileUploadCompletePayload,
  ): Promise<AgencyFileEnvelope> {
    return http.post<AgencyFileEnvelope>(`${filesBase(agencyId)}/uploads/complete`, payload)
  },

  /** A fresh signed `download_url` (60 min, forced attachment — D4). */
  show(agencyId: string, fileId: string): Promise<AgencyFileEnvelope> {
    return http.get<AgencyFileEnvelope>(`${filesBase(agencyId)}/${fileId}`)
  },

  /** Resolves on 204 No Content. Object-first hard delete server-side (D5). */
  delete(agencyId: string, fileId: string): Promise<void> {
    return http.delete<void>(`${filesBase(agencyId)}/${fileId}`)
  },
}
