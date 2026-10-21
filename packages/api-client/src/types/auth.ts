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

import type { PreferredLanguage, TimezoneIdentifier, UserResource } from './user'

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
  /**
   * AH-104b-del: re-send the SAME credentials with this set after the server
   * answered `auth.deletion_pending`, to restore an account that is scheduled
   * for deletion. The server ignores it on any other account.
   */
  cancel_deletion?: boolean
}

/**
 * The `meta` of an `auth.deletion_pending` (403) answer: right credentials on
 * an account scheduled for deletion. Only ever returned AFTER the password and
 * the second factor were verified.
 */
export interface DeletionPendingMeta {
  /** ISO-8601 instant the account becomes permanently deleted. */
  scheduledFor: string
  /**
   * How the second factor was satisfied on this request: `recovery_code` means
   * that code is now spent and the restore step needs a different one.
   */
  mfaVia: 'none' | 'totp' | 'recovery_code'
}

/**
 * `POST /api/v1/me/account-deletion` request body (AH-104b-del A3). Creators
 * only. `mfa_code` is required when the account has two-factor sign-in.
 */
export interface AccountDeletionRequest {
  password: string
  mfa_code?: string
  /** The tick on the consequences list. The typed word is a client-side gate and is not sent. */
  acknowledged: true
}

/** The unwrapped 200 body of `POST /api/v1/me/account-deletion`. */
export interface AccountDeletionResult {
  /** ISO-8601. */
  requestedAt: string
  /** ISO-8601; fixed at request time, never recomputed. */
  scheduledFor: string
}

/** The 200 envelope of `POST /api/v1/me/account-deletion`. */
export interface AccountDeletionEnvelope {
  data: {
    type: 'account_deletion'
    attributes: { requested_at: string; scheduled_for: string }
  }
}

/** The native platform a creator-app token is minted for (`creator-app:{platform}`). */
export type TokenPlatform = 'ios' | 'android'

/**
 * `POST /api/v1/auth/token` request body — the creator app's login
 * (AH-099 `TokenLoginRequest`). The credential fields and the 2FA
 * re-submit contract are identical to {@link LoginRequest}.
 */
export interface TokenLoginRequest extends LoginRequest {
  platform: TokenPlatform
}

/**
 * `POST /api/v1/auth/token` 2xx body (AH-099 `TokenLoginController`): the
 * user resource plus the bearer token, shown exactly once.
 */
export interface TokenLoginEnvelope {
  data: UserResource
  meta: {
    token: string
    token_type: 'Bearer'
    /** ISO-8601; `null` only when the server runs with no expiry configured. */
    expires_at: string | null
  }
}

/** The unwrapped {@link TokenLoginEnvelope} `AuthApi.tokenLogin` resolves to. */
export interface TokenLoginResult {
  user: UserResource
  token: string
  expiresAt: string | null
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
  /** First (given) name — the backend field is still called `name`. */
  name: string
  /** Surname. Required for new sign-ups (pre-split accounts have null). */
  last_name: string
  email: string
  password: string
  password_confirmation: string
  preferred_language?: PreferredLanguage
  /**
   * Browser-detected IANA timezone (Sprint 5 Chunk C). Auto-captured at
   * sign-up via `Intl.DateTimeFormat().resolvedOptions().timeZone` — there
   * is no user-facing input. Rides BOTH the direct-signup and the
   * invite-acceptance paths (same `SignUpPage`). The backend treats the
   * client as untrusted: it re-validates against the canonical IANA list
   * and silently falls back to `'UTC'` on an invalid/absent value (never
   * rejects the registration). Absent only if the browser exposes no zone.
   */
  timezone?: TimezoneIdentifier
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
