/**
 * Admin operational-alerts API (Sprint 13, D-12) — the non-payment admin
 * notification consumer.
 *
 *   GET /admin/alerts — the admin's own operational alerts feed, reusing
 *        the S11.0 notification subsystem (a platform_admin is a User).
 *
 * Payment-event alerts are held back (coming-soon, D-13): the backend
 * filters them out of `data` and reports them under `meta.payment_alerts`
 * so the page can render a discrete coming-soon block the swap-in S10
 * lights up.
 *
 * Auth is implicit via the admin SPA's `auth:web_admin` session cookie;
 * the backend gates the route with the platform_admin bounded bypass +
 * EnsureMfaForAdmins.
 */

import { http } from '@/core/api'

export interface AdminAlert {
  id: string
  type: 'notifications'
  attributes: {
    notification_type: string
    data: Record<string, unknown>
    read_at: string | null
    created_at: string
    actor: { id: string; name: string } | null
    subject: { type: string; id: string } | null
  }
}

export interface AdminAlertsResponse {
  data: AdminAlert[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
    payment_alerts: {
      coming_soon: boolean
      types: string[]
    }
  }
}

export const adminAlertsApi = {
  list(): Promise<AdminAlertsResponse> {
    return http.get<AdminAlertsResponse>('/admin/alerts')
  },
}
