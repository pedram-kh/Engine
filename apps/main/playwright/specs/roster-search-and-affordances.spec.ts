import { expect, test } from '../fixtures/test'

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
 *   - the METRICS filters render DISABLED, with a hover tooltip delivered
 *     through the span-wrap idiom, and do NOT filter (D-4),
 *   - the availability range filter (Sprint 6.5, D-6) renders as TWO ENABLED
 *     native date inputs (no longer the old disabled affordance) and threads
 *     a window through the live stack without error.
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

    // Metrics affordances: each renders disabled and does NOT filter (D-4).
    for (const id of [testIds.rosterFollowersFilter, testIds.rosterEngagementFilter]) {
      await expect(page.locator(dt(id))).toHaveClass(/v-input--disabled/)
    }

    // The span-wrap idiom delivers a hover tooltip on the disabled metrics
    // control (a disabled control emits no hover — the wrapping <span> does).
    // Both metrics affordances (followers + engagement) share this tooltip
    // text, so intersect with `:visible` to target the one the hover opened
    // (avoids the strict-mode violation of two matching text nodes in the DOM).
    await page.locator(dt(testIds.rosterFollowersAffordance)).hover()
    await expect(
      page.getByText("Social metrics aren't connected yet.").and(page.locator(':visible')),
    ).toBeVisible({ timeout: 5000 })

    // Availability range filter (Sprint 6.5, D-6): the real control is two
    // ENABLED native date inputs — NOT the old disabled affordance.
    const fromInput = page.locator(dt(testIds.rosterAvailableFrom)).locator('input')
    const toInput = page.locator(dt(testIds.rosterAvailableTo)).locator('input')
    await expect(fromInput).toBeEnabled()
    await expect(toInput).toBeEnabled()

    // Threading a window through the live stack: neither seeded creator has a
    // hard block, so both remain available (the filter is accepted end-to-end
    // — no 422/500, the table reloads with both rows). The filtering CORRECTNESS
    // (hard excludes / soft includes / recurrence / counts) is pinned by the
    // backend feature suite (AgencyCreatorRosterTest).
    await fromInput.fill('2026-06-08')
    await toInput.fill('2026-06-12')
    await expect(table).toContainText('Ada Lovelace')
    await expect(table).toContainText('Grace Hopper')
  })

  // AH-096: the roster wrapper (`roster.api.ts`) silently dropped `age_min`/
  // `age_max` from the query string — a filter set on the page never reached
  // the backend. `CreatorRosterPage.spec.ts` couldn't catch it (it mocks the
  // wrapper); this is the first REAL browser exercise of the age filter,
  // birthday-to-table-row, closing that gap for good.
  test('age-range filter narrows the table to creators whose age falls in the bound', async ({
    page,
  }) => {
    const currentYear = new Date().getFullYear()
    // January 1st births: by any day in the seed's own year, the birthday has
    // already passed, so the whole-years-old math is stable regardless of
    // what day this suite happens to run.
    await seedRosterCreators(page.context().request, agencyUlid, [
      { displayName: 'Young Yara', dateOfBirth: `${currentYear - 6}-01-01` },
      { displayName: 'Grown Greg', dateOfBirth: `${currentYear - 10}-01-01` },
    ])

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(adminEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(adminPassword)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10000 })

    await page.goto('/roster')
    const table = page.locator(dt(testIds.rosterTable))
    await expect(table).toBeVisible({ timeout: 10000 })
    await expect(table).toContainText('Young Yara')
    await expect(table).toContainText('Grown Greg')

    // 4–7 catches the 6-year-old, excludes the 10-year-old — and, per the
    // backend's "unknown age can't honestly satisfy a range" rule
    // (`FiltersCreatorColumns`, AH-088), also excludes the two no-DOB
    // creators seeded in `beforeEach` (Ada Lovelace, Grace Hopper).
    await page.locator(dt(testIds.rosterAgeMin)).locator('input').fill('4')
    await page.locator(dt(testIds.rosterAgeMax)).locator('input').fill('7')

    await expect(table).toContainText('Young Yara')
    await expect(table).not.toContainText('Grown Greg')
    await expect(table).not.toContainText('Ada Lovelace')
    await expect(table).not.toContainText('Grace Hopper')

    // Clearing both bounds restores the full roster — proves the narrowing
    // above was the filter, not a coincidental page/search state.
    await page.locator(dt(testIds.rosterAgeMin)).locator('input').fill('')
    await page.locator(dt(testIds.rosterAgeMax)).locator('input').fill('')
    await expect(table).toContainText('Young Yara')
    await expect(table).toContainText('Grown Greg')
    await expect(table).toContainText('Ada Lovelace')
    await expect(table).toContainText('Grace Hopper')
  })
})
