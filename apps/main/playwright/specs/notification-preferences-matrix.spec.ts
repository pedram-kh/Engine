import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedListedJob,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * AH-101 — the notification preference matrix, against the real API.
 *
 * The first Playwright walk for this page. Unit specs pin the matrix's SHAPE
 * (which cells exist, how columns derive); what they cannot pin is the loop that
 * makes the email column mean something: a switch flipped in the browser reaches
 * the API as an `email=false` row, comes back from the API on reload as that
 * exact state, and is the row the backend guard reads to stop one mail. This leg
 * runs the browser half of that loop end to end — save → reload → still off —
 * through the real `/me/notification-preferences` endpoints (the sparse-row
 * reconciliation included), which a mocked `notificationsApi` cannot.
 *
 * Desktop project only: this asserts the web GRID. The native stacked-card layout
 * is covered by the unit spec and by Pedram's device walk.
 */

const PASSWORD = 'Cata1yst-PrefsMatrix-E2E!'

function uniqueEmail(): string {
  return `prefs-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

const JOB_POSTED_EMAIL = '[data-test="prefs-toggle-campaign.job_posted-email"]'
const JOB_POSTED_IN_APP = '[data-test="prefs-toggle-campaign.job_posted-in_app"]'

test.describe('AH-101 — notification preference matrix', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('renders the grid, persists an email opt-out across a reload, and leaves always-on alone', async ({
    page,
  }) => {
    const request = page.context().request
    const email = uniqueEmail()

    await signUpUser(request, email, PASSWORD, 'Prefs Matrix E2E')
    await verifyEmailViaApi(request, email)
    await seedListedJob(request, email, {
      campaignName: 'Prefs matrix job',
      brandName: 'Northwind Coffee',
      listingFee: '€200 per video',
      listingDuration: '2 weeks',
      description: 'Seeded so the creator is approved and rostered.',
    })

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    await page.goto('/creator/notifications/preferences')
    await expect(page.locator('[data-test="prefs-form"]')).toBeVisible({ timeout: 10_000 })

    // ── The matrix: a grid with derived columns, not the old single list. ────
    await expect(page.locator('[data-test="prefs-grid-jobs_board"]')).toBeVisible()
    await expect(
      page.locator('[data-test="prefs-grid-jobs_board"] [data-test="prefs-column-email"]'),
    ).toBeVisible()
    // Digest is the Messages group's column alone.
    await expect(
      page.locator('[data-test="prefs-grid-jobs_board"] [data-test="prefs-column-digest"]'),
    ).toHaveCount(0)
    await expect(
      page.locator('[data-test="prefs-grid-messaging"] [data-test="prefs-column-digest"]'),
    ).toBeVisible()

    // Account-lifecycle mail is shown, and cannot be switched.
    await expect(page.locator('[data-test="prefs-always-on-creator.approved-email"]')).toHaveText(
      'Always on',
    )
    await expect(page.locator('[data-test="prefs-toggle-creator.approved-email"]')).toHaveCount(0)

    // The production default: no rows → every email cell is ON.
    const emailInput = page.locator(`${JOB_POSTED_EMAIL} input`)
    const inAppInput = page.locator(`${JOB_POSTED_IN_APP} input`)
    await expect(emailInput).toBeChecked()
    await expect(inAppInput).toBeChecked()

    // ── Opt out of ONE email, save, and reload through the real API. ─────────
    await emailInput.uncheck()
    await page.locator('[data-test="prefs-save-btn"]').click()
    await expect(page.locator('[data-test="prefs-success"]')).toBeVisible({ timeout: 10_000 })

    await page.reload()
    await expect(page.locator('[data-test="prefs-form"]')).toBeVisible({ timeout: 10_000 })

    // The stored email=false row survived the round trip; its sibling channel
    // and every other type's email are untouched (exactly one cell moved).
    await expect(page.locator(`${JOB_POSTED_EMAIL} input`)).not.toBeChecked()
    await expect(page.locator(`${JOB_POSTED_IN_APP} input`)).toBeChecked()
    await expect(
      page.locator('[data-test="prefs-toggle-campaign_application.accepted-email"] input'),
    ).toBeChecked()

    // ── And it can be turned back on (return-to-default deletes the row). ────
    await page.locator(`${JOB_POSTED_EMAIL} input`).check()
    await page.locator('[data-test="prefs-save-btn"]').click()
    await expect(page.locator('[data-test="prefs-success"]')).toBeVisible({ timeout: 10_000 })
    await page.reload()
    await expect(page.locator(`${JOB_POSTED_EMAIL} input`)).toBeChecked({ timeout: 10_000 })
  })
})
