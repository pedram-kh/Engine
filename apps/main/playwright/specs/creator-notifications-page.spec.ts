import type { Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedContractedAssignment,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * AH-115 L4 — the creator Notifications page has a real header (title and a settings button) above
 * the notification card, and a notification the agency caused is listed in it.
 *
 *   1. The agency sends a campaign message through the real API: the creator gets a notification.
 *   2. `/creator/notifications` renders the header, the settings button, and a row.
 *   3. The settings button opens the notification preferences.
 */

const PASSWORD = 'Cata1yst-CreatorNotif-E2E!'
const API = 'http://127.0.0.1:8000/api/v1'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

async function xsrfHeaders(page: Page): Promise<Record<string, string>> {
  const cookies = await page.context().cookies()
  const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN')
  return {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    Referer: 'http://127.0.0.1:5173/',
    'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.value) : '',
  }
}

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 15_000 })
}

test.describe('AH-115 L4 — the creator Notifications page', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await restoreThrottle(page.context().request, 'auth-ip')
    await signOutViaApi(page.context().request)
  })

  test('a header with a settings button above a list that holds the agency message', async ({
    page,
  }) => {
    test.setTimeout(180_000)
    const request = page.context().request
    const creatorEmail = uniqueEmail('creator-notif')

    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, PASSWORD, 'Notif Creator')
    await verifyEmailViaApi(request, creatorEmail)
    const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail)

    await signIn(page, admin.email, admin.password)
    const sent = await request.post(
      `${API}/agencies/${seeded.agencyUlid}/campaigns/${seeded.campaignUlid}/assignments/${seeded.assignmentUlid}/messages`,
      { headers: await xsrfHeaders(page), data: { body: 'Please confirm the shoot date.' } },
    )
    expect(sent.status(), await sent.text()).toBe(201)
    await signOutViaApi(request)

    await signIn(page, creatorEmail, PASSWORD)
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/creator/notifications')

    await expect(page.locator('[data-test="creator-notifications-title"]')).toBeVisible({
      timeout: 15_000,
    })
    await expect(page.locator('[data-test="creator-notifications-settings"]')).toBeVisible()
    await expect(
      page.locator('[data-test="notification-row"], [data-test^="notification-row"]').first(),
    ).toBeVisible({
      timeout: 15_000,
    })

    await page.locator('[data-test="creator-notifications-settings"]').click()
    await expect(page).toHaveURL(/\/creator\/notifications\/preferences/)
  })
})
