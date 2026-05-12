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
 * Admin mandatory-MFA enrollment journey + D7 deep-link preservation
 * (chunk-7.4 design decision, chunk-7.6 end-to-end coverage).
 *
 * Flow under test (D7 chained-flow shape, matching the unit test
 * added during Group 2's spot-check):
 *   1. Seed a fresh admin user WITHOUT pre-enrolled 2FA via
 *      `signUpAdminUser(...)` (default `enrolled: false`). The
 *      backend's `/admin/me` endpoint will return 403
 *      `auth.mfa.enrollment_required` for this user once signed in
 *      (chunk 5 priority #7's EnsureMfaForAdmins middleware).
 *   2. Deep-link to `/settings`. The router's `requireAuth` guard
 *      sees no session, redirects to
 *      `/sign-in?redirect=/settings` (chunks 6.5 + 7.4 redirect
 *      contract). Vue Router serialises `query: { redirect: '/settings' }`
 *      with a literal `/` (slashes are reserved-but-permitted in the
 *      query component per RFC 3986 §3.4, and Vue Router does not
 *      percent-encode them), so the URL assertion below matches a
 *      literal `/`, not `%2F` — chunk-7.6 hotfix #1 finding.
 *   3. Sign in. The `requireAuth` guard's `bootstrap()` call sees the
 *      403 envelope and flips the auth store's `mfaEnrollmentRequired`
 *      flag. The same guard's `mfaEnrollmentRequired` branch redirects
 *      to `/auth/2fa/enable?redirect=/settings` (D7: the intended
 *      destination is preserved across the MFA redirect — see
 *      `apps/admin/src/core/router/guards.ts:92-99`).
 *   4. Complete the 2FA enrollment: read the manual key from the DOM,
 *      mint a TOTP code from it (cache-resident secret — same
 *      in-flight path as main's chunk-7.1 spec #19), submit, wait
 *      for the recovery codes 5-second countdown, confirm.
 *   5. Land on `/settings` — NOT on the dashboard. This is the
 *      load-bearing D7 assertion: the admin's EnableTotpPage honors
 *      the preserved `?redirect=` query (chunk-7.5 admin-only
 *      adaptation; main's identical-shape page hard-codes
 *      `app.dashboard` because main's 2FA is opt-in).
 *
 * Chunk-7.1 saga conventions manifested from the first commit:
 *   - Per-spec `auth-ip` neutralization in `beforeEach` + restore in
 *     `afterEach`. The full flow consumes ~5 auth-ip hits per attempt
 *     (sign-up helper + sign-in + 3 enrollment-related calls), and
 *     Playwright retries on failure — three retries can accumulate
 *     >15 hits inside a single Carbon-pinned bucket and saturate the
 *     limiter.
 *   - JSON headers (Accept + X-Requested-With) on every API-driven
 *     fixture via shared `defaultHeaders` constant.
 *   - No parent `data-test` attrs that fall through to children with
 *     their own root `data-test` (EnableTotpPage carries the
 *     reminder comment on the `<RecoveryCodesDisplay>` slot).
 *   - `setClock` is NOT used by this spec (the journey runs in
 *     real time and the cookie expiry T0 baseline is irrelevant) —
 *     but `resetClock` runs in `afterEach` as a belt-and-suspenders
 *     reset in case a cross-spec ordering pin leaks.
 */

const PASSWORD = 'CorrectHorseBatteryStaple1!'

function uniqueEmail(): string {
  return `admin-mfa-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('admin mandatory-MFA enrollment journey', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    await restoreThrottle(request, 'auth-ip')
    await resetClock(request)
  })

  test('D7 deep-link to /settings is preserved across the MFA enrollment redirect', async ({
    page,
    request,
  }) => {
    const email = uniqueEmail()

    // -----------------------------------------------------------------
    // Step 1 — seed an admin without pre-enrolled 2FA. The /admin/me
    // endpoint will 403 `auth.mfa.enrollment_required` after sign-in.
    // -----------------------------------------------------------------
    await signUpAdminUser(request, email, PASSWORD, { enrolled: false })

    // -----------------------------------------------------------------
    // Step 2 — deep-link to /settings. No session → bounce to
    // /sign-in with `?redirect=/settings`.
    // -----------------------------------------------------------------
    await page.goto('/settings')
    await expect(page).toHaveURL(/\/sign-in\?redirect=\/settings$/)
    await expect(page.locator(dt(testIds.signInPage))).toBeVisible()

    // -----------------------------------------------------------------
    // Step 3 — submit credentials. requireAuth's bootstrap() sees the
    // 403 and the guard's `mfaEnrollmentRequired` branch redirects
    // to /auth/2fa/enable with the preserved ?redirect query.
    // -----------------------------------------------------------------
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()

    await expect(page).toHaveURL(/\/auth\/2fa\/enable\?redirect=\/settings$/)
    await expect(page.locator(dt(testIds.enableTotpPage))).toBeVisible()

    // -----------------------------------------------------------------
    // Step 4 — read the manual key from the DOM, mint a TOTP code
    // from it (cache-resident secret during enrollment, same path
    // as main's chunk-7.1 spec #19 in-flight branch), submit.
    // -----------------------------------------------------------------
    await expect(page.locator(dt(testIds.enableTotpManualKey))).toBeVisible()
    const manualKey = (await page.locator(dt(testIds.enableTotpManualKey)).innerText()).trim()
    expect(manualKey.length).toBeGreaterThan(0)
    const code = await mintTotpFromSecret(page.context().request, manualKey)

    await page.locator(dt(testIds.enableTotpCode)).locator('input').fill(code.code)
    await page.locator(dt(testIds.enableTotpSubmit)).click()

    // -----------------------------------------------------------------
    // Step 4 cont. — recovery-codes panel appears. The 5s countdown
    // gates the confirm button (chunk-7.5 mirror of chunk-6.7
    // transience invariant). Poll for enable.
    // -----------------------------------------------------------------
    await expect(page.locator(dt(testIds.recoveryCodesDisplay))).toBeVisible()
    await expect(page.locator(dt(testIds.recoveryCodesList))).toBeVisible()
    await expect(page.locator(dt(testIds.recoveryCodesConfirm))).toBeEnabled({
      timeout: 8_000,
    })
    await page.locator(dt(testIds.recoveryCodesConfirm)).click()

    // -----------------------------------------------------------------
    // Step 5 — LOAD-BEARING D7 ASSERTION: land on /settings (the
    // original deep-link target), NOT on /. The admin EnableTotpPage's
    // post-confirm navigation honors the preserved `?redirect`.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL('http://127.0.0.1:5174/settings')

    // Sign out so cookie state does not bleed into the next spec.
    await signOutViaApi(page.context().request)
  })
})
