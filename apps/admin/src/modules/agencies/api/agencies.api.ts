/**
 * Admin agency-management API surface (Sprint 13, D-3).
 *
 *   GET  /api/v1/admin/agencies                 — list (cross-agency)
 *   GET  /api/v1/admin/agencies/{ulid}          — detail
 *   POST /api/v1/admin/agencies/{ulid}/suspend       — reason required
 *   POST /api/v1/admin/agencies/{ulid}/reactivate
 *
 * Auth is implicit via the admin SPA's `auth:web_admin` session cookie;
 * the backend gates every route with AgencyPolicy (platform_admin) +
 * EnsureMfaForAdmins. Suspend blocks every agency user's login (the
 * auth-layer enforcement), so the SPA surfaces it as a deliberate,
 * reason-gated action.
 */

import { http } from '@/core/api'

export interface AdminAgencyAttributes {
  name: string
  slug: string
  country_code: string
  subscription_tier: string
  subscription_status: string
  is_active: boolean
  is_suspended: boolean
  suspended_at: string | null
  suspended_reason: string | null
  member_count: number
  created_at: string
}

export interface AdminAgency {
  id: string
  type: 'agencies'
  attributes: AdminAgencyAttributes
}

export interface AdminAgencyListResponse {
  data: AdminAgency[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
  }
}

export interface AdminAgencyEnvelope {
  data: AdminAgency
}

export type AgencyStatusFilter = 'all' | 'active' | 'suspended'

export interface AdminAgencyListParams {
  status?: Exclude<AgencyStatusFilter, 'all'>
  search?: string
  page?: number
  per_page?: number
}

export const adminAgenciesApi = {
  list(params: AdminAgencyListParams = {}): Promise<AdminAgencyListResponse> {
    const query = new URLSearchParams()
    if (params.status !== undefined) query.set('status', params.status)
    if (params.search !== undefined && params.search !== '') query.set('search', params.search)
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<AdminAgencyListResponse>(`/admin/agencies${qs === '' ? '' : `?${qs}`}`)
  },

  show(ulid: string): Promise<AdminAgencyEnvelope> {
    return http.get<AdminAgencyEnvelope>(`/admin/agencies/${ulid}`)
  },

  suspend(ulid: string, reason: string): Promise<AdminAgencyEnvelope> {
    return http.post<AdminAgencyEnvelope>(`/admin/agencies/${ulid}/suspend`, { reason })
  },

  reactivate(ulid: string): Promise<AdminAgencyEnvelope> {
    return http.post<AdminAgencyEnvelope>(`/admin/agencies/${ulid}/reactivate`, {})
  },
}
