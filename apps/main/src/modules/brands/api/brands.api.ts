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
}
