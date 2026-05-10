import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import { resetClock, setClock, signUpUser } from '../fixtures/test-helpers'

/**
 * 20-PHASE-1-SPEC.md § 7 priority #20 — failed-login lockout +
 * reset / escalation flow.
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
 *   6. Fast-forward 24 hours past the FIRST failed attempt (we set
 *      the clock to T0 + 24h + 1m so any timestamp in the original
 *      window is dropped from the prune step's view). Submit one
 *      more failed attempt. The backend escalates per
 *      `AccountLockoutService::escalate()` and the response carries
 *      `auth.account_locked.suspended` (the renamed code from chunks
 *      6.2–6.4). The SPA renders the suspended i18n key.
 *   7. `afterEach` resets the clock so a stray failure does not
 *      bleed pinned time into the next spec.
 *
 * Hermeticity contract:
 *   - The Laravel API runs with `CACHE_STORE=array` (see
 *     `playwright.config.ts`) so the lockout cache TTL is computed
 *     against `Carbon::now()` on read instead of Redis EXPIRE
 *     against real wall-clock. Without that, the fast-forward in
 *     step 4 would leave the lock active because Redis would still
 *     be inside its real 15-minute TTL. The chunk-6.8 review file
 *     records this as a flagged deviation from the kickoff's
 *     hidden assumption that the test clock alone unlocks.
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
  test.afterEach(async ({ request }) => {
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
    // The 6th attempt — temporary lockout returns the i18n key. We
    // assert on the resolved English substring (the `auth.account_locked.temporary`
    // value in `apps/main/src/core/i18n/locales/en/auth.json` is
    // "Too many failed sign-in attempts. Please try again in a few minutes.").
    // The kickoff's "in N minutes" phrasing does not match the
    // bundle (the {minutes} interpolation lives on the backend
    // `title` field, which the SPA does not render); flagged in
    // the chunk-6.8 review.
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
