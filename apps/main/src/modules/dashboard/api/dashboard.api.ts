/**
 * Typed wrapper for the agency dashboard (workspace-home) endpoints.
 *
 * Tenant-scoped to the current agency via the `agencyId` (the agency's
 * ULID) path segment, mirroring the Brands module's thin-`*.api.ts`
 * convention. The HTTP client handles CSRF preflight + Sanctum cookie auth.
 *
 * Endpoint prefix: /api/v1/agencies/{agency}/dashboard
 */

import { http } from '@/core/api'

/**
 * The single summary payload (D-c1-6). Two real KPIs; two stable `null`
 * placeholders (campaigns / payments) that slot in when those surfaces
 * ship — the SPA renders a muted `—` for `null`.
 */
export interface DashboardSummary {
  creators_in_roster: number
  pending_creator_applications: number
  active_campaigns: number | null
  payments_due: number | null
}

/**
 * One activity-feed row (1c). Curated, agency-scoped audit events; each row
 * carries only render-needed fields (the backend whitelists per-action
 * metadata, never the raw blob).
 */
export interface DashboardActivityItem {
  id: string
  action: string
  actor_label: string | null
  created_at: string
  metadata: Record<string, string | number | null>
}

export interface DashboardSummaryEnvelope {
  data: DashboardSummary
}

export interface DashboardActivityEnvelope {
  data: DashboardActivityItem[]
}

function dashboardBase(agencyId: string): string {
  return `/agencies/${agencyId}/dashboard`
}

export const dashboardApi = {
  summary(agencyId: string): Promise<DashboardSummaryEnvelope> {
    return http.get<DashboardSummaryEnvelope>(`${dashboardBase(agencyId)}/summary`)
  },

  activity(agencyId: string): Promise<DashboardActivityEnvelope> {
    return http.get<DashboardActivityEnvelope>(`${dashboardBase(agencyId)}/activity`)
  },
}
