import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedRosterCreators,
} from '../fixtures/test-helpers'

/**
 * Agency creator roster — search + disabled-affordance E2E spec.
 * Sprint 6 Chunk 1 (D-6): resolves the Chunk-5 jsdom heavy-component
 * tech-debt by covering the REAL table DOM in Playwright (the component spec
 * stubs the heavy Vuetify table/selects freely). Exercises:
 *
 *   - the real v-data-table-server renders the seeded roster rows,
 *   - the debounced name/bio search (?q=) narrows the table — and since CI's
 *     API runs against Postgres, this is also a LIVE exercise of the FTS
 *     `to_tsvector @@ plainto_tsquery` path (the one the unit suite can only
 *     cover via a dormant markTestSkipped under SQLite),
 *   - the availability + metrics filters render DISABLED, with a hover tooltip
 *     delivered through the span-wrap idiom, and do NOT filter (D-4).
 *
 * The `/roster` route is `requireAuth → requireAgencyUser` (NOT MFA-gated), so
 * a plain agency admin (no 2FA) can reach it directly — no TOTP hop needed.
 */

test.describe('Creator roster — search + disabled affordances', () => {
  let adminEmail: string
  let adminPassword: string
  let agencyUlid: string

  test.beforeEach(async ({ page }) => {
    const request = page.context().request
    await neutralizeThrottle(request, 'auth-ip')

    const setup = await seedAgencyAdmin(request)
    adminEmail = setup.email
    adminPassword = setup.password
    agencyUlid = setup.agencyUlid

    await seedRosterCreators(request, agencyUlid, [
      { displayName: 'Ada Lovelace', bio: 'Pioneering mathematician and writer' },
      { displayName: 'Grace Hopper', bio: 'Computer scientist and rear admiral' },
    ])
  })

  test.afterEach(async ({ page }) => {
    const request = page.context().request
    await restoreThrottle(request, 'auth-ip')
  })

  test('renders the real table, narrows by search, and shows inert filter affordances', async ({
    page,
  }) => {
    // Sign in (no MFA — the roster route is not MFA-gated).
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(adminEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(adminPassword)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10000 })

    // Navigate to the roster (page.goto for determinism — Vuetify :to clicks
    // are intermittently flaky, per the invitations spec).
    await page.goto('/roster')
    const table = page.locator(dt(testIds.rosterTable))
    await expect(table).toBeVisible({ timeout: 10000 })

    // Both seeded creators render in the real table.
    await expect(table).toContainText('Ada Lovelace')
    await expect(table).toContainText('Grace Hopper')

    // Search narrows the table. A full token ('lovelace') matches under both
    // the Postgres FTS lexeme match (CI) and the SQLite ILIKE substring path.
    await page.locator(dt(testIds.rosterSearch)).locator('input').fill('lovelace')
    await expect(table).toContainText('Ada Lovelace')
    await expect(table).not.toContainText('Grace Hopper')

    // Clearing the search restores the full roster.
    await page.locator(dt(testIds.rosterSearch)).locator('input').fill('')
    await expect(table).toContainText('Ada Lovelace')
    await expect(table).toContainText('Grace Hopper')

    // Disabled affordances: each renders disabled and does NOT filter.
    for (const id of [
      testIds.rosterFollowersFilter,
      testIds.rosterEngagementFilter,
      testIds.rosterAvailabilityFilter,
    ]) {
      await expect(page.locator(dt(id))).toHaveClass(/v-input--disabled/)
    }

    // The span-wrap idiom delivers a hover tooltip on the disabled availability
    // control (a disabled control emits no hover — the wrapping <span> does).
    await page.locator(dt(testIds.rosterAvailabilityAffordance)).hover()
    await expect(page.getByText('Availability filtering is coming soon.')).toBeVisible({
      timeout: 5000,
    })

    // The availability affordance does not narrow the table — both rows remain.
    await expect(table).toContainText('Ada Lovelace')
    await expect(table).toContainText('Grace Hopper')
  })
})
