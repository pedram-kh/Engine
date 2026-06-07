/**
 * Admin-side impersonation API surface (Sprint 13, D-9).
 *
 *   GET  /api/v1/admin/impersonate/users?q=  — target search
 *   POST /api/v1/admin/impersonate           — start (reason required)
 *   POST /api/v1/admin/impersonate/end       — end the active session
 *
 * Auth is implicit via the admin SPA's `auth:web_admin` session cookie;
 * the backend gates every route with the platform_admin check +
 * EnsureMfaForAdmins. The START returns a ONE-TIME hand-off token plus the
 * main-SPA base URL — the SPA opens the main app and claims the session
 * THERE (the two-cookie bridge). The admin's own session is never touched.
 *
 * No-escalation lives server-side: platform admins are not even searchable
 * as candidates, and start refuses an admin / self target. The TTL (Q2) is
 * the row's `expires_at` — authoritative regardless of any client clock.
 */

import { http } from '@/core/api'

export interface ImpersonationCandidate {
  id: string
  type: 'users'
  attributes: {
    name: string
    email: string
    user_type: string
  }
}

export interface ImpersonationCandidateResponse {
  data: ImpersonationCandidate[]
}

export interface ImpersonationStartResult {
  data: {
    id: string
    type: 'impersonation_sessions'
    attributes: {
      handoff_token: string
      main_spa_url: string
      impersonated_user_ulid: string
      impersonated_user_name: string
      expires_at: string
    }
  }
}

export interface ImpersonationEndResult {
  data: {
    ended: boolean
  }
}

export const impersonationApi = {
  searchUsers(q: string): Promise<ImpersonationCandidateResponse> {
    const query = new URLSearchParams()
    if (q.trim() !== '') query.set('q', q.trim())
    const qs = query.toString()
    return http.get<ImpersonationCandidateResponse>(
      `/admin/impersonate/users${qs === '' ? '' : `?${qs}`}`,
    )
  },

  start(userUlid: string, reason: string): Promise<ImpersonationStartResult> {
    return http.post<ImpersonationStartResult>('/admin/impersonate', {
      user_ulid: userUlid,
      reason,
    })
  },

  end(): Promise<ImpersonationEndResult> {
    return http.post<ImpersonationEndResult>('/admin/impersonate/end', {})
  },
}

/**
 * Build the main-SPA hand-off URL the admin opens in a new tab. The
 * one-time token rides in the fragment (`#`) — NOT the query string — so
 * it never lands in the backend access log / `Referer` header. The main
 * SPA reads it from `location.hash` and POSTs it to the claim endpoint.
 */
export function buildHandoffUrl(mainSpaUrl: string, token: string): string {
  const base = mainSpaUrl.replace(/\/+$/, '')
  return `${base}/impersonation/claim#token=${encodeURIComponent(token)}`
}
