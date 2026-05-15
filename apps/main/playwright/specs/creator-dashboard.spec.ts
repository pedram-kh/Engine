import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * Sprint 3 Chunk 3 sub-step 12 — creator dashboard direct-access surface.
 *
 * Companion spec to `creator-wizard-happy-path.spec.ts`. The wizard
 * spec asserts the post-submit `pending` banner; this spec asserts
 * the `incomplete` branch — a creator who has signed up + signed in
 * but has not yet submitted lands on `/creator/dashboard` and sees
 * the warning-typed banner directing them back to the wizard.
 *
 * The approved / rejected branches require admin action to flip
 * `application_status`. Those are covered by Vitest component-test
 * fixtures (`CreatorDashboardPage.spec.ts`) which mount the page
 * with fabricated bootstrap state. End-to-end coverage of admin
 * approve/reject lands when Chunk 4 ships the per-field admin edit
 * surface (pause-condition-6 closure).
 *
 * Route gate: `/creator/dashboard` carries `requireAuth` only. The
 * `requireOnboardingAccess` guard on the wizard routes redirects
 * submitted/approved/rejected creators TO this page, but incomplete
 * creators can navigate here directly via the URL bar.
 */

const PASSWORD = 'Cata1yst-Dashboard-E2E!'

function uniqueEmail(): string {
  return `dashboard-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('Sprint 3 Chunk 3 — creator dashboard', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('incomplete creator landing direct on /creator/dashboard sees the incomplete banner', async ({
    page,
  }) => {
    const request = page.context().request
    const email = uniqueEmail()

    await signUpUser(request, email, PASSWORD, 'Dashboard E2E')
    await verifyEmailViaApi(request, email)

    // Sign in via the SPA UI (not a `request.post`) so the browser
    // engages Sanctum's stateful pipeline — the SPA's apiClient
    // handles the `/sanctum/csrf-cookie` handshake + `X-XSRF-TOKEN`
    // header forwarding automatically. An API-only signInViaApi
    // helper would 500 with "Session store not set on request"
    // because Sanctum's `EnsureFrontendRequestsAreStateful` only
    // injects StartSession when the Referer matches one of the
    // configured stateful domains, and Playwright's APIRequestContext
    // does not set Referer for absolute-URL POSTs.
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    await page.goto('/creator/dashboard')

    await expect(page.locator('[data-testid="creator-dashboard"]')).toBeVisible({
      timeout: 10_000,
    })
    await expect(page.locator('[data-testid="dashboard-banner-incomplete"]')).toBeVisible()

    // None of the other banners render in the incomplete branch.
    await expect(page.locator('[data-testid="dashboard-banner-pending"]')).toHaveCount(0)
    await expect(page.locator('[data-testid="dashboard-banner-approved"]')).toHaveCount(0)
    await expect(page.locator('[data-testid="dashboard-banner-rejected"]')).toHaveCount(0)
  })
})
