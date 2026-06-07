/**
 * Admin audit-log viewer API (Sprint 13, D-5).
 *
 *   GET /api/v1/admin/audit-logs — read-only, cross-agency,
 *        cursor-paginated. All filters target indexed columns.
 *
 * Auth is implicit via the admin SPA's `auth:web_admin` session cookie;
 * the backend gates the route with the platform_admin bounded bypass +
 * EnsureMfaForAdmins.
 */

import { http } from '@/core/api'

export interface AdminAuditLog {
  id: string
  type: 'audit_logs'
  attributes: {
    action: string
    actor_id: number | null
    actor_name: string | null
    actor_email: string | null
    actor_role: string | null
    agency_id: number | null
    subject_type: string | null
    subject_ulid: string | null
    reason: string | null
    ip: string | null
    created_at: string
  }
}

export interface AdminAuditLogResponse {
  data: AdminAuditLog[]
  meta: {
    per_page: number
    next_cursor: string | null
    prev_cursor: string | null
    has_more: boolean
  }
}

export interface AdminAuditLogParams {
  action?: string
  actor_id?: number
  agency_id?: number
  subject_ulid?: string
  date_from?: string
  date_to?: string
  per_page?: number
  cursor?: string
}

export const adminAuditApi = {
  list(params: AdminAuditLogParams = {}): Promise<AdminAuditLogResponse> {
    const query = new URLSearchParams()
    if (params.action !== undefined && params.action !== '') query.set('action', params.action)
    if (params.actor_id !== undefined) query.set('actor_id', String(params.actor_id))
    if (params.agency_id !== undefined) query.set('agency_id', String(params.agency_id))
    if (params.subject_ulid !== undefined && params.subject_ulid !== '')
      query.set('subject_ulid', params.subject_ulid)
    if (params.date_from !== undefined && params.date_from !== '')
      query.set('date_from', params.date_from)
    if (params.date_to !== undefined && params.date_to !== '') query.set('date_to', params.date_to)
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    if (params.cursor !== undefined && params.cursor !== '') query.set('cursor', params.cursor)
    const qs = query.toString()
    return http.get<AdminAuditLogResponse>(`/admin/audit-logs${qs === '' ? '' : `?${qs}`}`)
  },
}
