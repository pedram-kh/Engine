import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedRosterCreators,
} from '../fixtures/test-helpers'

/**
 * Talent pools — E2E (Sprint 6 Chunk 2b).
 *
 * Covers the full usable feature against real backend rows (per the Chunk-1
 * jsdom/Playwright split, the heavy DOM + navigation lives here):
 *
 *   - the pools pages: empty state → create → list shows the COUNT (D-2b-7),
 *   - the add-to-pool round-trip on the 2a creator detail page (D-2b-9): the
 *     header button → picker dialog → toggle on → the creator lands in the
 *     pool's member roster on the pool detail page.
 *
 * Both `/talent-pools*` and `/roster/:ulid` are `requireAuth → requireAgencyUser`
 * (NOT MFA-gated), so a plain agency admin reaches them directly.
 */

test.describe('Talent pools', () => {
  let agencyUlid: string
  let creatorUlid: string

  test.beforeEach(async ({ page }) => {
    const request = page.context().request
    await neutralizeThrottle(request, 'auth-ip')

    const setup = await seedAgencyAdmin(request)
    agencyUlid = setup.agencyUlid

    const seeded = await seedRosterCreators(request, agencyUlid, [
      { displayName: 'Ada Lovelace', countryCode: 'GB', primaryLanguage: 'en' },
    ])
    creatorUlid = seeded.relations[0]!.creatorUlid

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(setup.email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(setup.password)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10000 })
  })

  test.afterEach(async ({ page }) => {
    await restoreThrottle(page.context().request, 'auth-ip')
  })

  test('creates a pool from the empty state and lists it with a member count', async ({ page }) => {
    await page.goto('/talent-pools')

    // Empty state on a fresh agency.
    await expect(page.locator('[data-test="pool-empty-state"]')).toBeVisible({ timeout: 10000 })

    await page.locator('[data-test="pool-empty-cta"]').click()
    await expect(page.locator('[data-test="pool-create-page"]')).toBeVisible()

    await page.locator('[data-test="pool-name"]').locator('input').fill('Acme Q3')
    await page.locator('[data-test="pool-form-submit"]').click()

    // Lands on the new pool's detail page with a zero-member roster.
    await expect(page.locator('[data-test="pool-detail-name"]')).toHaveText('Acme Q3', {
      timeout: 10000,
    })
    await expect(page.locator('[data-test="pool-members-empty"]')).toBeVisible()

    // The list shows the pool with its COUNT, not a member preview (D-2b-7).
    await page.goto('/talent-pools')
    const table = page.locator('[data-test="pool-table"]')
    await expect(table).toBeVisible({ timeout: 10000 })
    await expect(table.getByText('Acme Q3')).toBeVisible()
  })

  test('adds a creator to a pool from the detail page and the pool roster reflects it', async ({
    page,
  }) => {
    // Create a pool first (via the UI).
    await page.goto('/talent-pools/new')
    await page.locator('[data-test="pool-name"]').locator('input').fill('Spring Campaign')
    await page.locator('[data-test="pool-form-submit"]').click()
    await expect(page.locator('[data-test="pool-detail-name"]')).toHaveText('Spring Campaign', {
      timeout: 10000,
    })
    const poolUrl = page.url()

    // Open the creator detail page and add them to the pool via the picker.
    await page.goto(`/roster/${creatorUlid}`)
    await expect(page.locator('[data-test="creator-detail-name"]')).toHaveText('Ada Lovelace', {
      timeout: 10000,
    })

    await page.locator('[data-test="creator-detail-add-to-pool"]').click()
    await expect(page.locator('[data-test="add-to-pool-dialog"]')).toBeVisible()
    await expect(page.locator('[data-test="add-to-pool-list"]')).toBeVisible()

    // Toggle the single pool ON → success snackbar.
    await page.locator('[data-test^="add-to-pool-toggle-"]').first().click()
    await expect(page.locator('[data-test="creator-detail-pool-snackbar"]')).toBeVisible({
      timeout: 10000,
    })
    await page.locator('[data-test="add-to-pool-done"]').click()

    // The pool detail page now lists the creator as a member.
    await page.goto(poolUrl)
    await expect(page.locator('[data-test="pool-detail-name"]')).toHaveText('Spring Campaign')
    await expect(page.locator('[data-test="pool-members-list"]')).toContainText('Ada Lovelace')

    // And the list count reflects the one member.
    await page.goto('/talent-pools')
    const table = page.locator('[data-test="pool-table"]')
    await expect(table).toBeVisible({ timeout: 10000 })
    await expect(table.getByText('Spring Campaign')).toBeVisible()
  })

  test('adds a roster creator to a pool from the POOL page and the roster + count reflect it', async ({
    page,
  }) => {
    // Create an empty pool and stay on its detail page.
    await page.goto('/talent-pools/new')
    await page.locator('[data-test="pool-name"]').locator('input').fill('Summer Push')
    await page.locator('[data-test="pool-form-submit"]').click()
    await expect(page.locator('[data-test="pool-detail-name"]')).toHaveText('Summer Push', {
      timeout: 10000,
    })
    await expect(page.locator('[data-test="pool-members-empty"]')).toBeVisible()

    // Open the pool-side picker, pick the rostered creator not yet in the pool.
    await page.locator('[data-test="pool-detail-add-creators"]').click()
    await expect(page.locator('[data-test="add-creators-dialog"]')).toBeVisible()
    await expect(page.locator(`[data-test="add-creators-row-${creatorUlid}"]`)).toBeVisible({
      timeout: 10000,
    })

    await page.locator(`[data-test="add-creators-checkbox-${creatorUlid}"]`).click()
    await page.locator('[data-test="add-creators-submit"]').click()

    // Success snackbar, then the member roster + the count reflect the add.
    await expect(page.locator('[data-test="pool-detail-snackbar"]')).toBeVisible({ timeout: 10000 })
    await expect(page.locator('[data-test="pool-members-list"]')).toContainText('Ada Lovelace')
    await expect(page.locator('[data-test="pool-detail-count"]')).toContainText('1')
  })
})
