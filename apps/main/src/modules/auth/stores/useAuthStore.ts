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

import { ApiError, UI_LOCALES } from '@catalyst/api-client'
import type {
  ConfirmTotpRequest,
  DisableTotpRequest,
  EnableTotpResponse,
  ForgotPasswordRequest,
  PreferredLanguage,
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

import { writeStoredLocale } from '@/composables/useLocalePreference'
import {
  ensureInstallReconciled,
  localStorageMarker,
  type ReconcileOutcome,
} from '@/core/auth/installMarker'
import { nativeTokenStore } from '@/core/auth/tokenStore'
import { setLocale } from '@/core/i18n'
import { isNativePlatform, nativePlatform } from '@/core/platform/isNativePlatform'
import { clearIconBadge } from '@/core/platform/badge/iconBadge'
import { pushLogoutCleanup } from '@/core/platform/push/pushLogoutCleanup'
import { setAuthRebootstrap, useAgencyStore } from '@/core/stores/useAgencyStore'
import { resetWelcomeBackFlag } from '@/modules/onboarding/internal/welcomeBackFlag'
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
  /**
   * AH-100b D3 — creator app only. Set by the cold-start bootstrap when the
   * stored token turned out to be dead (past its expiry, or refused by
   * `/me`), so the sign-in screen can say why the user is signed out. Never
   * set on web, never set by a deliberate sign-out; `login()` and
   * `logout()` both clear it.
   */
  const sessionLapsed = ref(false)

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

  /**
   * AH-087 — whether a platform admin has exempted this user from the
   * 2FA-enrollment requirement. Read by `requireMfaUnlessExempt`.
   *
   * Defaults to `false` when absent (no user, or a payload predating the
   * field), so an unknown answer keeps the gate closed.
   */
  const isMfaExempt = computed(() => user.value?.attributes.mfa_exempt ?? false)

  // ---------------------------------------------------------------
  // Actions — identity primitives
  // ---------------------------------------------------------------

  /**
   * Replace the stored user. The login() action calls this internally;
   * tests can call it directly to seed state. Also seeds the agency store
   * from the user's membership list.
   */
  function setUser(next: User): void {
    user.value = next
    mfaEnrollmentRequired.value = false
    useAgencyStore().initFromUser(next.relationships?.agency_memberships?.data ?? [])
    hydratePreferredLocale(next)
  }

  /**
   * Server-wins locale hydration. The persisted `preferred_language` is the
   * authoritative answer to "what language?" once a user loads, so on every
   * identity refresh (login, cold-load bootstrap, post-MFA refresh) we
   * sync both `localStorage` and the active i18n locale to it — a reload
   * and a fresh login then both land on the saved language. Unrenderable /
   * absent values are ignored (the boot/default locale stays). `setLocale`
   * lazy-loads the target bundle, so this is fire-and-forget.
   */
  function hydratePreferredLocale(next: User): void {
    const language = next.attributes.preferred_language
    if (language !== null && (UI_LOCALES as readonly string[]).includes(language)) {
      writeStoredLocale(language)
      void setLocale(language)
    }
  }

  /**
   * Drop the stored user back to `null` and reset MFA-required flag.
   * Called after logout, after an authoritative 401, and from tests.
   * Resets bootstrapStatus to 'idle' so the next requireAuth guard
   * triggers a fresh bootstrap rather than short-circuiting.
   */
  function clearUser(): void {
    // AH-108 (4b) — every end of a session (sign-out, a lapsed token, a failed
    // bootstrap) wipes the home-screen badge: it is the previous user's number.
    // Fire and forget; a no-op off native iOS; never throws.
    void clearIconBadge()
    user.value = null
    mfaEnrollmentRequired.value = false
    bootstrapStatus.value = 'idle'
    useAgencyStore().reset()
    // Tear down the tab-scoped "already showed the onboarding landing"
    // flag so a sign-out → sign-in cycle in the same tab re-shows the
    // Welcome / Let's-get-started screen for the next session.
    resetWelcomeBackFlag()
  }

  // ---------------------------------------------------------------
  // bootstrap() — cold-load identity resolution
  // ---------------------------------------------------------------

  async function bootstrap(): Promise<void> {
    if (inFlightBootstrap !== null) {
      return inFlightBootstrap
    }

    // Skip re-fetch if already bootstrapped. clearUser() resets the status
    // to 'idle' on logout so the next auth-required navigation re-runs.
    if (bootstrapStatus.value === 'ready') {
      return
    }

    bootstrapStatus.value = 'loading'

    inFlightBootstrap = (async (): Promise<void> => {
      try {
        // AH-100 — the creator app with no stored token is anonymous;
        // there is nothing to ask `/me` about.
        if (isNativePlatform()) {
          // AH-103d — a reinstalled phone must be signed out BEFORE the token is
          // read: iOS keeps the Keychain item across Delete App. Memoised, so this
          // is the same run `main.ts` started at boot.
          await reconcileInstall()
          const tokens = nativeTokenStore()
          if ((await tokens.read()) === null) {
            user.value = null
            sessionLapsed.value = tokens.hasLapsed?.() === true
            bootstrapStatus.value = 'ready'
            return
          }
        }
        const me = await authApi.me()
        // Use setUser() so the agency store is initialized from the membership
        // list embedded in the /me response. Direct user.value assignment would
        // skip agencyStore.initFromUser() and leave currentAgencyId null.
        setUser(me)
        bootstrapStatus.value = 'ready'
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          // Cold-load anonymous session — normal, not an error. In the
          // creator app it means the stored token is dead: drop it.
          if (isNativePlatform()) {
            sessionLapsed.value = true
            await nativeTokenStore()
              .clear()
              .catch(() => undefined)
          }
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
  // refreshSession() — AH-100d: re-read /me while signed in
  // ---------------------------------------------------------------

  // Dedup cache for concurrent refreshSession() calls (a second resume while
  // the first is still in flight returns the same promise: one /me, not two).
  let inFlightRefresh: Promise<void> | null = null

  /**
   * Re-fetch `/me` for a user who is already signed in, and replace the held
   * user with the answer. The creator app calls this when it comes back to the
   * foreground (`core/platform/appResume.ts`): an app is backgrounded for
   * hours, and the server-owned parts of the session (the approved state, the
   * admin's changes) move while it sleeps.
   *
   * Guards, each a specced polarity:
   *   - Signed out (no user, or bootstrap not `ready`): returns without a
   *     request. Resuming onto the sign-in screen must not call `/me`.
   *   - Overlap: one request at a time (see `inFlightRefresh`).
   *   - A sign-out or a different user arriving while the request was in
   *     flight discards the answer, so a late reply can never resurrect a
   *     session the user just ended.
   *   - A 401 in the creator app means the token is dead (the same rule as the
   *     cold-load bootstrap): drop it, mark the session lapsed, clear the user.
   *     Any other failure is swallowed — a background refresh failing is not
   *     an error state, the held user stays and the next resume retries.
   *
   * Deliberately NOT `setUser()`: no locale hydration, no agency-store reset.
   * It replaces the identity fields in place and nothing else.
   */
  async function refreshSession(): Promise<void> {
    if (inFlightRefresh !== null) {
      return inFlightRefresh
    }
    if (user.value === null || bootstrapStatus.value !== 'ready') {
      return
    }
    const requestedFor = user.value.id

    inFlightRefresh = (async (): Promise<void> => {
      try {
        const me = await authApi.me()
        if (user.value !== null && user.value.id === requestedFor && me.id === requestedFor) {
          user.value = me
        }
      } catch (error) {
        if (
          error instanceof ApiError &&
          error.status === 401 &&
          isNativePlatform() &&
          user.value !== null &&
          user.value.id === requestedFor
        ) {
          sessionLapsed.value = true
          await nativeTokenStore()
            .clear()
            .catch(() => undefined)
          clearUser()
        }
      } finally {
        inFlightRefresh = null
      }
    })()

    return inFlightRefresh
  }

  // ---------------------------------------------------------------
  // Actions — auth surface
  // ---------------------------------------------------------------

  /**
   * Every caller (sign-in page, TOTP page) goes through `login()`; inside
   * the creator app it dispatches to {@link loginWithToken}, so no native
   * path can reach the cookie endpoint.
   */
  async function login(email: string, password: string, totpCode?: string): Promise<void> {
    sessionLapsed.value = false
    if (isNativePlatform()) {
      return loginWithToken(email, password, totpCode)
    }
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

  /**
   * AH-100 D5 — `POST /auth/token` for the creator app. The token is
   * persisted before the user is set, so a signed-in app always has a
   * token behind it. Failure envelopes (wrong door, 2FA, suspension)
   * surface unchanged for the page to render.
   */
  async function loginWithToken(email: string, password: string, totpCode?: string): Promise<void> {
    const platform = nativePlatform()
    if (platform === null) {
      throw new Error('loginWithToken() is native-only.')
    }
    isLoggingIn.value = true
    try {
      const result = await authApi.tokenLogin({
        email,
        password,
        platform,
        ...(totpCode !== undefined ? { mfa_code: totpCode } : {}),
      })
      // AH-103d — `requireGuest` has no bootstrap, so the sign-in page can be reached
      // first: a late reconcile (marker absent, token present = the NEW token) would
      // sign out the session just made. Wait for the decision before writing.
      await reconcileInstall()
      await nativeTokenStore().write(result.token, result.expiresAt)
      setUser(result.user)
    } finally {
      isLoggingIn.value = false
    }
  }

  /**
   * In the creator app, signing out on the device is authoritative: the
   * revoke request carries the token, then the token and user are
   * dropped whatever the server said. A revoke that never arrived leaves
   * the server-side token to lapse at its `expires_at`.
   */
  async function nativeLogout(): Promise<void> {
    // AH-102 (R-4) — forget this device's push token while the bearer still
    // works (the DELETE needs it). Best-effort and bounded: it never throws and
    // never holds sign-out past its own short timeout. It MUST run before the
    // token is cleared below; the order is pinned by a spec.
    await pushLogoutCleanup().catch(() => undefined)
    try {
      await authApi.logout()
    } catch {
      // Device-side sign-out proceeds regardless.
    } finally {
      await nativeTokenStore()
        .clear()
        .catch(() => undefined)
      clearUser()
    }
  }

  /**
   * AH-103d — the reinstall marker (tech-debt 17). Creator app only; resolves to
   * `'skipped-web'` in the browser without touching storage. ONE run per app-open
   * (`main.ts` starts it at boot, `bootstrap()` and `loginWithToken()` wait for it);
   * it never rejects. The wipe is `logout()`, which on native runs `nativeLogout()`
   * verbatim (push cleanup, revoke, local clear), so the ruling "wipe through
   * nativeLogout" holds without exporting it. The token store is read UN-gated here:
   * gating the store would deadlock the sign-out, which reads the token to revoke it.
   */
  function reconcileInstall(): Promise<ReconcileOutcome> {
    if (!isNativePlatform()) {
      // The web SPA authenticates by cookie: no marker, no storage, no token store
      // (`nativeTokenStore()` throws off native), and nothing memoised.
      return Promise.resolve('skipped-web')
    }
    return ensureInstallReconciled(() => ({
      isNative: true,
      marker: localStorageMarker,
      tokens: nativeTokenStore(),
      wipe: logout,
    }))
  }

  async function logout(): Promise<void> {
    sessionLapsed.value = false
    isLoggingOut.value = true
    try {
      if (isNativePlatform()) {
        await nativeLogout()
        return
      }
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

  /**
   * Mirror a signed-in user's locale choice to the server (`PATCH /me`).
   * The locale switcher has already flipped i18n + localStorage; this
   * persists the choice so it follows the user across devices/sessions.
   * The returned resource updates the in-memory user so it stays
   * consistent with the server (and a later `setUser`/hydrate is a no-op).
   * Callers fire-and-forget: a failure leaves localStorage authoritative
   * until the next server-wins hydration reconciles.
   */
  async function setPreferredLanguage(language: PreferredLanguage): Promise<void> {
    user.value = await authApi.updateMe({ preferred_language: language })
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

  // Sprint 3 Chunk 4 sub-step 5 — wire the workspace-switch re-bootstrap
  // hook into the agency store. Using a setter-injection seam avoids a
  // top-level circular import (the agency store imports nothing from
  // auth at module level; this factory closure pushes the contract in).
  setAuthRebootstrap({
    resetBootstrapStatus(): void {
      bootstrapStatus.value = 'idle'
    },
    async bootstrap(): Promise<void> {
      await bootstrap()
    },
    isBootstrapping(): boolean {
      return bootstrapStatus.value === 'loading'
    },
  })

  return {
    // state
    user,
    bootstrapStatus,
    mfaEnrollmentRequired,
    sessionLapsed,
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
    isMfaExempt,
    // actions
    setUser,
    clearUser,
    bootstrap,
    refreshSession,
    login,
    loginWithToken,
    logout,
    reconcileInstall,
    signUp,
    setPreferredLanguage,
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
