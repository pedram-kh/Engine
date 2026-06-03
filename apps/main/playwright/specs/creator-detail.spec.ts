import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedRosterCreators,
} from '../fixtures/test-helpers'

/**
 * Agency per-creator detail view — E2E (Sprint 6 Chunk 2a).
 *
 * Covers the heavy detail-page DOM + the live availability calendar (CMonthGrid)
 * that the component spec deliberately stubs (per the Chunk-1 jsdom/Playwright
 * split). Exercises:
 *
 *   - row-click navigation from the real roster table → /roster/:ulid (D-2a-6),
 *   - the re-composed profile + the surfaced contact email (D-2a-8),
 *   - the rating/notes EDITOR round-trip (admin) → success snackbar (D-2a-3/4),
 *   - the read-only availability calendar rendering via CMonthGrid (D-2a-9),
 *   - the two blocked sections rendering honest empty states (D-2a-10).
 *
 * The `/roster/:ulid` route is `requireAuth → requireAgencyUser` (NOT MFA-gated),
 * so a plain agency admin reaches it directly.
 */

test.describe('Creator detail view', () => {
  let adminEmail: string
  let adminPassword: string
  let agencyUlid: string
  let creatorUlid: string

  test.beforeEach(async ({ page }) => {
    const request = page.context().request
    await neutralizeThrottle(request, 'auth-ip')

    const setup = await seedAgencyAdmin(request)
    adminEmail = setup.email
    adminPassword = setup.password
    agencyUlid = setup.agencyUlid

    const seeded = await seedRosterCreators(request, agencyUlid, [
      {
        displayName: 'Ada Lovelace',
        bio: 'Pioneering mathematician and writer',
        countryCode: 'GB',
        primaryLanguage: 'en',
      },
    ])
    creatorUlid = seeded.relations[0]!.creatorUlid

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(adminEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(adminPassword)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10000 })
  })

  test.afterEach(async ({ page }) => {
    await restoreThrottle(page.context().request, 'auth-ip')
  })

  test('navigates from a roster row and renders the composed detail', async ({ page }) => {
    await page.goto('/roster')
    const table = page.locator(dt(testIds.rosterTable))
    await expect(table).toBeVisible({ timeout: 10000 })

    // Row-click → detail route (D-2a-6).
    await table.getByText('Ada Lovelace').click()
    await expect(page).toHaveURL(new RegExp(`/roster/${creatorUlid}$`))

    await expect(page.locator('[data-test="creator-detail-name"]')).toHaveText('Ada Lovelace')

    // Contact email surfaced as a mailto link (D-2a-8).
    await expect(page.locator('[data-test="creator-detail-email"]')).toHaveAttribute(
      'href',
      /^mailto:/,
    )

    // Read-only availability calendar renders via CMonthGrid (D-2a-9), and with
    // no seeded blocks shows its empty state — the grid itself still renders.
    await expect(page.locator('[data-test="agency-availability-grid"]')).toBeVisible()
    await expect(page.locator('[data-test="agency-availability-empty"]')).toBeVisible()

    // Blocked sections → honest empty states (D-2a-10).
    await expect(page.locator('[data-test="creator-detail-metrics-empty"]')).toBeVisible()
    await expect(page.locator('[data-test="creator-detail-campaigns-empty"]')).toBeVisible()
  })

  test('admin edits rating + notes and sees the changes persist', async ({ page }) => {
    await page.goto(`/roster/${creatorUlid}`)
    await expect(page.locator('[data-test="creator-detail-name"]')).toHaveText('Ada Lovelace')

    // Set a 5-star rating + a note, then save.
    await page.locator('[data-test="creator-detail-rating-star-5"]').click()
    await page
      .locator('[data-test="creator-detail-notes"]')
      .locator('textarea')
      .first()
      .fill('Excellent collaborator')
    await page.locator('[data-test="creator-detail-save"]').click()

    await expect(page.locator('[data-test="creator-detail-saved"]')).toBeVisible({ timeout: 10000 })

    // Reload — the edits round-tripped through the PATCH and re-render.
    await page.reload()
    await expect(page.locator('[data-test="creator-detail-name"]')).toHaveText('Ada Lovelace')
    await expect(
      page.locator('[data-test="creator-detail-notes"]').locator('textarea').first(),
    ).toHaveValue('Excellent collaborator')
    await expect(page.locator('[data-test="creator-detail-rating-star-5"]')).toHaveAttribute(
      'aria-checked',
      'true',
    )
  })
})
