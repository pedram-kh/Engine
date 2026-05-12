import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  mintTotpFromSecret,
  neutralizeThrottle,
  resetClock,
  restoreThrottle,
  signOutViaApi,
  signUpAdminUser,
} from '../fixtures/test-helpers'

/**
 * 20-PHASE-1-SPEC.md § 7 admin priority — admin sign-in happy path.
 *
 * Flow under test:
 *   1. Seed an admin user with 2FA already enrolled via the chunk-7.6
 *      test-helper `POST /_test/users/admin?enrolled=true`. Production
 *      sign-up cannot create admin users (admin onboarding is out-of-
 *      band per `docs/20-PHASE-1-SPEC.md` § 5); see the
 *      CreateAdminUserController docblock + Group 3 deviation #D1 for
 *      the design discussion.
 *   2. Navigate to `/sign-in`. Submit email + password. The backend
 *      answers `auth.mfa_required` because the admin has 2FA
 *      enrolled; the SignInPage reveals the TOTP field inline (chunk
 *      6.6 same-page reveal pattern mirrored in admin's chunk-7.5
 *      SignInPage).
 *   3. Mint a fresh TOTP code from the secret returned by the
 *      provisioning helper, fill, submit, land on `/`.
 *
 * Chunk-7.1 saga conventions manifested from the first commit:
 *   - Per-spec `auth-ip` neutralization in `beforeEach` + restore in
 *     `afterEach` (saga finding #1).
 *   - Shared `defaultHeaders` constant on every API-driven fixture
 *     (saga finding #2).
 *   - No parent `data-test` attrs that fall through to children with
 *     their own root `data-test` (saga finding #4).
 *   - `resetClock` in `afterEach` even though this spec doesn't pin
 *     the clock (defence in depth against a future cross-spec bleed).
 *
 * Assertions are anchored on `data-test` attributes (no English-
 * string matches), so a future locale change will not flake the spec.
 */

const PASSWORD = 'CorrectHorseBatteryStaple1!'

function uniqueEmail(): string {
  return `admin-signin-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('admin sign-in — happy path', () => {
  test.beforeEach(async ({ request }) => {
    // Neutralise the per-IP auth limiter (`auth-ip`, 10/min/IP from
    // `IdentityServiceProvider::registerRateLimits()`). The admin
    // suite traffic shares the same limiter bucket as e2e-main on
    // localhost in dev (CI gets fresh service containers per job).
    // Pair with `restoreThrottle` in `afterEach`.
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    await restoreThrottle(request, 'auth-ip')
    // Belt-and-suspenders: this spec doesn't pin the clock, but the
    // mandatory-MFA-enrollment spec does. Resetting here too means
    // no run order can cross-contaminate.
    await resetClock(request)
  })

  test('admin with 2FA enrolled signs in via the inline TOTP reveal', async ({ page, request }) => {
    const email = uniqueEmail()

    // -----------------------------------------------------------------
    // Step 1 — seed a pre-enrolled admin via the test-helper. The
    // returned `twoFactorSecret` is stamped on `users.two_factor_secret`
    // and is what the post-confirm `mintTotpFromSecret` helper consumes.
    // -----------------------------------------------------------------
    const admin = await signUpAdminUser(request, email, PASSWORD, { enrolled: true })
    expect(admin.twoFactorSecret).not.toBeNull()
    /** @type {string} */
    const secret = admin.twoFactorSecret as string

    // -----------------------------------------------------------------
    // Step 2 — navigate to /sign-in, submit email + password. The
    // backend signals `auth.mfa_required`, the SignInPage reveals the
    // TOTP field inline (chunk-7.5 mirror of main's chunk-6.6 UX).
    // -----------------------------------------------------------------
    await page.goto('/sign-in')
    await expect(page.locator(dt(testIds.signInPage))).toBeVisible()
    await expect(page.locator(dt(testIds.authBrand))).toBeVisible()

    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()

    await expect(page.locator(dt(testIds.signInTotp))).toBeVisible()

    // -----------------------------------------------------------------
    // Step 3 — mint a fresh code from the secret and submit. Lands
    // on `/` (admin's `app.dashboard` route — see
    // `apps/admin/src/modules/auth/routes.ts`).
    // -----------------------------------------------------------------
    const code = await mintTotpFromSecret(page.context().request, secret)
    await page.locator(dt(testIds.signInTotp)).locator('input').fill(code.code)
    await page.locator(dt(testIds.signInSubmit)).click()

    await expect(page).toHaveURL(/127\.0\.0\.1:5174\/$/)

    // Sign out via the admin logout endpoint so cookie state does not
    // bleed into the next spec on the same suite invocation.
    await signOutViaApi(page.context().request)
  })
})
