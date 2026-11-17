/**
 * Typed wrapper for the brand client-files endpoints (PL-1.1).
 *
 * Endpoint prefix: /api/v1/agencies/{agency}/brands/{brand}/files. Behind the `waves_enabled` flag (404 while
 * off). The presigned dance is two server calls here plus the vendor PUT in between (`uploadToPresignedUrl`
 * from `@catalyst/api-client`), the AgencyFileUploadDialog precedent.
 */

import type {
  BrandFileEnvelope,
  BrandFileListResponse,
  BrandFileUploadCompletePayload,
  BrandFileUploadInitPayload,
  BrandFileUploadInitResponse,
} from '@catalyst/api-client'
import { http } from '@/core/api'

function base(agencyId: string, brandId: string): string {
  return `/agencies/${agencyId}/brands/${brandId}/files`
}

export const brandFilesApi = {
  list(
    agencyId: string,
    brandId: string,
    params: { page?: number; per_page?: number } = {},
  ): Promise<BrandFileListResponse> {
    const query = new URLSearchParams()
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<BrandFileListResponse>(`${base(agencyId, brandId)}${qs ? `?${qs}` : ''}`)
  },

  initUpload(
    agencyId: string,
    brandId: string,
    payload: BrandFileUploadInitPayload,
  ): Promise<BrandFileUploadInitResponse> {
    return http.post<BrandFileUploadInitResponse>(
      `${base(agencyId, brandId)}/uploads/init`,
      payload,
    )
  },

  completeUpload(
    agencyId: string,
    brandId: string,
    payload: BrandFileUploadCompletePayload,
  ): Promise<BrandFileEnvelope> {
    return http.post<BrandFileEnvelope>(`${base(agencyId, brandId)}/uploads/complete`, payload)
  },

  /** A fresh signed `download_url` (60 minutes, forced attachment). */
  show(agencyId: string, brandId: string, fileId: string): Promise<BrandFileEnvelope> {
    return http.get<BrandFileEnvelope>(`${base(agencyId, brandId)}/${fileId}`)
  },

  /** Resolves on 204. Object-first delete server-side. */
  delete(agencyId: string, brandId: string, fileId: string): Promise<void> {
    return http.delete<void>(`${base(agencyId, brandId)}/${fileId}`)
  },
}
