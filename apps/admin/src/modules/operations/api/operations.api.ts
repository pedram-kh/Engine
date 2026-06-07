/**
 * Admin operations API (Sprint 13, D-8).
 *
 *   GET /api/v1/admin/health — cheap DB + cache liveness probe
 *
 * Queue depth / failed jobs are served by the gated Horizon embed (a nav
 * link out to `/horizon`), not this API. Auth is implicit via the admin
 * SPA's `auth:web_admin` session.
 */

import { http } from '@/core/api'

export type HealthCheckStatus = 'ok' | 'error'

export interface AdminHealth {
  status: 'ok' | 'degraded'
  checks: Record<string, HealthCheckStatus>
}

export interface AdminHealthResponse {
  data: AdminHealth
}

export const adminOperationsApi = {
  health(): Promise<AdminHealthResponse> {
    return http.get<AdminHealthResponse>('/admin/health')
  },
}
