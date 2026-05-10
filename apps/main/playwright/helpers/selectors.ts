/**
 * Single source of truth for the `data-test` selectors the chunk-6.8
 * Playwright specs touch.
 *
 * Why centralised:
 *   - A renamed selector breaks the test compile (`testIds.signInPage`
 *     no longer resolves) instead of the test runtime (selector
 *     never matches and Playwright times out), which makes the
 *     failure trivially diagnosable.
 *   - Specs read these as named imports, so a refactor that renames
 *     a `data-test` attribute requires editing this one file rather
 *     than chasing string literals across the spec tree.
 *
 * Anchored on the `data-test="…"` attributes already shipped by the
 * chunk 6.6 / 6.7 page components — none of the values below are
 * new; they mirror what is in the .vue templates.
 *
 * Building selector strings:
 *   - `dt(id)` returns `[data-test="<id>"]` — the standard CSS
 *     attribute selector Playwright's `page.locator()` accepts.
 *   - Specs can compose selectors via Playwright's locator API:
 *     `page.locator(dt(testIds.signInError)).innerText()`.
 */

export const testIds = {
  // ---------------------------------------------------------------
  // Sign-in (apps/main/src/modules/auth/pages/SignInPage.vue)
  // ---------------------------------------------------------------
  signInPage: 'sign-in-page',
  signInHeading: 'sign-in-heading',
  signInEmail: 'sign-in-email',
  signInPassword: 'sign-in-password',
  signInTotp: 'sign-in-totp',
  signInError: 'sign-in-error',
  signInSubmit: 'sign-in-submit',
  signInSignupLink: 'sign-in-signup-link',

  // ---------------------------------------------------------------
  // Sign-up (apps/main/src/modules/auth/pages/SignUpPage.vue)
  // ---------------------------------------------------------------
  signUpPage: 'sign-up-page',
  signUpHeading: 'sign-up-heading',
  signUpName: 'sign-up-name',
  signUpEmail: 'sign-up-email',
  signUpPassword: 'sign-up-password',
  signUpPasswordConfirmation: 'sign-up-password-confirmation',
  signUpError: 'sign-up-error',
  signUpSubmit: 'sign-up-submit',

  // ---------------------------------------------------------------
  // Email verification pending (post-sign-up landing page)
  // ---------------------------------------------------------------
  emailVerificationPendingPage: 'email-verification-pending-page',
  emailVerificationPendingHeading: 'email-verification-pending-heading',

  // ---------------------------------------------------------------
  // Email verification confirm (link-target page)
  // ---------------------------------------------------------------
  emailVerificationConfirmPage: 'email-verification-confirm-page',
  emailVerificationConfirmSuccess: 'email-verification-confirm-success',
  emailVerificationConfirmError: 'email-verification-confirm-error',

  // ---------------------------------------------------------------
  // Enable 2FA (apps/main/src/modules/auth/pages/EnableTotpPage.vue)
  // ---------------------------------------------------------------
  enableTotpPage: 'enable-totp-page',
  enableTotpHeading: 'enable-totp-heading',
  enableTotpQr: 'enable-totp-qr',
  enableTotpManualKey: 'enable-totp-manual-key',
  enableTotpCode: 'enable-totp-code',
  enableTotpError: 'enable-totp-error',
  enableTotpSubmit: 'enable-totp-submit',

  // ---------------------------------------------------------------
  // Recovery codes (chunk 6.7 component)
  // ---------------------------------------------------------------
  recoveryCodesDisplay: 'recovery-codes-display',
  recoveryCodesList: 'recovery-codes-list',
  recoveryCodesCountdown: 'recovery-codes-countdown',
  recoveryCodesConfirm: 'recovery-codes-confirm',

  // ---------------------------------------------------------------
  // Auth shell (AuthLayout.vue) — the brand mark is a stable
  // anchor that proves AuthLayout mounted, distinct from the
  // routed page underneath.
  // ---------------------------------------------------------------
  authBrand: 'auth-brand',
} as const

export type TestId = (typeof testIds)[keyof typeof testIds]

/**
 * `dt('sign-in-page')` → `[data-test="sign-in-page"]`. Spec-side
 * convenience so the locator builder is one short call instead of a
 * template string.
 */
export function dt(id: TestId): string {
  return `[data-test="${id}"]`
}
