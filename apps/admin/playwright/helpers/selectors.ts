/**
 * Single source of truth for the `data-test` selectors the chunk-7.6
 * admin Playwright specs touch.
 *
 * Mirror of `apps/main/playwright/helpers/selectors.ts` (chunk 6.8)
 * scoped to admin's surface — sign-in, 2FA enable/verify, recovery
 * codes, AuthLayout brand mark. Admin omits the sign-up and
 * forgot-password selectors since those flows are out-of-band per
 * `docs/20-PHASE-1-SPEC.md` § 5.
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
 * Anchored on the `data-test="…"` attributes shipped by the chunk-7.5
 * admin page components — none of the values below are new; they
 * mirror what is in the `.vue` templates.
 */

export const testIds = {
  // ---------------------------------------------------------------
  // Sign-in (apps/admin/src/modules/auth/pages/SignInPage.vue)
  // ---------------------------------------------------------------
  signInPage: 'sign-in-page',
  signInHeading: 'sign-in-heading',
  signInEmail: 'sign-in-email',
  signInPassword: 'sign-in-password',
  signInTotp: 'sign-in-totp',
  signInError: 'sign-in-error',
  signInSubmit: 'sign-in-submit',

  // ---------------------------------------------------------------
  // Enable 2FA (apps/admin/src/modules/auth/pages/EnableTotpPage.vue)
  // ---------------------------------------------------------------
  enableTotpPage: 'enable-totp-page',
  enableTotpHeading: 'enable-totp-heading',
  enableTotpQr: 'enable-totp-qr',
  enableTotpManualKey: 'enable-totp-manual-key',
  enableTotpCode: 'enable-totp-code',
  enableTotpError: 'enable-totp-error',
  enableTotpSubmit: 'enable-totp-submit',

  // ---------------------------------------------------------------
  // Recovery codes (chunk 7.5 component — mirror of chunk-6.7 main)
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
