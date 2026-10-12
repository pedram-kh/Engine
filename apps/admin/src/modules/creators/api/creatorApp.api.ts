/**
 * Admin creator-app usage API (AH-102b, Half B).
 *
 *   GET /api/v1/admin/creator-app/users                      — one row per creator
 *   GET /api/v1/admin/creator-app/users/{creator}/sign-ins   — that creator's sign-ins
 *
 * Derived, read-only views. The list's "Signed out" state is read from the
 * audit trail (an ended sign-in has no row), so the sign-ins drawer can only
 * ever hold `live` and `expired` rows. Auth is implicit via the admin SPA's
 * `auth:web_admin` session cookie.
 */

import { http } from '@/core/api'

export type CreatorAppStatus = 'active' | 'signed_out' | 'never'
export type CreatorAppPlatform = 'ios' | 'android'
export type CreatorAppPushState = 'registered' | 'dead' | 'none'
/** `dead` and `none` are separate: "all devices invalidated" vs "no push registration at all". */
export type CreatorAppPushFilter = CreatorAppPushState

export interface AdminCreatorAppUser {
  id: string
  type: 'creator_app_users'
  attributes: {
    creator: { id: string; display_name: string | null; email: string | null }
    app_status: CreatorAppStatus
    live_sign_ins: number
    platforms: CreatorAppPlatform[]
    last_seen_at: string | null
    last_app_sign_in_at: string | null
    app_version: string | null
    push_state: CreatorAppPushState
    push_invalidation_reason: string | null
    last_push_registered_at: string | null
  }
}

export interface AdminCreatorAppUsersResponse {
  data: AdminCreatorAppUser[]
  meta: { total: number; page: number; per_page: number; last_page: number }
}

export interface AdminCreatorAppSignIn {
  platform: CreatorAppPlatform | null
  signed_in_at: string
  last_seen_at: string | null
  state: 'live' | 'expired'
  expires_at: string | null
  app_version: string | null
  push_state: CreatorAppPushState
  push_registered_at: string | null
}

export interface AdminCreatorAppSignInsResponse {
  data: AdminCreatorAppSignIn[]
  meta: { total: number; limit: number }
}

export interface AdminCreatorAppUsersParams {
  app_status?: CreatorAppStatus
  platform?: CreatorAppPlatform
  push?: CreatorAppPushFilter
  search?: string
  page?: number
  per_page?: number
}

export const adminCreatorAppApi = {
  users(params: AdminCreatorAppUsersParams = {}): Promise<AdminCreatorAppUsersResponse> {
    const query = new URLSearchParams()
    if (params.app_status !== undefined) query.set('app_status', params.app_status)
    if (params.platform !== undefined) query.set('platform', params.platform)
    if (params.push !== undefined) query.set('push', params.push)
    if (params.search !== undefined && params.search.trim() !== '')
      query.set('search', params.search.trim())
    if (params.page !== undefined) query.set('page', String(params.page))
    if (params.per_page !== undefined) query.set('per_page', String(params.per_page))
    const qs = query.toString()
    return http.get<AdminCreatorAppUsersResponse>(
      `/admin/creator-app/users${qs === '' ? '' : `?${qs}`}`,
    )
  },

  signIns(creatorUlid: string): Promise<AdminCreatorAppSignInsResponse> {
    return http.get<AdminCreatorAppSignInsResponse>(
      `/admin/creator-app/users/${encodeURIComponent(creatorUlid)}/sign-ins`,
    )
  },
}
