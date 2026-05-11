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
 *   6. Fast-forward 24 hours past T0. Submit 5 more failed attempts;
 *      the 5th brings the 24h long-window count to 10 (= 5 from
 *      step 5 + 5 here — step 4's success cleared the steps 2/3
 *      register, AND the 6th attempt of each of steps 2/3 + 5
 *      returned 423 from the temp-lock precheck which skips the
 *      record path entirely, so each of those steps contributes 5
 *      recorded failures, not 6). The backend escalates per
 *      `AccountLockoutService::escalate()` and the response carries
 *      `auth.account_locked.suspended` (the renamed code from
 *      chunks 6.2–6.4). The SPA renders the suspended i18n key.
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
    // Neutralise BOTH the per-email limiter (`auth-login-email`,
    // 5/min/email + IP) AND the per-IP limiter (`auth-ip`, 10/min/IP)
    // from `IdentityServiceProvider::registerRateLimits()`.
    //
    // - `auth-login-email`: needed so the application-level lockout
    //   layer (FailedLoginTracker + AccountLockoutService) is what
    //   the request graph reaches at the 6th attempt — mirrors
    //   chunk-5 `LoginTest::beforeEach`. Without this, the 6th
    //   failed login would 429 from the throttle BEFORE reaching
    //   the lockout layer.
    //
    // - `auth-ip`: needed because this spec issues ~13 failed
    //   logins + 1 successful login + 1 sign-up = ~15 auth-ip hits
    //   per attempt, distributed across multiple Carbon-pinned
    //   buckets. Even within a single bucket, three Playwright
    //   retries on failure can accumulate >20 cumulative hits in
    //   the cache between attempts (RateLimiter cache TTL is set
    //   in Carbon time → entries from a prior attempt's pinned
    //   future T0 outlive `afterEach` cleanup, since `resetClock`
    //   only restores Carbon, not the limiter cache). Without
    //   neutralising auth-ip, retry #1 of a flaked spec lands
    //   pre-saturated and surfaces the chunk-7.1 hotfix-discovery
    //   "Too many requests. Please try again in 85483 seconds."
    //   error. Pair with `restoreThrottle` in `afterEach`.
    await neutralizeThrottle(request, 'auth-login-email')
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    // Restore in inverse order. Both calls are idempotent — even if
    // the test bailed before either side-effect ran, the cleanup
    // still runs cleanly.
    await restoreThrottle(request, 'auth-ip')
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
    // 5 more failed attempts; the 5th brings the 24h long-window
    // count to 10 and the user is escalated to `is_suspended = true`.
    //
    // Why 5 attempts (not 1, not 4)
    // -----------------------------
    // Two compounding effects shape the 24h-window count entering
    // step 6:
    //
    // 1. Step 4's successful sign-in calls
    //    `AuthService::clear($email) → FailedLoginTracker::clear()`,
    //    which wipes the failures recorded by steps 2/3. So nothing
    //    from before step 4 contributes.
    //
    // 2. Steps 2/3 and step 5 each LOOK like 6 failed attempts
    //    (5 in the loop + 1 explicit), but the 6th attempt of each
    //    block hits `AuthService::login()`'s `isTemporarilyLocked`
    //    precheck and returns 423 IMMEDIATELY — the lockout
    //    short-circuit returns BEFORE the password verifier and
    //    BEFORE `recordFailureAndMaybeLock()`, so the 6th attempt
    //    does NOT add a row to the failure ledger. (The 5th
    //    attempt's `record()` call sets the temp-lock IN-LINE with
    //    its own response, so the 5th IS recorded but the response
    //    is also already 423 — see CI run 25688305242 trace.) Each
    //    block therefore contributes 5 recorded failures, not 6.
    //
    // Result: the 24h ledger entering step 6 has 5 failures (from
    // step 5, at T0 + 16m). Five attempts here bring the cumulative
    // count to 5 + 5 = 10 exactly, and the 5th attempt's
    // `recordFailureAndMaybeLock()` short-circuits the escalation
    // check (`if ($counts['long_window_count'] >= 10) { escalate;
    // return; }`) — the temp-lock branch never runs because of the
    // early return.
    //
    // Why exactly 24h, not 24h + N minutes
    // ------------------------------------
    // `FailedLoginTracker::prune()` filters timestamps by
    // `ts >= Carbon::now()->subHours(24)->getTimestamp()` — boundary
    // INCLUSIVE. At step 6's clock = T0 + 24h, the prune cutoff is
    // exactly T0; step 5's failures (at T0 + 16m) survive trivially
    // (`T0 + 16m > T0`). With ANY positive offset (e.g. the original
    // T0 + 24h + 1min), the cutoff drifts to T0 + 1min — still past
    // the cleared T0 failures, so the count math is unaffected, but
    // we keep the cleaner cutoff for clarity.
    //
    // Failure ledger at the moment the 5th attempt records:
    //   - 5 at T0 + 16m     (step 5 attempts 1-5; attempt 6 was
    //                        the 423 short-circuit, no record)
    //   - 5 at T0 + 24h     (this loop attempts 1-5)
    //   = 10 total in the (T0, T0 + 24h] window
    //
    // 10 >= LONG_WINDOW_THRESHOLD (10), so escalate() runs, sets
    // `is_suspended = true`, and the response carries
    // `auth.account_locked.suspended` ("This account has been
    // locked. Reset your password or contact support to regain
    // access.").
    //
    // The temp-lock from step 5 (cached at T0 + 16m, 15-minute TTL,
    // expires at T0 + 31m) has long since lapsed by T0 + 24h, so
    // each attempt here passes the `isLocked` precheck and records
    // a fresh failure timestamp.
    // -----------------------------------------------------------------
    await setClock(request, clockAtMinutes(24 * 60))

    for (let i = 0; i < 4; i += 1) {
      await attemptFailedSignIn(page, email)
    }
    await attemptFailedSignIn(page, email)

    // The chunk-6.2-6.4 rename: long-window escalation now responds
    // with `auth.account_locked.suspended`. The bundle entry is
    // "This account has been locked. Reset your password or contact
    // support to regain access." We assert on a stable substring.
    await expect(page.locator(dt(testIds.signInError))).toContainText('account has been locked')
  })
})
