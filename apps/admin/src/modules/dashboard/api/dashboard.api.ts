/**
 * Admin dashboard API (Sprint 13, D-7).
 *
 *   GET /api/v1/admin/dashboard/summary  — non-payment operational KPIs
 *   GET /api/v1/admin/dashboard/activity — the recent audit activity feed
 *
 * The summary's payment/dispute fields are stable `null` placeholders
 * (D-13) the SPA renders as a muted dash until payment processing ships.
 * Auth is implicit via the admin SPA's `auth:web_admin` session.
 */

import { http } from '@/core/api'

export interface AdminDashboardSummary {
  agencies_total: number
  agencies_active: number
  agencies_suspended: number
  creators_pending_approval: number
  creators_pending_kyc: number
  queue_pending: number
  queue_failed: number
  open_disputes: number | null
  failed_payments_today: number | null
}

export interface AdminDashboardSummaryResponse {
  data: AdminDashboardSummary
}

export interface AdminDashboardActivityRow {
  id: string
  type: 'audit_logs'
  attributes: {
    action: string
    actor_name: string | null
    actor_email: string | null
    reason: string | null
    created_at: string
  }
}

export interface AdminDashboardActivityResponse {
  data: AdminDashboardActivityRow[]
}

export const adminDashboardApi = {
  summary(): Promise<AdminDashboardSummaryResponse> {
    return http.get<AdminDashboardSummaryResponse>('/admin/dashboard/summary')
  },

  activity(): Promise<AdminDashboardActivityResponse> {
    return http.get<AdminDashboardActivityResponse>('/admin/dashboard/activity')
  },
}
