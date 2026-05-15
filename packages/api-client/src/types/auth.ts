/**
 * Wire DTOs for the authentication endpoints exposed under `/api/v1/auth`.
 *
 * Every interface here mirrors the request/response contracts defined
 * by the corresponding backend `FormRequest` / `Controller` pair in
 * `apps/api/app/Modules/Identity/Http/`. The api-client functions in
 * `auth.ts` are thin: they convert a typed input into the matching
 * payload, hand it to the {@link HttpClient}, and unwrap the typed
 * response.
 *
 * Conventions:
 *   - Wire keys stay `snake_case`. We do not re-case payloads on either
 *     direction; drift between the SPA and the API is loud at the
 *     compiler level.
 *   - Optional fields use `?:` — the backend treats absence and `null`
 *     identically for these inputs.
 *   - 2FA-related responses surface plaintext recovery codes in the
 *     return value but NEVER on a state field. The {@link useAuthStore}
 *     in chunk 6.4 enforces this with a source-inspection regression
 *     test (PROJECT-WORKFLOW.md § 5.1).
 */

import type { PreferredLanguage } from './user'

/**
 * `POST /api/v1/auth/login` request body.
 *
 * `mfa_code` is omitted on the first request. The backend signals
 * `auth.mfa_required` and the SPA re-submits with the code set. The
 * width range covers both 6-digit TOTP and the 19-char
 * `xxxx-xxxx-xxxx-xxxx` recovery format (the validator caps at 32
 * chars; we do NOT re-validate width here — the api-client passes the
 * value through).
 */
export interface LoginRequest {
  email: string
  password: string
  mfa_code?: string
}

/**
 * `POST /api/v1/auth/sign-up` request body.
 *
 * `password_confirmation` is required by Laravel's `confirmed` rule
 * even though it is the same value the user typed twice — we forward
 * it verbatim instead of inventing a frontend-only "match the password
 * client-side" shortcut.
 */
export interface SignUpRequest {
  name: string
  email: string
  password: string
  password_confirmation: string
  preferred_language?: PreferredLanguage
  /**
   * Magic-link invitation token (Sprint 3 Chunk 4). When present, the
   * sign-up endpoint accepts the invitation: the bulk-invite User row
   * is updated in place rather than a new row created, the relation
   * flips to roster, and email_verified_at is stamped to now() (the
   * invitee clicked a link mailed to them — the verification gate is
   * implicit). Absent for direct-signup users.
   */
  invitation_token?: string
}

/**
 * `POST /api/v1/auth/forgot-password` request body.
 */
export interface ForgotPasswordRequest {
  email: string
}

/**
 * `POST /api/v1/auth/reset-password` request body. The `token` arrives
 * via the password-reset email link's query string.
 */
export interface ResetPasswordRequest {
  email: string
  token: string
  password: string
  password_confirmation: string
}

/**
 * `POST /api/v1/auth/verify-email` request body. The token's contents
 * are opaque to the SPA — its structure is validated cryptographically
 * inside `EmailVerificationToken` on the backend.
 */
export interface VerifyEmailRequest {
  token: string
}

/**
 * `POST /api/v1/auth/resend-verification` request body.
 */
export interface ResendVerificationRequest {
  email: string
}

/**
 * `POST /api/v1/auth/2fa/confirm` request body. Step 2 of enrollment.
 */
export interface ConfirmTotpRequest {
  provisional_token: string
  code: string
}

/**
 * `POST /api/v1/auth/2fa/disable` request body. Disable requires BOTH
 * the user's current password AND a working 2FA code (TOTP or recovery
 * code) per chunk 5 priority #10.
 */
export interface DisableTotpRequest {
  password: string
  mfa_code: string
}

/**
 * `POST /api/v1/auth/2fa/recovery-codes` request body. Regenerating
 * recovery codes requires a working 2FA code so a stolen-session
 * attacker who briefly has authenticated access cannot rotate the
 * recovery codes out from under the legitimate user.
 */
export interface RegenerateRecoveryCodesRequest {
  mfa_code: string
}

/**
 * Step 1 of the two-step 2FA enrollment flow — the response of
 * `POST /api/v1/auth/2fa/enable`.
 *
 * `provisional_token` is the opaque cache key the backend tracks the
 * unconfirmed enrollment under. The SPA passes it back to
 * `/2fa/confirm` along with the user's first TOTP code.
 *
 * `qr_code_svg` is an inline SVG document the SPA renders directly.
 * `manual_entry_key` is the same secret in human-typeable form for
 * users whose authenticator does not support QR scanning.
 */
export interface EnableTotpResponse {
  provisional_token: string
  otpauth_url: string
  qr_code_svg: string
  manual_entry_key: string
  expires_in_seconds: number
}

/**
 * Wire envelope for {@link EnableTotpResponse}. The api-client unwraps
 * the `data` field and returns {@link EnableTotpResponse} directly.
 */
export interface EnableTotpEnvelope {
  data: EnableTotpResponse
}

/**
 * Plaintext recovery codes returned by `POST /api/v1/auth/2fa/confirm`
 * (on successful enrollment) and by
 * `POST /api/v1/auth/2fa/recovery-codes` (on regeneration). These are
 * the user's only chance to save the codes; the audit row that fires
 * for `mfa.confirmed` does NOT contain them (chunk 5 priority #6).
 *
 * The api-client returns this object directly from the action; callers
 * must NOT route the codes into a Pinia store state field — they belong
 * in component-local state for one-time display only (chunk 6 plan
 * rule, PROJECT-WORKFLOW.md § 5.1).
 */
export interface RecoveryCodesResponse {
  recovery_codes: readonly string[]
}

/**
 * Wire envelope for {@link RecoveryCodesResponse}.
 */
export interface RecoveryCodesEnvelope {
  data: RecoveryCodesResponse
}
