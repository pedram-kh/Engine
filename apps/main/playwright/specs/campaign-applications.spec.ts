import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedPendingApplications,
} from '../fixtures/test-helpers'

/**
 * Jobs Board chunk 4 (AH-058, D8) — the AGENCY half of the applications loop,
 * end to end against a real API.
 *
 * The component specs mount the tab with a mocked client and the feature tests
 * drive the endpoints with no browser. What neither can see is the seam this leg
 * walks: the eighth tab and its pending-only badge, the two answers travelling
 * through the real client, and — the part that is free machinery and therefore
 * the part most worth pinning — the accepted applicant arriving on the campaign
 * BOARD as an ordinary invited assignment. D1's claim that "the board already
 * handles post-accept for free" is asserted here rather than asserted in prose.
 *
 * Provisioning goes through `_test/agencies/{agency}/pending-applications` — an
 * agency-keyed sibling of the c3 leg's creator-keyed helper (see that
 * controller's docblock for why it could not simply be extended).
 *
 * `/campaigns/:ulid` is `requireAuth → requireAgencyUser` (NOT MFA-gated), so a
 * plain agency admin reaches it without a TOTP hop. Assertions anchor on
 * `data-test` attributes, never on translated copy; the one text assertion is
 * the board COLUMN NAME, which is seeded database content (`BoardDefaults`), not
 * an i18n string.
 */

test.describe('AH-058 — agency answers job applications', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await restoreThrottle(page.context().request, 'auth-ip')
  })

  test('the agency rejects one applicant, accepts another, and the accept lands on the board', async ({
    page,
  }) => {
    const request = page.context().request

    const admin = await seedAgencyAdmin(request)
    const seeded = await seedPendingApplications(
      request,
      admin.agencyUlid,
      [
        { displayName: 'Ada Lovelace', note: 'I shoot food content weekly.' },
        { displayName: 'Grace Hopper' },
      ],
      { campaignName: 'Autumn UGC push', brandName: 'Northwind Coffee' },
    )

    const [rejected, accepted] = seeded.applications

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(admin.email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(admin.password)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10_000 })

    // ── The tab + the badge ─────────────────────────────────────────────────
    // The badge is loaded on MOUNT, not on tab open, so it is readable before
    // the tab has ever been clicked — the whole point of hoisting that call.
    await page.goto(`/campaigns/${seeded.campaignUlid}`)
    await expect(page.locator('[data-test="campaign-detail-heading"]')).toBeVisible({
      timeout: 15_000,
    })
    await expect(page.locator('[data-test="tab-applications-badge"]')).toHaveText('2')

    await page.locator('[data-test="tab-applications"]').click()
    await expect(page.locator('[data-test="applications-list"]')).toBeVisible({ timeout: 10_000 })

    // The applicant's note reaches the agency (it is the only free text the
    // whole loop carries).
    await expect(
      page.locator(`[data-test="applications-note-${rejected.applicationUlid}"]`),
    ).toContainText('I shoot food content weekly.')

    // ── Reject, through the confirmation dialog (D4) ────────────────────────
    await page.locator(`[data-test="applications-reject-${rejected.applicationUlid}"]`).click()
    await expect(page.locator('[data-test="reject-application-dialog"]')).toBeVisible()
    await page.locator('[data-test="reject-application-confirm"]').click()

    // Terminal: the row survives with a status chip, and its two actions are
    // gone — the tab offers no way to answer an answered application, and the
    // badge now counts one pending.
    await expect(
      page.locator(`[data-test="applications-reject-${rejected.applicationUlid}"]`),
    ).toHaveCount(0, { timeout: 10_000 })
    await expect(
      page.locator(`[data-test="applications-accept-${rejected.applicationUlid}"]`),
    ).toHaveCount(0)
    await expect(page.locator('[data-test="tab-applications-badge"]')).toHaveText('1')

    // ── Accept, with a real offer (D2) ──────────────────────────────────────
    await page.locator(`[data-test="applications-accept-${accepted.applicationUlid}"]`).click()
    await expect(page.locator('[data-test="accept-application-dialog"]')).toBeVisible()

    // The offer form is the shared child (Q2) — the same fee field the invite
    // dialog uses, validated by the same rules.
    await page.locator('[data-test="accept-application-fee"]').locator('input').first().fill('750')
    await page.locator('[data-test="accept-application-submit"]').click()

    await expect(
      page.locator(`[data-test="applications-accept-${accepted.applicationUlid}"]`),
    ).toHaveCount(0, { timeout: 10_000 })
    // Nothing is pending, so the badge is gone entirely rather than showing 0.
    await expect(page.locator('[data-test="tab-applications-badge"]')).toHaveCount(0)

    // ── The board, for free (D1) ────────────────────────────────────────────
    // An accepted applicant is byte-indistinguishable downstream from a cold
    // invitee, so CreateBoardCard and the assignment.invited → Invited
    // automation carry them onto the board with no new machinery. This is the
    // assertion that claim earns.
    await page.locator('[data-test="tab-board"]').click()
    await expect(page.locator('[data-test="board-view"]')).toBeVisible({ timeout: 15_000 })

    const invitedColumn = page.locator('.board-column').filter({
      has: page.locator('[data-test^="board-column-name-"]', { hasText: 'Invited' }),
    })

    await expect(invitedColumn).toContainText('Grace Hopper', { timeout: 10_000 })
    await expect(invitedColumn.locator('.board-card')).toHaveCount(1)
    // And the rejected applicant never became an assignment at all.
    await expect(page.locator('[data-test="board-view"]')).not.toContainText('Ada Lovelace')
  })
})
