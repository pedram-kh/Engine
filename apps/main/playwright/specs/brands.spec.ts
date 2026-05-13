import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  signOutViaApi,
} from '../fixtures/test-helpers'

/**
 * Brand happy-path E2E spec.
 *
 * Acceptance scenario (20-PHASE-1-SPEC.md § 5):
 *   - agency_admin signs in
 *   - Sees the AgencyLayout shell
 *   - Navigates to /brands
 *   - Creates a brand
 *   - Sees it in the list
 *   - Opens detail page
 *   - Edits the brand
 *   - Archives the brand
 *
 * Chunk-7.1 conventions (all applied from first commit):
 *   - auth-ip rate-limiter neutralised in beforeEach; restored in afterEach
 *   - No parent data-test attribute fall-through
 *   - Spec-local `seedAgencyAdmin` fixture follows chunk-7.6 pattern
 */

test.describe('Brand happy path', () => {
  let adminEmail: string
  let adminPassword: string
  let agencyUlid: string

  test.beforeEach(async ({ page }) => {
    const request = page.context().request

    // Neutralise auth-ip rate limiter per chunk-7.1 conventions.
    await neutralizeThrottle(request, 'auth-ip')

    // Seed an agency admin + agency in a single test-helper call.
    const setup = await seedAgencyAdmin(request)
    adminEmail = setup.email
    adminPassword = setup.password
    agencyUlid = setup.agencyUlid
  })

  test.afterEach(async ({ page }) => {
    const request = page.context().request
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('agency_admin can navigate brands list, create, detail, edit, archive', async ({ page }) => {
    // ── Sign in ──────────────────────────────────────────────────────────────
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).fill(adminEmail)
    await page.locator(dt(testIds.signInPassword)).fill(adminPassword)
    await page.locator(dt(testIds.signInSubmit)).click()

    // ── Verify AgencyLayout rendered ─────────────────────────────────────────
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()
    await expect(page.locator(dt(testIds.agencySidebar))).toBeVisible()
    await expect(page.locator(dt(testIds.agencyTopbar))).toBeVisible()

    // ── Navigate to brands ───────────────────────────────────────────────────
    await page.locator(dt(testIds.navBrands)).click()
    await expect(page.locator(dt(testIds.brandListPage))).toBeVisible()
    await expect(page.locator(dt(testIds.brandListHeading))).toBeVisible()

    // Empty state should appear (no brands yet).
    await expect(page.locator(dt(testIds.brandEmptyState))).toBeVisible()

    // ── Create a brand ────────────────────────────────────────────────────────
    await page.locator(dt(testIds.brandEmptyCta)).click()
    await expect(page.locator(dt(testIds.brandCreatePage))).toBeVisible()

    await page.locator(dt(testIds.brandName)).fill('Acme Brand')
    // Trigger slug auto-suggestion.
    await page.locator(dt(testIds.brandName)).blur()

    await page.locator(dt(testIds.brandFormSubmit)).click()

    // Should redirect to detail page after create.
    await expect(page.locator(dt(testIds.brandDetailPage))).toBeVisible({ timeout: 8000 })
    await expect(page.locator(dt(testIds.brandDetailCard))).toContainText('Acme Brand')

    // ── Verify detail page ────────────────────────────────────────────────────
    await expect(page.locator(dt(testIds.brandDetailStatus))).toContainText('Active')

    // ── Edit the brand ────────────────────────────────────────────────────────
    await page.locator(dt(testIds.brandEditBtn)).click()
    await expect(page.locator(dt(testIds.brandEditPage))).toBeVisible()

    // Update the brand name.
    await page.locator(dt(testIds.brandName)).fill('Acme Brand Updated')
    await page.locator(dt(testIds.brandFormSubmit)).click()

    // Should redirect back to detail page.
    await expect(page.locator(dt(testIds.brandDetailPage))).toBeVisible({ timeout: 8000 })
    await expect(page.locator(dt(testIds.brandDetailCard))).toContainText('Acme Brand Updated')

    // ── Navigate to brand list and verify it appears ──────────────────────────
    await page.locator(dt(testIds.navBrands)).click()
    await expect(page.locator(dt(testIds.brandTable))).toBeVisible()
    await expect(page.locator(dt(testIds.brandTable))).toContainText('Acme Brand Updated')

    // ── Archive the brand ─────────────────────────────────────────────────────
    // Get the ULID from the page URL and navigate to detail.
    await page.locator(`[data-test^="brand-view-"]`).first().click()
    await expect(page.locator(dt(testIds.brandDetailPage))).toBeVisible({ timeout: 8000 })

    await page.locator(dt(testIds.brandArchiveBtn)).click()
    await expect(page.locator(dt(testIds.brandDetailArchiveDialog))).toBeVisible()
    await page.locator(dt(testIds.brandDetailArchiveConfirm)).click()

    // Should redirect to brands list after archiving.
    await expect(page.locator(dt(testIds.brandListPage))).toBeVisible({ timeout: 8000 })

    // Switch to "all" filter and verify the brand is now archived.
    await page.locator(`[data-test="brand-filter-all"]`).click()
    await expect(page.locator(dt(testIds.brandTable))).toContainText('Archived')
  })
})
