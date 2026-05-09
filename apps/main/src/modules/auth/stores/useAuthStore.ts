/**
 * Pinia store backing the SPA's authenticated session state.
 *
 * Scope contract (chunk 6.4 plan):
 *   - `user` is the **only** mutable identity field. The Pinia state
 *     intentionally does NOT carry recovery codes — those are returned
 *     by the action and held in component-local state for one-time
 *     display (PROJECT-WORKFLOW.md § 5.1, enforced by the
 *     source-inspection regression test in
 *     `apps/main/tests/unit/architecture/no-recovery-codes-in-store.spec.ts`).
 *   - `bootstrap()` is idempotent across concurrent calls — an
 *     in-flight promise cache dedupes simultaneous invocations to a
 *     single `apiClient.me()` call.
 *   - The 401 cold-load path is NOT an error: it means "not signed
 *     in yet" and resolves to `bootstrapStatus = 'ready'` with `user
 *     = null` (chunk 6.4 review priority #6).
 *   - The 403 `auth.mfa.enrollment_required` path stores the derived
 *     `mfaEnrollmentRequired` flag (the chunk-7 router guard reads
 *     it). The user payload is left untouched on this path because
 *     the backend does NOT include one when an admin needs to enrol
 *     2FA.
 *
 * Action loading flags:
 *   - `isLoggingIn`, `isLoggingOut`, etc. are read by chunk-6.6 forms
 *     to drive their disabled/loading UI states. They are deliberately
 *     per-action rather than a single coarse boolean so independent
 *     concurrent actions (a `logout()` racing a slow `me()`) cannot
 *     stomp on each other's UI.
 */

import { ApiError } from '@catalyst/api-client'
import type {
  ConfirmTotpRequest,
  DisableTotpRequest,
  EnableTotpResponse,
  ForgotPasswordRequest,
  RecoveryCodesResponse,
  RegenerateRecoveryCodesRequest,
  ResendVerificationRequest,
  ResetPasswordRequest,
  SignUpRequest,
  User,
  UserType,
  VerifyEmailRequest,
} from '@catalyst/api-client'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { authApi } from '../api/auth.api'

export type BootstrapStatus = 'idle' | 'loading' | 'ready' | 'error'

/**
 * Error code emitted by the admin `/me` endpoint when an authenticated
 * platform-admin has not yet enrolled 2FA. The bootstrap action
 * special-cases this so the chunk-7 router guard can branch on a
 * derived flag rather than re-inspecting the underlying error.
 */
export const MFA_ENROLLMENT_REQUIRED_CODE = 'auth.mfa.enrollment_required'

