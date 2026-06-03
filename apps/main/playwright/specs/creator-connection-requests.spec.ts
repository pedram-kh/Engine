import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedPendingConnectionRequest,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * Sprint 6.6c — the creator-side connection-request inbox round-trip.
 *
 * Closes the discovery sprint end-to-end from the CREATOR's surface: an
 * approved creator with a seeded `pending_request` lands on
 * `/creator/dashboard`, sees the incoming agency request, accepts it, and the
 * row disappears with a snackbar naming the agency.
 *
 * The approved branch is the ONLY place the inbox renders, and no production
 * path approves a self-signed-up creator (admin-only) or sends a request from
 * an agency the spec controls — so the net-new `seedPendingConnectionRequest`
 * test-helper (a gated `App\TestHelpers` endpoint) approves the creator + seeds
 * the relation in one call. See its fixture docblock for the design context.
 *
 * Sign-in goes through the SPA UI (not an API helper) so the browser engages
 * Sanctum's stateful pipeline — the same reasoning documented at length in
 * `creator-dashboard.spec.ts`.
 */

const PASSWORD = 'Cata1yst-Inbox-E2E!'
const AGENCY_NAME = 'Aurora Collective'

function uniqueEmail(): string {
  return `inbox-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('Sprint 6.6c — creator connection-request inbox', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('an approved creator accepts a seeded request → the row clears + a toast names the agency', async ({
    page,
  }) => {
    const request = page.context().request
    const email = uniqueEmail()

    await signUpUser(request, email, PASSWORD, 'Inbox E2E')
    await verifyEmailViaApi(request, email)

    // Sign in through the SPA UI so Sanctum's stateful pipeline engages.
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    // Approve the creator + seed the incoming pending request (the inbox is
    // approved-only). Identified by email — no session dependency.
    const { relationUlid } = await seedPendingConnectionRequest(request, email, AGENCY_NAME)

    await page.goto('/creator/dashboard')

    await expect(page.locator('[data-testid="creator-dashboard"]')).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('[data-testid="dashboard-banner-approved"]')).toBeVisible()

    // The inbox renders the seeded request, naming the agency.
    const section = page.locator('[data-testid="dashboard-requests"]')
    await expect(section).toBeVisible()
    const row = page.locator(`[data-testid="dashboard-request-${relationUlid}"]`)
    await expect(row).toBeVisible()
    await expect(row).toContainText(AGENCY_NAME)

    // Accept → the row clears (re-fetch drops it from the pending set) and the
    // snackbar names the agency.
    await page.locator(`[data-testid="dashboard-request-accept-${relationUlid}"]`).click()

    await expect(row).toHaveCount(0, { timeout: 10_000 })
    await expect(page.locator('[data-testid="dashboard-requests-snackbar"]')).toContainText(
      AGENCY_NAME,
    )
  })
})
