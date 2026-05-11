import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  resetClock,
  restoreThrottle,
  setClock,
  signUpUser,
} from '../fixtures/test-helpers'

/**
 * 20-PHASE-1-SPEC.md § 7 priority #20 — failed-login lockout +
 * reset / escalation flow.
 *
 * Layer-design choice (chunk 7.1, option (i) from the kickoff)
 * ------------------------------------------------------------
 * Production stacks two layers on the login endpoint at the same
 * 5-attempts-per-minute threshold:
 *
 *   - Route-level throttle `auth-login-email` (chunk-3, defined in
 *     `IdentityServiceProvider::registerRateLimits()`).
 *   - Application-level lockout (`FailedLoginTracker` +
 *     `AccountLockoutService`, chunks 3 + 5).
 *
 * The route-level throttle preempts the application-level lockout, so
 * the SPA only ever sees `rate_limit.exceeded` from the throttle. The
 * chunk-5 Pest suite hides this overlap by registering the named
 * limiters as `Limit::none()` in `LoginTest::beforeEach` so the
 * lockout layer can be exercised in isolation. This spec mirrors that
 * shape via the chunk-7.1 `_test/rate-limiter/{name}` test-helper:
 * the throttle is neutralised in `beforeEach` and restored in
 * `afterEach`, so the request graph the spec drives lands on the
 * application-level lockout in the same way it does in the Pest
 * `LoginTest` suite.
 *
 * Why option (i), not (ii)
 * ------------------------
 * Option (ii) — assert the throttle-then-lockout chain that
 * production actually exhibits — would require either a 60-second
 * sleep between the 6th and 7th attempts (to let the throttle reset)
 * or a second test-helper that ALSO bumps the throttle's bucket. Both
 * make the spec slow or further entangled with backend internals. The
 * Pest `AuthRateLimitTest` already exercises the throttle in
 * isolation (with the lockout layer dormant because no row escalates
 * inside the test's tight loop), and the chunk-5 `LoginTest`
 * exercises the lockout in isolation. Spec #20's job is to be the
 * cross-layer integration check that the SPA actually renders the
 * lockout's i18n key when the application path is reached — so
 * option (i) is the right shape.
 *
 * Throttle-neutraliser convention (chunk-7.1 standard)
 * ----------------------------------------------------
 * The `_test/rate-limiter/{name}` endpoint mutates global state that
 * survives across `php artisan serve`'s per-request PHP processes
 * (that's the whole point — the override has to persist). Specs MUST
 * pair `neutralizeThrottle` with `restoreThrottle` in `afterEach`,
 * otherwise the next spec runs against a silently-neutralised limiter
 * and unrelated production-shape assertions become meaningless. The
 * convention is identical to `setClock` / `resetClock`.
 *
 * Flow under test (mirrors `AccountLockoutService` /
 * `FailedLoginTracker` thresholds from
 * `docs/05-SECURITY-COMPLIANCE.md § 6.2`):
 *
 *   1. Sign up a fresh user.
 *   2. Submit 5 failed login attempts (5 = SHORT_WINDOW_THRESHOLD).
 *   3. Submit a 6th. The backend short-windows the user and answers
 *      `auth.account_locked.temporary`. The SPA renders the i18n
 *      key inline; we assert on the data-test selector and the
 *      rendered text contains the locked-account substring.
 *   4. Fast-forward 16 minutes via the chunk-6.1 test clock (past
 *      SHORT_WINDOW_MINUTES = 15). Submit the correct password.
 *      Login succeeds.
 *   5. Submit 5 more failed attempts; assert the 6th hits the
 *      temporary lockout again.
 *   6. Fast-forward 24 hours past the FIRST failed attempt. Submit
 *      one more failed attempt. The backend escalates per
 *      `AccountLockoutService::escalate()` and the response carries
 *      `auth.account_locked.suspended` (the renamed code from chunks
 *      6.2–6.4). The SPA renders the suspended i18n key.
 *   7. `afterEach` resets the clock AND restores the throttle so a
 *      stray failure does not bleed pinned time or a neutralised
 *      limiter into the next spec.
 *
 * Hermeticity contract (carried forward from chunk-6.8 hotfix #3):
 *   - The Laravel API runs with `CACHE_STORE=database` (see
 *     `playwright.config.ts`) so the lockout cache survives across
 *     `php artisan serve`'s per-request PHP processes. The chunk-6.8
 *     review's post-merge addendum #3 records the discovery context.
 *   - The throttle-neutraliser cache flag also persists across those
 *     same per-request processes via the same database cache, so
 *     `TestHelpersServiceProvider::boot()` re-applies `Limit::none()`
 *     on every fresh request the spec issues.
 */

const WRONG_PASSWORD = 'WrongPassword42!'
const CORRECT_PASSWORD = 'CorrectHorseBatteryStaple1!'

