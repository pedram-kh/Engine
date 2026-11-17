/**
 * PL-1.1 — client files on a brand. Mirrors `BrandFileResource` / `BrandFileController` in `apps/api`.
 * `storage_key` is never sent; `download_url` is a fresh signed GET forced to attachment.
 */

export type BrandFileType = 'brief' | 'research' | 'guidelines' | 'assets' | 'other'

export interface BrandFileResource {
  id: string
  type: 'brand_files'
  attributes: {
    original_filename: string
    mime_type: string
    size_bytes: number
    file_type: BrandFileType
    /** Null on a non-S3 disk (local dev without object storage). */
    download_url: string | null
    created_at: string
  }
  relationships: {
    /** Null when the uploader account was later deleted. */
    uploaded_by: { data: { id: string; type: 'users'; attributes: { name: string } } } | null
  }
}

export interface BrandFileListResponse {
  data: BrandFileResource[]
  meta: { total: number; page: number; per_page: number; last_page: number }
}

export interface BrandFileEnvelope {
  data: BrandFileResource
}

export interface BrandFileUploadInitPayload {
  mime_type: string
  declared_bytes: number
  file_type: BrandFileType
}

export interface BrandFileUploadInitResponse {
  data: {
    upload_url: string
    upload_id: string
    storage_path: string
    expires_at: string
    max_bytes: number
  }
}

export interface BrandFileUploadCompletePayload {
  upload_id: string
  original_filename: string
  file_type: BrandFileType
}
