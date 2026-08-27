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

/**
 * A member row on the agency drill-in (AH-087). Keys mirror the backend's
 * pinned keyset verbatim (AdminAgencyMemberController::serialize) — the pin
 * is what proves no secret-adjacent field can drift in: enrollment reaches
 * the UI as a BOOLEAN and nothing else. There is no field here for a TOTP
 * secret, a recovery code, or a code count, and there never should be.
 *
 * The MFA column renders three states from two booleans: `is_mfa_exempt`
 * wins the chip, otherwise `is_mfa_enrolled` picks enrolled / not enrolled.
 */
export interface AdminAgencyMemberAttributes {
  user_id: string
  name: string
  email: string
  role: string
  status: 'active' | 'pending'
  joined_at: string
  last_active_at: string | null
  is_mfa_enrolled: boolean
  is_mfa_exempt: boolean
  mfa_exempted_at: string | null
  mfa_exemption_reason: string | null
}

export interface AdminAgencyMember {
  id: string
  type: 'agency_members'
  attributes: AdminAgencyMemberAttributes
}

export interface AdminAgencyMemberListResponse {
  data: AdminAgencyMember[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
  }
}

export interface AdminAgencyMemberEnvelope {
  data: AdminAgencyMember
}

export interface AdminAgencyMemberListParams {
  role?: string
  search?: string
  sort?: string
  page?: number
  per_page?: number
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

  // ── Members drill-in + per-member 2FA controls (AH-087) ──────────────────
  // All three writes take a mandatory reason (the backend enforces min 10)
  // and return the updated member row so the table can refresh in place.

  members(
    ulid: string,
    params: AdminAgencyMemberListParams = {},
  ): Promise<AdminAgencyMemberListResponse> {
    const query = new URLSearchParams()
    if (params.role !== undefined && params.role !== '') query.set('role', params.role)
    if (params.search !== undefined && params.search !== '') query.set('search', params.search)
    if (params.sort !== undefined && params.sort !== '') query.set('sort', params.sort)
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<AdminAgencyMemberListResponse>(
      `/admin/agencies/${ulid}/members${qs === '' ? '' : `?${qs}`}`,
    )
  },

  resetMemberTwoFactor(
    ulid: string,
    userUlid: string,
    reason: string,
  ): Promise<AdminAgencyMemberEnvelope> {
    return http.post<AdminAgencyMemberEnvelope>(
      `/admin/agencies/${ulid}/members/${userUlid}/reset-2fa`,
      { reason },
    )
  },

  exemptMemberFromTwoFactor(
    ulid: string,
    userUlid: string,
    reason: string,
  ): Promise<AdminAgencyMemberEnvelope> {
    return http.post<AdminAgencyMemberEnvelope>(
      `/admin/agencies/${ulid}/members/${userUlid}/exempt-2fa`,
      { reason },
    )
  },

  removeMemberTwoFactorExemption(
    ulid: string,
    userUlid: string,
    reason: string,
  ): Promise<AdminAgencyMemberEnvelope> {
    return http.post<AdminAgencyMemberEnvelope>(
      `/admin/agencies/${ulid}/members/${userUlid}/remove-2fa-exemption`,
      { reason },
    )
  },
}
