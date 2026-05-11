import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  mintTotpCodeForEmail,
  mintTotpFromSecret,
  neutralizeThrottle,
  resetClock,
  restoreThrottle,
  signOutViaApi,
} from '../fixtures/test-helpers'

/**
 * 20-PHASE-1-SPEC.md § 7 priority #19 — 2FA enrollment + sign-in.
 *
 * Flow under test:
 *   1. Sign up a fresh user via the SPA's sign-up form.
 *   2. Sign in with email + password. Land on `/auth/2fa/enable`
 *      (the requireMfaEnrolled router guard rebounds the dashboard
 *      navigation because `two_factor_enabled = false`).
 *   3. The enable-totp page calls `/auth/2fa/enable` on mount and
 *      renders the QR + manual key. Read the manual key from the
 *      DOM and forward it to the chunk-7.1 in-flight TOTP helper
 *      (`mintTotpFromSecret`). The post-confirm helper
 *      (`mintTotpCodeForEmail`) cannot be used here because
 *      `users.two_factor_secret` is still NULL — the secret lives
 *      in cache (`identity:2fa:enroll:`) until step 4 lands.
 *   4. Submit the code. The recovery codes panel appears. The 5-
 *      second countdown has to elapse before the "I have saved them"
 *      button is enabled — Playwright `expect(...).toBeEnabled()`
 *      polls until it is, capping at the chunk-6.7 default of 5s.
 *   5. Land on `/` (the chunk-6.5 dashboard route is `path: '/'`,
 *      named `app.dashboard` — see deviation note in the chunk-6.8
 *      review).
 *   6. Sign out (via the chunk 7-defer fixture; no UI button yet).
 *   7. Sign in again. The backend now answers `auth.mfa_required`
 *      because the user has 2FA enrolled; the SignInPage reveals the
 *      TOTP field inline. Mint a fresh code via the post-confirm
 *      helper (the secret is now persisted on the user row), submit,
 *      land on `/`.
 *
 * Two minting paths, intentional split:
 *   - Step 3 (in-flight): `mintTotpFromSecret(request, secret)`. The
 *     secret is read from the DOM via the `enable-totp-manual-key`
 *     element. The helper is gated by the chunk-6.1 token header.
 *   - Step 7 (post-confirm): `mintTotpCodeForEmail(request, email)`.
 *     Same email-keyed lookup the chunk-6.8 helper has always
 *     supported, now reaching a confirmed `users.two_factor_secret`.
 *
 * Assertions are anchored on `data-test` attributes and i18n keys
 * (no English-string matches), so a future locale change will not
 * flake the spec.
 */

const PASSWORD = 'CorrectHorseBatteryStaple1!'

