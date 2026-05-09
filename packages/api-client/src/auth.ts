/**
 * Typed wrapper functions for every auth endpoint shipped through
 * Sprint-1 chunks 3 → 5. The wire shapes are nailed down in
 * {@link ./types/auth} and {@link ./types/user}; this module is
 * intentionally thin — its only job is to keep the SPA out of the
 * business of building HTTP requests.
 *
 * Every function:
 *   - Routes through the {@link HttpClient} (the only place axios lives,
 *     per chunk-6.2 standard 5.1 / `docs/02-CONVENTIONS.md § 3.6`).
 *   - Returns the unwrapped resource (or `void`) on the 2xx path.
 *   - Throws an {@link ApiError} on any non-2xx response or transport
 *     failure. Backend error codes pass through verbatim.
 *
 * There is no admin-vs-main split at this layer. The admin SPA gets its
 * own bound `AuthApi` in chunk 7 by passing an http client whose
 * `baseUrl` resolves the `/admin/*` variants. The endpoint paths the
 * functions below hit live on the main SPA's auth surface; the admin
 * surface uses `/admin/me` and `/admin/auth/login` instead, but those
 * shapes are identical so the same types apply.
 */

import type { HttpClient } from './http'
import type {
  ConfirmTotpRequest,
  DisableTotpRequest,
  EnableTotpEnvelope,
  EnableTotpResponse,
  ForgotPasswordRequest,
  LoginRequest,
  RecoveryCodesEnvelope,
  RecoveryCodesResponse,
  RegenerateRecoveryCodesRequest,
  ResendVerificationRequest,
  ResetPasswordRequest,
  SignUpRequest,
  VerifyEmailRequest,
} from './types/auth'
import type { User, UserEnvelope } from './types/user'

/**
 * Variant the api-client targets. The wire payloads are identical; only
 * the `/me` and `/login` paths differ (`/me` vs `/admin/me`,
 * `/auth/login` vs `/admin/auth/login`). The main-SPA store passes
 * `'main'`; the admin-SPA store (chunk 7) will pass `'admin'`.
 */
export type AuthVariant = 'main' | 'admin'

export interface CreateAuthApiOptions {
  /**
   * Defaults to `'main'`. The admin SPA in chunk 7 will pass `'admin'`
   * to redirect `/me` and `/auth/login` to their admin twins.
   */
  variant?: AuthVariant
}

/**
 * The typed surface every consumer (the Pinia store, tests, and the
 * future admin SPA store) imports.
 */
export interface AuthApi {
  /**
   * `GET /api/v1/me` (or `/api/v1/admin/me` for the admin variant).
   *
   * On the admin variant a 403 with `auth.mfa.enrollment_required` is
   * possible — the {@link ApiError} surfaces with that exact code so
   * the chunk-7 router guard can branch on it.
   */
  me(): Promise<User>

  /**
   * `POST /api/v1/auth/login` (or `/admin/auth/login` for the admin
   * variant). Returns the user resource on the 2xx path; the cookie
   * is set as a side effect of the response.
   */
  login(body: LoginRequest): Promise<User>

  /**
   * `POST /api/v1/auth/logout`. The 2xx path destroys the session
   * cookie; the SPA must clear its in-memory user separately.
   */
  logout(): Promise<void>

  /**
   * `POST /api/v1/auth/sign-up`. Returns the newly-created user; the
   * sign-up flow is intentionally non-authenticating (no cookie set).
   * The user must verify their email and sign in separately.
   */
  signUp(body: SignUpRequest): Promise<User>

  /**
   * `POST /api/v1/auth/verify-email`. Returns 204 on success, throws
   * on a malformed / expired token.
   */
  verifyEmail(body: VerifyEmailRequest): Promise<void>

  /**
   * `POST /api/v1/auth/resend-verification`. Always returns 204
   * regardless of whether the email exists (user-enumeration defence
   * per `docs/05-SECURITY-COMPLIANCE.md § 6.6`).
   */
  resendVerification(body: ResendVerificationRequest): Promise<void>

  /**
   * `POST /api/v1/auth/forgot-password`. Always returns 204
   * (user-enumeration defence).
   */
  forgotPassword(body: ForgotPasswordRequest): Promise<void>

