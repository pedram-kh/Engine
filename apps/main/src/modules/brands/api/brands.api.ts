/**
 * Typed wrapper for the Brands module API endpoints.
 *
 * All calls are tenant-scoped to the current agency via the
 * `agencyId` parameter (the agency's ULID). The HTTP client handles
 * CSRF preflight and Sanctum cookie auth transparently.
 *
 * Endpoint prefix: /api/v1/agencies/{agency}/brands
 */

import type {
  BrandOptionResource,
  BrandResource,
  CreateBrandPayload,
  PaginatedCollection,
  UpdateBrandPayload,
} from '@catalyst/api-client'
import { http } from '@/core/api'

export interface BrandListParams {
  page?: number
  per_page?: number
  /** 'active' (default) | 'archived' | 'all' (active + archived). */
  status?: 'active' | 'archived' | 'all'
}

export interface SingleBrandEnvelope {
  data: BrandResource
}

export interface BrandOptionsEnvelope {
  data: BrandOptionResource[]
}

function brandsBase(agencyId: string): string {
  return `/agencies/${agencyId}/brands`
}

export const brandsApi = {
  list(
    agencyId: string,
    params: BrandListParams = {},
  ): Promise<PaginatedCollection<BrandResource>> {
    const query = new URLSearchParams()
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    if (params.status !== undefined) query.set('status', params.status)
    const qs = query.toString()
    return http.get<PaginatedCollection<BrandResource>>(
      `${brandsBase(agencyId)}${qs ? `?${qs}` : ''}`,
    )
  },

  /**
   * Every brand for the agency, unpaginated, in the thin `{id, name}`
   * projection — for `<select>` pickers and filter dropdowns (AH-085).
   * NOT for the Brands admin table, which paginates on purpose and should
   * keep using `list()`.
   */
  listOptions(
    agencyId: string,
    status: BrandListParams['status'] = 'active',
  ): Promise<BrandOptionsEnvelope> {
    const query = new URLSearchParams({ for: 'select', status })
    return http.get<BrandOptionsEnvelope>(`${brandsBase(agencyId)}?${query.toString()}`)
  },

  show(agencyId: string, brandId: string): Promise<SingleBrandEnvelope> {
    return http.get<SingleBrandEnvelope>(`${brandsBase(agencyId)}/${brandId}`)
  },

  create(agencyId: string, payload: CreateBrandPayload): Promise<SingleBrandEnvelope> {
    return http.post<SingleBrandEnvelope>(brandsBase(agencyId), payload)
  },

  update(
    agencyId: string,
    brandId: string,
    payload: UpdateBrandPayload,
  ): Promise<SingleBrandEnvelope> {
    return http.patch<SingleBrandEnvelope>(`${brandsBase(agencyId)}/${brandId}`, payload)
  },

  /** Archive — maps to DELETE on the backend (soft-delete + status=archived). */
  archive(agencyId: string, brandId: string): Promise<SingleBrandEnvelope> {
    return http.delete<SingleBrandEnvelope>(`${brandsBase(agencyId)}/${brandId}`)
  },

  /**
   * Restore an archived brand.
   *
   * Sprint 3 Chunk 4 sub-step 6 — un-archives a soft-deleted brand:
   * clears `deleted_at` + flips `status` back to `active`. Requires the
   * agency_admin or agency_manager role; staff get a 403.
   *
   * Idempotent: restoring an already-active brand is a 200 OK no-op
   * (no audit emitted). See `BrandController::restore`.
   */
  restore(agencyId: string, brandId: string): Promise<SingleBrandEnvelope> {
    return http.post<SingleBrandEnvelope>(`${brandsBase(agencyId)}/${brandId}/restore`)
  },

  /**
   * Brand logo — direct multipart (AH-053, D7), the avatar precedent.
   *
   * The field name MUST be `file`: it is the project's canonical multipart
   * field and what `BrandLogoController` validates. Both endpoints return the
   * full brand envelope, so the caller gets the freshly-signed `logo_url`
   * without a second request.
   */
  uploadLogo(agencyId: string, brandId: string, file: File): Promise<SingleBrandEnvelope> {
    const form = new FormData()
    form.append('file', file)
    return http.post<SingleBrandEnvelope>(`${brandsBase(agencyId)}/${brandId}/logo`, form)
  },

  deleteLogo(agencyId: string, brandId: string): Promise<SingleBrandEnvelope> {
    return http.delete<SingleBrandEnvelope>(`${brandsBase(agencyId)}/${brandId}/logo`)
  },
}