function uniqueEmail(): string {
  // Per-test email keeps the suite hermetic across re-runs even if
  // the database isn't reset between specs (the global setup wipes
  // it once per suite invocation, but in-suite sequencing should not
  // depend on shared mutable state).
  return `spec19-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('spec #19 — 2FA enrollment + sign-in', () => {
  test.beforeEach(async ({ request }) => {
    // Neutralise the per-IP auth limiter (`auth-ip`, 10/min/IP from
    // `IdentityServiceProvider::registerRateLimits()`). The full
    // enrollment + re-sign-in flow consumes ~7 auth-ip hits per
    // attempt (sign-up + 2 sign-ins + 4 enrollment-related calls),
    // and Playwright retries on failure — three retries can
    // accumulate >20 hits inside a single Carbon-pinned bucket and
    // saturate the limiter. Spec #20 traffic landing on the same
    // shared CI runner IP also contributes. Pair with
    // `restoreThrottle` in `afterEach` so we don't leak the override
    // across specs (mandatory pair the controller docblock
    // enforces — same convention as `setClock`/`resetClock`).
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    // Restore in inverse order. Both calls are idempotent — even if
    // the test bailed before either side-effect ran, the cleanup
    // still runs cleanly.
    await restoreThrottle(request, 'auth-ip')
    // Belt-and-suspenders: this spec doesn't touch the test clock,
    // but spec #20 does — running the reset here guarantees that a
    // run order that interleaves the two cannot cross-contaminate.
    await resetClock(request)
  })

  test('full enrollment + re-sign-in flow', async ({ page }) => {
    const email = uniqueEmail()

    // -----------------------------------------------------------------
    // Step 1 — sign up via the SPA form.
    // -----------------------------------------------------------------
    await page.goto('/sign-up')
    await expect(page.locator(dt(testIds.signUpPage))).toBeVisible()

    await page.locator(dt(testIds.signUpName)).locator('input').fill('Spec User')
    await page.locator(dt(testIds.signUpEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signUpPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signUpPasswordConfirmation)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signUpSubmit)).click()

    // SPA redirects to the email-verification-pending page on success.
    await expect(page).toHaveURL(/\/verify-email\/pending/)
    await expect(page.locator(dt(testIds.emailVerificationPendingPage))).toBeVisible()

    // -----------------------------------------------------------------
    // Step 2 — sign in. The requireMfaEnrolled guard sends us to
    // `/auth/2fa/enable` because the new user has no 2FA yet.
    // -----------------------------------------------------------------
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()

    await expect(page).toHaveURL(/\/auth\/2fa\/enable/)
    await expect(page.locator(dt(testIds.enableTotpPage))).toBeVisible()

    // -----------------------------------------------------------------
    // Step 3 — wait for the manual key to render, read it from the
    // DOM, and mint the current code via the chunk-7.1 in-flight
    // helper. The post-confirm `mintTotpCodeForEmail` helper would
    // 422 here because the secret isn't on `users.two_factor_secret`
    // yet (it lives in cache during enrollment-in-progress).
    // -----------------------------------------------------------------
    await expect(page.locator(dt(testIds.enableTotpManualKey))).toBeVisible()
    const manualKey = (await page.locator(dt(testIds.enableTotpManualKey)).innerText()).trim()
    expect(manualKey.length).toBeGreaterThan(0)
    const code = await mintTotpFromSecret(page.context().request, manualKey)

    // -----------------------------------------------------------------
    // Step 4 — submit the code. Recovery codes appear with the
    // 5-second countdown gate.
    // -----------------------------------------------------------------
    await page.locator(dt(testIds.enableTotpCode)).locator('input').fill(code.code)
    await page.locator(dt(testIds.enableTotpSubmit)).click()

    await expect(page.locator(dt(testIds.recoveryCodesDisplay))).toBeVisible()
    await expect(page.locator(dt(testIds.recoveryCodesList))).toBeVisible()

    // The button stays disabled for 5s. Playwright's `toBeEnabled`
    // assertion polls until the assertion holds; the default
    // `expect.timeout` of 5000ms is bumped to 8000ms so we have a
    // safe margin for the countdown to elapse on slow CI runners.
    await expect(page.locator(dt(testIds.recoveryCodesConfirm))).toBeEnabled({
      timeout: 8_000,
    })
    await page.locator(dt(testIds.recoveryCodesConfirm)).click()

    // -----------------------------------------------------------------
    // Step 5 — land on `/`. The route name is `app.dashboard`; the
    // path is the empty `/`. The kickoff text says "/dashboard"
    // but the chunk 6.5 route table uses `/`. The deviation is
    // flagged in the chunk-6.8 review file.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL('http://127.0.0.1:5173/')

    // -----------------------------------------------------------------
    // Step 6 — sign out. Chunk 7 owns the nav-surface UI; until
    // then we drive logout via the API fixture (cookie shared with
    // the page context).
    // -----------------------------------------------------------------
    await signOutViaApi(page.context().request)

    // -----------------------------------------------------------------
    // Step 7 — sign in again. The backend answers `auth.mfa_required`
    // and the SignInPage reveals the TOTP field inline. The user now
    // has `users.two_factor_secret` populated (step 4 confirmed the
    // enrollment), so the post-confirm `mintTotpCodeForEmail`
    // fixture works.
    // -----------------------------------------------------------------
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()

    // The TOTP field appears once the backend signals MFA required.
    await expect(page.locator(dt(testIds.signInTotp))).toBeVisible()

    // Mint a fresh code (the first one may be in the prior 30s
    // window now; minting again is cheap and deterministic).
    const second = await mintTotpCodeForEmail(page.context().request, email)
    await page.locator(dt(testIds.signInTotp)).locator('input').fill(second.code)
    await page.locator(dt(testIds.signInSubmit)).click()

    // Land on `/` — chunk 6.5 dashboard route, MFA enrolled this
    // time so the requireMfaEnrolled guard passes.
    await expect(page).toHaveURL('http://127.0.0.1:5173/')
  })
})
