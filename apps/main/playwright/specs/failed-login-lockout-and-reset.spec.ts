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
 * single baseline so step 6's "+24 hours from T0" is easy to reason
 * about — within a single test run T0 is constant and every offset
 * (T0+0, T0+16m, T0+24h+1m) lands at a deterministic instant.
 *
 * Why T0 must be in the FUTURE relative to wall-clock time
 * --------------------------------------------------------
 * Laravel's session middleware computes session-cookie expiry as
 * `Carbon::now()->addMinutes(config('session.lifetime'))`, with
 * `Carbon::now()` honoring `Carbon::setTestNow()` (the test clock).
 * Symfony then serialises the cookie's `Max-Age` attribute as
 * `$expiresTimestamp - time()`, where `time()` reads REAL wall-clock
 * time — NOT Carbon. If T0 + session.lifetime lands in the past
 * relative to wall-clock now, `Max-Age` clamps to 0 and the browser
 * discards both `XSRF-TOKEN` and `catalyst_main_session` cookies the
 * instant they arrive. Every subsequent CSRF-protected POST then
 * lands without a token and 419s.
 *
 * Setting T0 to wall-clock-now + a comfortable buffer guarantees
 * `Carbon::now() + session.lifetime > time()` at every point in the
 * spec, regardless of how far Sprint 2+ pushes the wall clock past
 * the original chunk-7.1 authoring date. The buffer covers
 * session.lifetime + the spec's own +24h fast-forward + slack for
 * unrelated time pinning by future steps.
 *
 * See "Test-clock pinning interacts with Laravel cookie expiry to
 * invalidate session/XSRF cookies" in `docs/tech-debt.md` for the
 * full discovery context (chunk-7.1 post-merge hotfix).
 */
const T0 = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000)

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
    // Step 6 — fast-forward 24h past T0 (NOT 24h + 1 minute). Submit
    // one more failed attempt; the long-window threshold
    // (10 failures in 24h) trips and the user is escalated to
    // `is_suspended = true`.
    //
    // Why exactly 24h, not 24h + N minutes
    // ------------------------------------
    // `FailedLoginTracker::prune()` filters timestamps by
    // `ts >= Carbon::now()->subHours(24)->getTimestamp()` — boundary
    // INCLUSIVE. With the clock pinned at exactly T0 + 24h, the prune
    // cutoff is exactly T0, so failures recorded at T0 itself
    // (`ts = T0`) survive (`T0 >= T0`). With ANY positive offset
    // (e.g. T0 + 24h + 1min), the cutoff becomes T0 + 1min and the
    // original 6 failures recorded at T0 are pruned. The chunk-7.1
    // post-merge hotfix narrowed this from "+1min" to exactly 24h
    // after the spec failed in CI with "Invalid email or password"
    // (long-window count == 7 — short of the 10 threshold) instead
    // of "account has been locked" (long-window count == 13 once the
    // original 6 survive).
    //
    // Failure ledger at the moment this attempt is recorded:
    //   - 6 at T0           (steps 2 + 3, all 6 attempts including
    //                        the temp-lock-triggering 6th — the
    //                        temp-lock layer records BEFORE checking
    //                        the lockout, so all 6 land in the table)
    //   - 6 at T0 + 16m     (step 5, same shape — 5 + 1)
    //   - 1 at T0 + 24h     (this attempt)
    //   = 13 total in the [T0, T0 + 24h] window
    //
    // 13 >= LONG_WINDOW_THRESHOLD (10), so
    // `recordFailureAndMaybeLock()` calls `lockout->escalate($user)`
    // which sets `is_suspended = true` and the response carries
    // `auth.account_locked.suspended`.
    // -----------------------------------------------------------------
    await setClock(request, clockAtMinutes(24 * 60))

    await attemptFailedSignIn(page, email)

    // The chunk-6.2-6.4 rename: long-window escalation now responds
    // with `auth.account_locked.suspended`. The bundle entry is
    // "This account has been locked. Reset your password or contact
    // support to regain access." We assert on a stable substring.
    await expect(page.locator(dt(testIds.signInError))).toContainText('account has been locked')
  })
})
