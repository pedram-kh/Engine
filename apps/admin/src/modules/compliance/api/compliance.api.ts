/**
 * Admin GDPR-compliance queues API (Sprint 13, D-11) — SHELLS.
 *
 *   GET /admin/compliance/export-requests — data-subject export queue
 *   GET /admin/compliance/erasure-queue    — data-subject erasure queue
 *
 * Both return an empty `data: []` with `meta.shell: true` this sprint —
 * the backing tables (data_export_requests / data_erasure_requests) and
 * the export/erasure machinery land in Sprint 14. The page renders the
 * shell empty-state from `meta.shell`.
 *
 * Auth is implicit via the admin SPA's `auth:web_admin` session cookie;
 * the backend gates the route with the platform_admin bounded bypass +
 * EnsureMfaForAdmins.
 */

import { http } from '@/core/api'

export interface ComplianceRequest {
  id: string
  type: 'data_export_requests' | 'data_erasure_requests'
  attributes: {
    subject_email: string | null
    status: string
    requested_at: string
  }
}

export interface ComplianceQueueResponse {
  data: ComplianceRequest[]
  meta: {
    total: number
    shell: boolean
  }
}

export const adminComplianceApi = {
  listExports(): Promise<ComplianceQueueResponse> {
    return http.get<ComplianceQueueResponse>('/admin/compliance/export-requests')
  },
  listErasures(): Promise<ComplianceQueueResponse> {
    return http.get<ComplianceQueueResponse>('/admin/compliance/erasure-queue')
  },
}
