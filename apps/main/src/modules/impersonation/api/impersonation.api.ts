/**
 * Main-SPA impersonation hand-off API (Sprint 13, D-9 / D-10).
 *
 *   POST /api/v1/auth/impersonation/claim   — consume the one-time token
 *   GET  /api/v1/auth/impersonation/status  — banner hydration (cold load)
 *   POST /api/v1/auth/impersonation/end      — end from the impersonated tab
 *
 * `claim` is UNAUTHENTICATED by design: the one-time, short-lived,
 * single-use token minted by the admin SPA IS the bearer credential (the
 * magic-link pattern). On success the backend logs the impersonated user
 * into the `web` guard and the response carries the user + the
 * server-authoritative `expires_at`. The TTL is enforced server-side on
 * every subsequent request by the EnforceImpersonation middleware — the
 * `expires_at` here only drives the advisory countdown in the banner.
 */

import { http } from '@/core/api'

export interface ImpersonationClaimResult {
  data: {
    id: string
    type: 'users'
    attributes: {
      name: string
      email: string
      user_type: string
      impersonated: true
      expires_at: string
    }
  }
}

export interface ImpersonationStatusResult {
  data: {
    active: boolean
    expires_at?: string
  }
}

export const impersonationApi = {
  claim(token: string): Promise<ImpersonationClaimResult> {
    return http.post<ImpersonationClaimResult>('/auth/impersonation/claim', { token })
  },

  status(): Promise<ImpersonationStatusResult> {
    return http.get<ImpersonationStatusResult>('/auth/impersonation/status')
  },

  end(): Promise<{ data: { ended: boolean } }> {
    return http.post<{ data: { ended: boolean } }>('/auth/impersonation/end', {})
  },
}

/**
 * Pull the one-time token out of the hand-off URL fragment
 * (`#token=...`). The token rides the fragment — never the query string —
 * so it never reaches the server access log or a `Referer` header. Returns
 * `null` when no token is present.
 */
export function readHandoffToken(hash: string): string | null {
  const cleaned = hash.startsWith('#') ? hash.slice(1) : hash
  const params = new URLSearchParams(cleaned)
  const token = params.get('token')
  return token !== null && token.trim() !== '' ? token : null
}
