import type { Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedListableCampaign,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * AH-103 (C3.3) — a tap on an in-app notification lands on the exact screen,
 * end to end against a real API, on the WEB build.
 *
 * What the unit specs cannot see, and this walks: a notification written by the
 * REAL emit path (a creator applying through the UI → `CampaignApplicationNotifier`
 * → a persisted row carrying `campaign_ulid`), read back through the real feed
 * endpoint, rendered by `NotificationCenter` in the agency app-bar's bell, and a
 * click that navigates to `/campaigns/:ulid?tab=applications` with the
 * Applications tab actually OPEN — not merely the right URL. The agency bell is
 * the surface that had no navigation at all before this chunk (the creator app's
 * push tap is device-walked, not E2E'd: nothing here exercises native).
 *
 * Provisioning mirrors `jobs-board-full-lifecycle` (the only real apply path):
 * a real creator account, an agency admin who lists the campaign from the list
 * page, the creator applying. Assertions anchor on `data-test` attributes, never
 * on translated copy.
 */

const CREATOR_PASSWORD = 'Cata1yst-DeepLink-E2E!'
const CAMPAIGN_NAME = 'Deep-link UGC push'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

async function signInAsAgency(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 15_000 })
}

async function signInAsCreator(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 15_000 })
}

test.describe('AH-103 — a notification opens the screen it is about (web, agency bell)', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await restoreThrottle(page.context().request, 'auth-ip')
    await signOutViaApi(page.context().request)
  })

  test('a creator applies; the agency clicks the bell row and lands on the campaign, Applications tab open', async ({
    page,
  }) => {
    test.setTimeout(120_000)

    const request = page.context().request
    const creatorEmail = uniqueEmail('deeplink-creator')

    // ── Provisioning ────────────────────────────────────────────────────────
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, CREATOR_PASSWORD, 'Deep Link')
    await verifyEmailViaApi(request, creatorEmail)
    const seeded = await seedListableCampaign(request, admin.agencyUlid, creatorEmail, {
      campaignName: CAMPAIGN_NAME,
    })

    // ── The agency lists the campaign (the creator can only apply to a listed job)
    await signInAsAgency(page, admin.email, admin.password)
    await page.goto('/campaigns')
    const toggle = page.locator(`[data-test="campaign-job-board-toggle-${seeded.campaignUlid}"]`)
    await expect(toggle).toBeVisible({ timeout: 15_000 })
    await toggle.locator('input').click()
    await page.locator('[data-test="campaign-listing-confirm-submit"]').click()
    await expect(toggle.locator('input')).toBeChecked({ timeout: 10_000 })

    // ── The creator applies — the REAL emit that writes the agency's notification
    await signOutViaApi(request)
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)
    await page.goto(`/creator/jobs/${seeded.campaignUlid}`)
    await page.locator('[data-testid="creator-job-apply"]').click({ timeout: 15_000 })
    await page
      .locator('[data-testid="creator-job-apply-note"]')
      .locator('textarea')
      .first()
      .fill('I shoot food content weekly.')
    await page.locator('[data-testid="creator-job-apply-submit"]').click()
    await expect(page.locator('[data-testid="creator-job-applied-notice"]')).toBeVisible({
      timeout: 10_000,
    })

    // ── The agency opens the bell from a page that is NOT the campaign ──────
    await signOutViaApi(request)
    await signInAsAgency(page, admin.email, admin.password)
    await page.goto('/')
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 15_000 })

    await page.locator('[data-test="notification-bell-btn"]').click()
    const row = page.locator('[data-test~="notification-row--unread"]').first()
    await expect(row).toBeVisible({ timeout: 15_000 })

    // ── The click: the right campaign, the right tab, actually open ─────────
    await row.click()

    await expect(page).toHaveURL(
      new RegExp(`/campaigns/${seeded.campaignUlid}\\?tab=applications$`),
      { timeout: 10_000 },
    )
    await expect(page.locator('[data-test="applications-list"]')).toBeVisible({ timeout: 15_000 })

    // The dropdown closed with the route change, and the row it came from is read
    // (the PATCH ran alongside the navigation, not before it).
    await expect(page.locator('[data-test="notification-dropdown-card"]')).toBeHidden()
    await page.locator('[data-test="notification-bell-btn"]').click()
    await expect(page.locator('[data-test~="notification-row--unread"]')).toHaveCount(0, {
      timeout: 10_000,
    })
  })
})
