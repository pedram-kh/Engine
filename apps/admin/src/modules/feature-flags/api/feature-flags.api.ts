/**
 * Admin feature-flag toggle API (Sprint 13, D-6).
 *
 *   GET  /api/v1/admin/feature-flags        — list every flag + state
 *   POST /api/v1/admin/feature-flags/{flag} — flip it (reason required)
 *
 * The toggle is the RUNTIME mutation path over DB-backed Pennant flags;
 * every flip carries a mandatory reason (the feature_flag.toggled audit
 * verb). Auth is implicit via the admin SPA's `auth:web_admin` session.
 */

import { http } from '@/core/api'

export interface AdminFeatureFlag {
  id: string
  type: 'feature_flags'
  attributes: {
    name: string
    label: string
    description: string
    enabled: boolean
  }
}

export interface AdminFeatureFlagListResponse {
  data: AdminFeatureFlag[]
}

export interface AdminFeatureFlagEnvelope {
  data: AdminFeatureFlag
}

export const adminFeatureFlagsApi = {
  list(): Promise<AdminFeatureFlagListResponse> {
    return http.get<AdminFeatureFlagListResponse>('/admin/feature-flags')
  },

  toggle(flag: string, enabled: boolean, reason: string): Promise<AdminFeatureFlagEnvelope> {
    return http.post<AdminFeatureFlagEnvelope>(`/admin/feature-flags/${flag}`, { enabled, reason })
  },
}