export const useAuthStore = defineStore('auth', () => {
  // ---------------------------------------------------------------
  // State
  // ---------------------------------------------------------------
  const user = ref<User | null>(null)
  const bootstrapStatus = ref<BootstrapStatus>('idle')
  const mfaEnrollmentRequired = ref(false)

  const isLoggingIn = ref(false)
  const isLoggingOut = ref(false)
  const isSigningUp = ref(false)
  const isVerifyingEmail = ref(false)
  const isResendingVerification = ref(false)
  const isRequestingPasswordReset = ref(false)
  const isResettingPassword = ref(false)
  const isEnrollingTotp = ref(false)
  const isVerifyingTotp = ref(false)
  const isDisablingTotp = ref(false)
  const isRegeneratingRecoveryCodes = ref(false)

  // Dedup cache for concurrent bootstrap() calls. Cleared once the
  // in-flight promise settles, so a subsequent (e.g. post-401)
  // bootstrap reissues a fresh request.
  let inFlightBootstrap: Promise<void> | null = null

  // ---------------------------------------------------------------
  // Getters
  // ---------------------------------------------------------------
  const isAuthenticated = computed(() => user.value !== null)

  const userType = computed<UserType | null>(() => user.value?.attributes.user_type ?? null)

  const isMfaEnrolled = computed(() => user.value?.attributes.two_factor_enabled ?? false)

  // ---------------------------------------------------------------
  // Actions — identity primitives
  // ---------------------------------------------------------------

  /**
   * Replace the stored user. The login() action calls this internally;
   * tests can call it directly to seed state.
   */
  function setUser(next: User): void {
    user.value = next
    mfaEnrollmentRequired.value = false
  }

  /**
   * Drop the stored user back to `null` and reset MFA-required flag.
   * Called after logout, after an authoritative 401, and from tests.
   */
  function clearUser(): void {
    user.value = null
    mfaEnrollmentRequired.value = false
  }

  // ---------------------------------------------------------------
  // bootstrap() — cold-load identity resolution
  // ---------------------------------------------------------------

  async function bootstrap(): Promise<void> {
    if (inFlightBootstrap !== null) {
      return inFlightBootstrap
    }

    bootstrapStatus.value = 'loading'

    inFlightBootstrap = (async (): Promise<void> => {
      try {
        const me = await authApi.me()
        user.value = me
        mfaEnrollmentRequired.value = false
        bootstrapStatus.value = 'ready'
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          // Cold-load anonymous session — normal, not an error.
          user.value = null
          mfaEnrollmentRequired.value = false
          bootstrapStatus.value = 'ready'
          return
        }
        if (
          error instanceof ApiError &&
          error.status === 403 &&
          error.code === MFA_ENROLLMENT_REQUIRED_CODE
        ) {
          // Admin SPA territory (chunk 7). The backend does NOT
          // include a user payload on this path — leave `user`
          // untouched and surface the derived flag for the router
          // guard to branch on.
          mfaEnrollmentRequired.value = true
          bootstrapStatus.value = 'ready'
          return
        }
        bootstrapStatus.value = 'error'
        throw error
      } finally {
        inFlightBootstrap = null
      }
    })()

    return inFlightBootstrap
  }

  // ---------------------------------------------------------------
  // Actions — auth surface
  // ---------------------------------------------------------------

  async function login(email: string, password: string, totpCode?: string): Promise<void> {
    isLoggingIn.value = true
    try {
      const next = await authApi.login({
        email,
        password,
        ...(totpCode !== undefined ? { mfa_code: totpCode } : {}),
      })
      setUser(next)
    } finally {
      isLoggingIn.value = false
    }
  }

  async function logout(): Promise<void> {
    isLoggingOut.value = true
    try {
      try {
        await authApi.logout()
      } catch (error) {
        // 401 on logout means we already lost the session — treat
        // as success, the in-memory user must still be cleared.
        if (!(error instanceof ApiError) || error.status !== 401) {
          throw error
        }
      }
      clearUser()
    } finally {
      isLoggingOut.value = false
    }
  }

  async function signUp(payload: SignUpRequest): Promise<User> {
    isSigningUp.value = true
    try {
      // Sign-up is intentionally non-authenticating. The user must
      // verify their email and sign in separately.
      return await authApi.signUp(payload)
    } finally {
      isSigningUp.value = false
    }
  }

  async function verifyEmail(payload: VerifyEmailRequest): Promise<void> {
    isVerifyingEmail.value = true
    try {
      await authApi.verifyEmail(payload)
    } finally {
      isVerifyingEmail.value = false
    }
  }

  async function resendVerification(payload: ResendVerificationRequest): Promise<void> {
    isResendingVerification.value = true
    try {
      await authApi.resendVerification(payload)
    } finally {
      isResendingVerification.value = false
    }
  }

  async function forgotPassword(payload: ForgotPasswordRequest): Promise<void> {
    isRequestingPasswordReset.value = true
    try {
      await authApi.forgotPassword(payload)
    } finally {
      isRequestingPasswordReset.value = false
    }
  }

  async function resetPassword(payload: ResetPasswordRequest): Promise<void> {
    isResettingPassword.value = true
    try {
      await authApi.resetPassword(payload)
    } finally {
      isResettingPassword.value = false
    }
  }

  /**
   * Step 1 of TOTP enrollment. Returns the provisional token + QR code
   * + manual-entry key. Nothing is stored on the store — the caller
   * holds the response in component-local state until step 2 lands.
   */
  async function enrollTotp(): Promise<EnableTotpResponse> {
    isEnrollingTotp.value = true
    try {
      return await authApi.enrollTotp()
    } finally {
      isEnrollingTotp.value = false
    }
  }

  /**
   * Step 2 of TOTP enrollment. Returns the plaintext recovery codes
   * — these MUST stay outside Pinia state (chunk-6 plan rule). The
   * caller (chunk 6.7 component) holds them in component-local state
   * for one-time display.
   */
  async function verifyTotp(payload: ConfirmTotpRequest): Promise<RecoveryCodesResponse> {
    isVerifyingTotp.value = true
    try {
      const result = await authApi.verifyTotp(payload)
      // Optimistic: the backend just confirmed enrollment, so we know
      // `two_factor_enabled` is now true. Update the stored user
      // directly via top-level ref reassignment (spread-replace) so
      // reactive consumers see the transition. The follow-up me()
      // below picks up any other drifted fields, but its failure is
      // invisible — the canonical state for `two_factor_enabled` is
      // already in place.
      if (user.value !== null) {
        user.value = {
          ...user.value,
          attributes: { ...user.value.attributes, two_factor_enabled: true },
        }
      }
      try {
        const refreshed = await authApi.me()
        setUser(refreshed)
      } catch {
        // Silent — optimistic update is canonical for this field.
      }
      return result
    } finally {
      isVerifyingTotp.value = false
    }
  }

  async function disableTotp(payload: DisableTotpRequest): Promise<void> {
    isDisablingTotp.value = true
    try {
      await authApi.disableTotp(payload)
      // Optimistic: the backend just disabled enrollment.
      if (user.value !== null) {
        user.value = {
          ...user.value,
          attributes: { ...user.value.attributes, two_factor_enabled: false },
        }
      }
      try {
        const refreshed = await authApi.me()
        setUser(refreshed)
      } catch {
        // Same rationale as verifyTotp.
      }
    } finally {
      isDisablingTotp.value = false
    }
  }

  /**
   * Returns the freshly minted plaintext recovery codes for one-time
   * display. Never stored on the store.
   */
  async function regenerateRecoveryCodes(
    payload: RegenerateRecoveryCodesRequest,
  ): Promise<RecoveryCodesResponse> {
    isRegeneratingRecoveryCodes.value = true
    try {
      return await authApi.regenerateRecoveryCodes(payload)
    } finally {
      isRegeneratingRecoveryCodes.value = false
    }
  }

  return {
    // state
    user,
    bootstrapStatus,
    mfaEnrollmentRequired,
    isLoggingIn,
    isLoggingOut,
    isSigningUp,
    isVerifyingEmail,
    isResendingVerification,
    isRequestingPasswordReset,
    isResettingPassword,
    isEnrollingTotp,
    isVerifyingTotp,
    isDisablingTotp,
    isRegeneratingRecoveryCodes,
    // getters
    isAuthenticated,
    userType,
    isMfaEnrolled,
    // actions
    setUser,
    clearUser,
    bootstrap,
    login,
    logout,
    signUp,
    verifyEmail,
    resendVerification,
    forgotPassword,
    resetPassword,
    enrollTotp,
    verifyTotp,
    disableTotp,
    regenerateRecoveryCodes,
  }
})
