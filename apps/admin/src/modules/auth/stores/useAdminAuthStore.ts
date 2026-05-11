/**
 * Pinia store backing the admin SPA's authenticated session state.
 *
 * Mirror of `apps/main/src/modules/auth/stores/useAuthStore.ts`
 * (chunks 6.2-6.4), narrowed to the admin SPA's action surface:
 *
 *   - Identity primitives: setUser, clearUser.
 *   - Cold-load: bootstrap() — including the 403
 *     `auth.mfa.enrollment_required` branch that the sub-chunk 7.4
 *     router guard will read off `mfaEnrollmentRequired`.
 *   - Credential exchange: login, logout.
 *   - 2FA management: enrollTotp, verifyTotp, disableTotp,
 *     regenerateRecoveryCodes — all required by the sub-chunk 7.4
 *     mandatory-enrollment flow.
 *
 * Out of admin scope (per Group 1 kickoff + `docs/20-PHASE-1-SPEC.md §
 * 5`) and therefore NOT mirrored:
 *
 *   - signUp / verifyEmail / resendVerification — admin onboarding goes
 *     through an admin-invite flow (out of Sprint 1).
 *   - forgotPassword / resetPassword — admin self-service password
 *     reset is out of Sprint 1; admins recover access through an
 *     out-of-band channel.
 *
 * Scope contract (mirrors main's, chunk 6.4 plan):
 *   - `user` is the **only** mutable identity field. Recovery codes
 *     from `verifyTotp()` and `regenerateRecoveryCodes()` are returned
 *     by the action and held in component-local state for one-time
 *     display (PROJECT-WORKFLOW.md § 5.1, enforced by the
 *     source-inspection regression test in
 *     `apps/admin/tests/unit/architecture/no-recovery-codes-in-store.spec.ts`).
 *   - `bootstrap()` is idempotent across concurrent calls — an
 *     in-flight promise cache dedupes simultaneous invocations to a
 *     single `apiClient.me()` call.
 *   - The 401 cold-load path is NOT an error: it means "not signed in
 *     yet" and resolves to `bootstrapStatus = 'ready'` with `user =
 *     null`.
 *   - The 403 `auth.mfa.enrollment_required` path stores the derived
 *     `mfaEnrollmentRequired` flag (the chunk-7.4 router guard reads
 *     it). The user payload is left untouched on this path because the
 *     backend does NOT include one when an admin needs to enrol 2FA.
 *
 * Persistence:
 *   - NO `sessionStorage` / `localStorage` persistence. Rehydration
 *     happens via `bootstrap()` against the still-valid
 *     `catalyst_admin_session` cookie set by the backend (Sanctum SPA
 *     pattern). Mirrors main's chunk-6.4 strategy verbatim. The
 *     `recoveryCodes` invariant (transient component-local state,
 *     never persisted) is automatically satisfied because Pinia state
 *     itself is not persisted.
 *
 * Action loading flags:
 *   - `isLoggingIn`, `isLoggingOut`, etc. are per-action booleans so
 *     independent concurrent actions (a `logout()` racing a slow
 *     `me()`) cannot stomp on each other's UI states. Mirrors main.
 */

import { ApiError } from '@catalyst/api-client'
import type {
  ConfirmTotpRequest,
  DisableTotpRequest,
  EnableTotpResponse,
  RecoveryCodesResponse,
  RegenerateRecoveryCodesRequest,
  User,
  UserType,
} from '@catalyst/api-client'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { authApi } from '../api/admin-auth.api'

export type BootstrapStatus = 'idle' | 'loading' | 'ready' | 'error'

/**
 * Error code emitted by the admin `/me` endpoint when an authenticated
 * platform-admin has not yet enrolled 2FA. The bootstrap action
 * special-cases this so the chunk-7.4 router guard can branch on a
 * derived flag rather than re-inspecting the underlying error.
 *
 * Identical to main's `MFA_ENROLLMENT_REQUIRED_CODE` constant by
 * intent — they are the same backend code, exported from each store
 * for module-locality.
 */
export const MFA_ENROLLMENT_REQUIRED_CODE = 'auth.mfa.enrollment_required'

export const useAdminAuthStore = defineStore('adminAuth', () => {
  // ---------------------------------------------------------------
  // State
  // ---------------------------------------------------------------
  const user = ref<User | null>(null)
  const bootstrapStatus = ref<BootstrapStatus>('idle')
  const mfaEnrollmentRequired = ref(false)

  const isLoggingIn = ref(false)
  const isLoggingOut = ref(false)
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

  /**
   * True when the stored user has 2FA enabled. Consumed by the
   * sub-chunk 7.4 router guard to enforce the mandatory-MFA invariant
   * for admin users (`docs/05-SECURITY-COMPLIANCE.md § 6`). The store
   * exposes the state; routing logic lives in the guard, NOT here.
   */
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
   * Drop the stored user back to `null` and reset the MFA-required
   * flag. Called after logout, after an authoritative 401, and from
   * tests.
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
          // Cold-load anonymous admin session — normal, not an error.
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
          // Admin authenticated but has not enrolled 2FA. The backend
          // does NOT include a user payload on this path — leave
          // `user` untouched and surface the derived flag for the
          // sub-chunk 7.4 router guard to branch on.
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
   * Step 2 of TOTP enrollment. Returns the plaintext recovery codes —
   * these MUST stay outside Pinia state (PROJECT-WORKFLOW.md § 5.1).
   * The caller (sub-chunk 7.5 component) holds them in component-local
   * state for one-time display.
   *
   * The optimistic-update pattern mirrors main's chunk-6.2-6.4
   * change-request #2 contract: the backend just confirmed enrollment,
   * so we flip `two_factor_enabled` to true immediately and let the
   * follow-up me() refresh other drifted fields. A me() failure is
   * invisible — the canonical state for `two_factor_enabled` is
   * already in place for the router guard to read.
   */
  async function verifyTotp(payload: ConfirmTotpRequest): Promise<RecoveryCodesResponse> {
    isVerifyingTotp.value = true
    try {
      const result = await authApi.verifyTotp(payload)
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
    enrollTotp,
    verifyTotp,
    disableTotp,
    regenerateRecoveryCodes,
  }
})
