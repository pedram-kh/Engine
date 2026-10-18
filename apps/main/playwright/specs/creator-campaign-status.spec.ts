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
 * AH-107 — the campaign's state, on the creator side, across the role swap.
 *
 * The claim: when the AGENCY pauses a campaign, the CREATOR sees it, and nothing
 * else about their assignment changes. Three things have to agree and no unit
 * test sees all three at once: the agency's real Settings control writes the
 * status, the API puts it on the creator's resources, and the creator's pages
 * render it.
 *
 *   1. As the creator, before anything: the campaign is Active (the seed's
 *      state) and there is NO banner. This is the BEFORE half of the pair, so the
 *      "Paused" assertions below cannot pass on a page that always said it.
 *   2. The agency pauses it through the REAL Settings select.
 *   3. As the creator: the assignment card says Paused, the detail header says
 *      Paused, the banner is there, and the draft form is STILL THERE. That last
 *      assertion is Q2 ("display only; no server gates"): a banner informs, it does
 *      not lock. Tracked as tech debt, "campaign status does not gate creator
 *      actions".
 *   4. At a 360 px phone width neither chip is clipped (the campaign-status chip, and the
 *      assignment-status chip beside the long title) (AH-106's lesson: a
 *      `toHaveText` matches the DOM and cannot see a clipped label).
 *
 * Role swaps go through `signOutViaApi` + a fresh SPA sign-in, as the hand-off
 * spec records: the SPA sign-in is what engages Sanctum's stateful pipeline.
 */

const CREATOR_PASSWORD = 'Cata1yst-Status-E2E!'

const CAMPAIGN_NAME = 'Autumn UGC push for the whole family kitchen collection 2026'
const BRAND_NAME = 'Halden Studio'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('AH-107 — campaign status on the creator side', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await restoreThrottle(page.context().request, 'auth-ip')
    await signOutViaApi(page.context().request)
  })

  test('the agency pauses a campaign; the creator sees it, and can still act', async ({ page }) => {
    test.setTimeout(180_000)

    const request = page.context().request
    const creatorEmail = uniqueEmail('status-creator')

    // ── Provisioning: a CONTRACTED assignment on an ACTIVE campaign ─────────
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, CREATOR_PASSWORD, 'Campaign Status')
    await verifyEmailViaApi(request, creatorEmail)
    const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: CAMPAIGN_NAME,
      brandName: BRAND_NAME,
    })

    const detailChip = page.locator('[data-testid="assignment-detail-campaign-status"]')
    const banner = page.locator('[data-testid="creator-campaign-status-banner"]')
    const listChip = page.locator(
      `[data-testid="creator-assignment-campaign-status-${seeded.assignmentUlid}"]`,
    )

    // ── Step 1: the creator, BEFORE the pause ───────────────────────────────
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)
    await page.goto(`/creator/assignments/${seeded.assignmentUlid}`)
    await expect(detailChip).toHaveText('Active', { timeout: 15_000 })
    await expect(banner).toHaveCount(0)
    await expect(page.locator('[data-testid="assignment-draft-form"]')).toBeVisible()

    // ── Step 2: the agency pauses the campaign through the real control ─────
    await signOutViaApi(request)
    await signInAsAgency(page, admin.email, admin.password)
    await page.goto(`/campaigns/${seeded.campaignUlid}`)
    await page.locator('[data-test="tab-settings"]').click()
    await page.locator('[data-test="campaign-settings-status"]').click()
    await page.getByRole('option', { name: 'Paused' }).click()
    await page.locator('[data-test="campaign-form-submit"]').click()
    await expect(page.locator('[data-test="settings-success-toast"]')).toBeVisible({
      timeout: 15_000,
    })

    // ── Step 3: the creator, AFTER the pause ────────────────────────────────
    await signOutViaApi(request)
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)

    await page.goto('/creator/assignments')
    await expect(listChip).toHaveText('Paused', { timeout: 15_000 })

    await page.goto(`/creator/assignments/${seeded.assignmentUlid}`)
    await expect(detailChip).toHaveText('Paused', { timeout: 15_000 })
    await expect(banner).toBeVisible()
    await expect(banner).toContainText('This campaign is paused.')
    // Q2, display only: the banner informs and locks nothing.
    await expect(page.locator('[data-testid="assignment-draft-form"]')).toBeVisible()

    // ── Step 4: not clipped at a phone width ────────────────────────────────
    await page.setViewportSize({ width: 360, height: 740 })
    await page.goto('/creator/assignments')
    await expect(listChip).toHaveText('Paused', { timeout: 15_000 })
    const cutOff = await listChip.evaluate((el) => el.scrollWidth - el.clientWidth)
    expect(cutOff, 'the campaign-status chip clips its own label').toBeLessThanOrEqual(1)

    // The assignment-status chip shares the card with the long title above; it clipped
    // ("Contrac…") before it was pinned (found in the AH-107 copy-review screenshots).
    const assignmentChip = page.locator(
      `[data-testid="creator-assignment-status-${seeded.assignmentUlid}"]`,
    )
    await expect(assignmentChip).toBeVisible()
    const assignmentCut = await assignmentChip.evaluate((el) => el.scrollWidth - el.clientWidth)
    expect(assignmentCut, 'the assignment-status chip clips its own label').toBeLessThanOrEqual(1)
  })
})

// ---------------------------------------------------------------------------
// Role helpers — both sign in through the SPA so Sanctum's stateful pipeline
// engages for everything the page does afterwards.
// ---------------------------------------------------------------------------

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