  /**
   * `POST /api/v1/auth/reset-password`. Returns 204 on success, throws
   * on `auth.password.invalid_token` for an unknown / expired token.
   */
  resetPassword(body: ResetPasswordRequest): Promise<void>

  /**
   * `POST /api/v1/auth/2fa/enable`. Step 1 of the two-step enrollment
   * flow. Returns the provisional token + QR code + manual entry key.
   */
  enrollTotp(): Promise<EnableTotpResponse>

  /**
   * `POST /api/v1/auth/2fa/confirm`. Step 2 of enrollment. Returns the
   * plaintext recovery codes — these MUST stay outside Pinia state
   * (PROJECT-WORKFLOW.md § 5.1, enforced by the source-inspection
   * regression test in chunk 6.4).
   */
  verifyTotp(body: ConfirmTotpRequest): Promise<RecoveryCodesResponse>

  /**
   * `POST /api/v1/auth/2fa/disable`. Returns 204 on success. Failure
   * modes ride the {@link ApiError} channel.
   */
  disableTotp(body: DisableTotpRequest): Promise<void>

  /**
   * `POST /api/v1/auth/2fa/recovery-codes`. Returns the freshly-minted
   * plaintext recovery codes — same lifetime contract as
   * {@link AuthApi.verifyTotp}.
   */
  regenerateRecoveryCodes(body: RegenerateRecoveryCodesRequest): Promise<RecoveryCodesResponse>
}

/**
 * Build an {@link AuthApi} bound to the given {@link HttpClient}.
 */
export function createAuthApi(http: HttpClient, options: CreateAuthApiOptions = {}): AuthApi {
  const variant: AuthVariant = options.variant ?? 'main'
  const mePath = variant === 'admin' ? '/admin/me' : '/me'
  const loginPath = variant === 'admin' ? '/admin/auth/login' : '/auth/login'
  const logoutPath = variant === 'admin' ? '/admin/auth/logout' : '/auth/logout'
  // Sign-up + email-verification + password-reset live only on the main
  // SPA's surface — the admin SPA's onboarding goes through a separate
  // (eventual) admin-invite flow rather than self-service. We keep the
  // `/auth/*` paths fixed; an admin-variant client calling them will
  // hit the main-SPA endpoints, which is the documented behaviour.
  const signUpPath = '/auth/sign-up'
  const verifyEmailPath = '/auth/verify-email'
  const resendVerificationPath = '/auth/resend-verification'
  const forgotPasswordPath = '/auth/forgot-password'
  const resetPasswordPath = '/auth/reset-password'
  const totpEnablePath = variant === 'admin' ? '/admin/auth/2fa/enable' : '/auth/2fa/enable'
  const totpConfirmPath = variant === 'admin' ? '/admin/auth/2fa/confirm' : '/auth/2fa/confirm'
  const totpDisablePath = variant === 'admin' ? '/admin/auth/2fa/disable' : '/auth/2fa/disable'
  const recoveryCodesPath =
    variant === 'admin' ? '/admin/auth/2fa/recovery-codes' : '/auth/2fa/recovery-codes'

  return {
    async me() {
      const envelope = await http.get<UserEnvelope>(mePath)
      return envelope.data
    },

    async login(body) {
      const envelope = await http.post<UserEnvelope>(loginPath, body)
      return envelope.data
    },

    async logout() {
      await http.post<void>(logoutPath)
    },

    async signUp(body) {
      const envelope = await http.post<UserEnvelope>(signUpPath, body)
      return envelope.data
    },

    async verifyEmail(body) {
      await http.post<void>(verifyEmailPath, body)
    },

    async resendVerification(body) {
      await http.post<void>(resendVerificationPath, body)
    },

    async forgotPassword(body) {
      await http.post<void>(forgotPasswordPath, body)
    },

    async resetPassword(body) {
      await http.post<void>(resetPasswordPath, body)
    },

    async enrollTotp() {
      const envelope = await http.post<EnableTotpEnvelope>(totpEnablePath)
      return envelope.data
    },

    async verifyTotp(body) {
      const envelope = await http.post<RecoveryCodesEnvelope>(totpConfirmPath, body)
      return envelope.data
    },

    async disableTotp(body) {
      await http.post<void>(totpDisablePath, body)
    },

    async regenerateRecoveryCodes(body) {
      const envelope = await http.post<RecoveryCodesEnvelope>(recoveryCodesPath, body)
      return envelope.data
    },
  }
}