function uniqueEmail(): string {
  return `spec20-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

/**
 * The test clock is set in absolute terms (the helper accepts an
 * ISO 8601 instant, not a relative offset). We anchor the spec at a
 * deterministic baseline so step 6's "+24 hours from T0" is easy to
 * reason about. Any instant that is not the unix epoch works; we
 * pick one in the chunk-6 timeframe so a stray log is recognisable.
 */
const T0 = new Date('2026-05-10T09:00:00.000Z')

function clockAtMinutes(minutes: number): string {
  return new Date(T0.getTime() + minutes * 60_000).toISOString()
}

async function attemptFailedSignIn(
  page: import('@playwright/test').Page,
  email: string,
): Promise<void> {
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(WRONG_PASSWORD)
  await page.locator(dt(testIds.signInSubmit)).click()
  // Wait until the inline error region renders the i18n-resolved
  // string so we know the round-trip completed.
  await expect(page.locator(dt(testIds.signInError))).not.toBeEmpty()
}

test.describe('spec #20 — failed-login lockout + reset / escalation', () => {
  test.beforeEach(async ({ request }) => {
    // Neutralise the named limiter so the application-level lockout
    // layer is what the request graph reaches at the 6th attempt.
    // Mirrors chunk-5 `LoginTest::beforeEach`.
    await neutralizeThrottle(request, 'auth-login-email')
  })

  test.afterEach(async ({ request }) => {
    // Restore in inverse order. Both calls are idempotent — even if
    // the test bailed before either side-effect ran, the cleanup
    // still runs cleanly.
    await restoreThrottle(request, 'auth-login-email')
    await resetClock(request)
  })

  test('short-window lockout, fast-forward unlock, long-window escalation', async ({
    page,
    request,
  }) => {
    const email = uniqueEmail()
    await signUpUser(request, email, CORRECT_PASSWORD)

    // Anchor the clock at T0 before the first attempt so every
    // failed-login timestamp lands inside a deterministic window.
    await setClock(request, clockAtMinutes(0))

    // -----------------------------------------------------------------
    // Step 2 + 3 — 5 failed attempts arm the short window; the 6th
    // returns `auth.account_locked.temporary`.
    // -----------------------------------------------------------------
    for (let i = 0; i < 5; i += 1) {
      await attemptFailedSignIn(page, email)
    }
    // The 6th attempt — temporary lockout returns the i18n key. The
    // bundle entry for `auth.account_locked.temporary` is "Too many
    // failed sign-in attempts. Please try again in a few minutes."
    // (the {minutes} interpolation is tracked as a separate
    // tech-debt entry — see "auth.account_locked.temporary i18n
    // bundle has no {minutes} interpolation" in `docs/tech-debt.md`).
    // We assert on a stable substring so a future copy update that
    // adds the {minutes} placeholder doesn't flake the spec.
    await attemptFailedSignIn(page, email)
    await expect(page.locator(dt(testIds.signInError))).toContainText('failed sign-in')

    // -----------------------------------------------------------------
    // Step 4 — fast-forward 16 minutes past T0 (past the 15-minute
    // SHORT_WINDOW). Submit the correct password; login succeeds.
    // -----------------------------------------------------------------
    await setClock(request, clockAtMinutes(16))

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(CORRECT_PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()

    // The user has no 2FA → requireMfaEnrolled bounces the dashboard
    // navigation to `/auth/2fa/enable`. That's the expected resting
    // place for a successful sign-in by an unenrolled user; the
    // sign-in itself succeeded (the redirect proves the cookie
    // landed), which is what step 4 asserts.
    await expect(page).toHaveURL(/\/auth\/2fa\/enable/)

    // -----------------------------------------------------------------
    // Step 5 — sign out (no UI button yet; clear cookies via the
    // page context so the next sign-in starts cold) and submit 5
    // more failed attempts to re-arm the short window. The 6th hits
    // the temporary lockout again.
    // -----------------------------------------------------------------
    await page.context().clearCookies()
    for (let i = 0; i < 5; i += 1) {
      await attemptFailedSignIn(page, email)
    }
    await attemptFailedSignIn(page, email)
    await expect(page.locator(dt(testIds.signInError))).toContainText('failed sign-in')

    // -----------------------------------------------------------------
    // Step 6 — fast-forward 24h + 1m past T0. Submit one more
    // failed attempt; the long-window threshold (10 failures in 24h)
    // trips and the user is escalated to `is_suspended = true`.
    //
    // We've already recorded 6 + 5 + 1 = 12 failures within the
    // pruned 24-hour window (the prune cutoff is `Carbon::now()->subHours(24)`,
    // which under the new clock is exactly T0 — the original 6
    // failures land on T0 itself, so they survive the prune by the
    // `>=` boundary). The 13th attempt below is the one that
    // triggers escalate() inside recordFailureAndMaybeLock.
    //
    // Even if a few of the original 6 fall just outside the
    // boundary, the freshly-armed 6 from step 5 still leave us at
    // ≥ 10 failures inside the long window when this attempt lands.
    // -----------------------------------------------------------------
    await setClock(request, clockAtMinutes(24 * 60 + 1))

    await attemptFailedSignIn(page, email)

    // The chunk-6.2-6.4 rename: long-window escalation now responds
    // with `auth.account_locked.suspended`. The bundle entry is
    // "This account has been locked. Reset your password or contact
    // support to regain access." We assert on a stable substring.
    await expect(page.locator(dt(testIds.signInError))).toContainText('account has been locked')
  })
})
