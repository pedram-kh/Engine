/**
 * Module-local client for the creator-side magic-link invitation flow
 * (Sprint 3 Chunk 4 sub-step 4).
 *
 * The backend's preview endpoint is intentionally unauthenticated and
 * does NOT expose the invited email (`docs/05-SECURITY-COMPLIANCE.md`
 * standard #42 — no enumerable identifiers on unauthenticated surfaces).
 * The 5-state UI on /auth/accept-invite anchors purely on
 * `{agency_name, is_expired, is_accepted}` + the HTTP status code.
 *
 * The "hard-lock" from Decision C2=a degrades to a post-submit gate on
 * the sign-up endpoint — that branch is owned by `useAuthStore.signUp()`
 * (it already forwards `invitation_token` when present).
 *
 * Per `docs/02-CONVENTIONS.md § 3.1` the file is module-scoped: this is
 * the only place in the main SPA that talks to the creator-invitations
 * endpoint.
 */

import { http } from '@/core/api'
import { ApiError } from '@catalyst/api-client'

export type CreatorInvitationPreviewState =
  | { kind: 'valid-pending'; agencyName: string }
  | { kind: 'already-accepted'; agencyName: string }
  | { kind: 'expired'; agencyName: string }
  | { kind: 'invalid' }

interface PreviewBody {
  data: {
    agency_name: string
    is_expired: boolean
    is_accepted: boolean
  }
}

/**
 * Fetch the invitation preview for the given token. Maps the backend's
 * `{agency_name, is_expired, is_accepted}` projection + 404 outcome to
 * the 4 terminal states the 5-state UI renders (`loading` is a local
 * pre-await state managed by the consuming page).
 */
export async function previewCreatorInvitation(
  token: string,
): Promise<CreatorInvitationPreviewState> {
  try {
    // `HttpRequestOptions` does not expose a `params` field (it's an
    // intentionally minimal contract — see `packages/api-client/src/http.ts`).
    // We build the query string inline so the URL is self-contained.
    const qs = `?token=${encodeURIComponent(token)}`
    const response = await http.get<PreviewBody>(`/creators/invitations/preview${qs}`)
    const { agency_name, is_expired, is_accepted } = response.data

    if (is_accepted) {
      return { kind: 'already-accepted', agencyName: agency_name }
    }
    if (is_expired) {
      return { kind: 'expired', agencyName: agency_name }
    }
    return { kind: 'valid-pending', agencyName: agency_name }
  } catch (err) {
    // Any backend-shaped failure (404 generic invitation.not_found,
    // network outage, parse error) collapses to the `invalid` state.
    // Standing standard #42 — we don't differentiate between
    // "token unknown" and "token malformed" on this unauthenticated
    // surface.
    if (err instanceof ApiError) {
      return { kind: 'invalid' }
    }
    return { kind: 'invalid' }
  }
}
