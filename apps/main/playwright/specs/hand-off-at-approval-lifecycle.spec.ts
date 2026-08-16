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
 * AH-069 (D9) — the hand-off lifecycle, end to end, both roles.
 *
 * The chunk's claim in one sentence: on a campaign whose creators do not post
 * the deliverable, APPROVING THE DRAFT IS THE END. Four surfaces have to agree
 * on that at once, and each of them is proved somewhere by a unit or feature
 * test. What no test can prove in isolation is that they agree with each other
 * on the same campaign, in the same session, in the order a human meets them:
 *
 *   1. The agency turns posting off through the REAL Settings switch. This is
 *      the leg's coverage of D1's write path — the switch, the PATCH, the
 *      re-read — rather than a fixture that pre-set the column.
 *   2. The board immediately stops rendering the posting column (D6). Same
 *      board, same session, one save apart.
 *   3. The creator submits a LINK-ONLY draft. Link-only deliberately: media
 *      uploads need presigned S3 round-trips, which is a different chunk's
 *      apparatus and a different chunk's flakiness.
 *   4. The agency approves — one click, no second action anywhere.
 *   5. The creator's page shows the completion banner, and NO post form. This
 *      is the D7 assertion that matters: the banner appears AND the affordance
 *      it replaces is gone.
 *
 * ── Where it stops, and why that is the honest line ────────────────────────
 *
 * This leg covers the toggle-OFF path only. The posting path —
 * approve → post → verify — remains the named pre-existing E2E gap it was
 * before this chunk; AH-069 does not close it and does not pretend to. What
 * changed is that the OFF path now has the coverage the ON path still lacks.
 *
 * Role swaps go through `signOutViaApi` + a fresh SPA sign-in, for the reason
 * the jobs-board full-lifecycle spec records: the SPA sign-in is what engages
 * Sanctum's stateful pipeline.
 */

const CREATOR_PASSWORD = 'Cata1yst-HandOff-E2E!'

const CAMPAIGN_NAME = 'Spring lookbook'
const BRAND_NAME = 'Halden Studio'
const DRAFT_LINK = 'https://drive.example/spring-lookbook-cut-01'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('AH-069 (D9) — the hand-off lifecycle, end to end, both roles', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await restoreThrottle(page.context().request, 'auth-ip')
    await signOutViaApi(page.context().request)
  })

  test('the agency turns posting off, the creator submits a link-only draft, and approving it completes the assignment', async ({
    page,
  }) => {
    test.setTimeout(180_000)

    const request = page.context().request
    const creatorEmail = uniqueEmail('handoff-creator')

    // ── Provisioning ────────────────────────────────────────────────────────
    // A real creator account (the spec signs in as them) on a CONTRACTED
    // assignment, with the posting toggle left ON — step 1 is turning it off.
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, CREATOR_PASSWORD, 'Hand Off')
    await verifyEmailViaApi(request, creatorEmail)

    const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: CAMPAIGN_NAME,
      brandName: BRAND_NAME,
    })

    // ── Step 0: the board renders its posting column while posting is ON ────
    await signInAsAgency(page, admin.email, admin.password)
    await page.goto(`/campaigns/${seeded.campaignUlid}`)
    await page.locator('[data-test="tab-board"]').click()
    await expect(page.locator('[data-test="board-view"]')).toBeVisible({ timeout: 15_000 })

    // The BEFORE half of the D6 pair. Without it, step 2's absence assertion
    // could pass on a board that never had the column in the first place.
    await expect(
      page.locator('[data-test^="board-column-name-"]', { hasText: 'Posted' }),
    ).toHaveCount(1)

    // ── Step 1: turn posting off through the real Settings switch (D1) ──────
    await page.locator('[data-test="tab-settings"]').click()
    const postingToggle = page.locator('[data-test="campaign-creator-posts-content"] input')
    await expect(postingToggle).toBeVisible({ timeout: 15_000 })
    await expect(postingToggle).toBeChecked()

    await postingToggle.click()
    await expect(postingToggle).not.toBeChecked()
    await page.locator('[data-test="campaign-form-submit"]').click()
    await expect(page.locator('[data-test="settings-success-toast"]')).toBeVisible({
      timeout: 15_000,
    })

    // Re-read from the server's response, not from local optimism: reload and
    // look again.
    await page.reload()
    await page.locator('[data-test="tab-settings"]').click()
    await expect(
      page.locator('[data-test="campaign-creator-posts-content"] input'),
    ).not.toBeChecked({ timeout: 15_000 })

    // ── Step 2: the board no longer renders the posting column (D6) ─────────
    await page.locator('[data-test="tab-board"]').click()
    await expect(page.locator('[data-test="board-view"]')).toBeVisible({ timeout: 15_000 })
    await expect(
      page.locator('[data-test^="board-column-name-"]', { hasText: 'Posted' }),
    ).toHaveCount(0)
    // Only that one column went: the rest of the board is untouched.
    await expect(
      page.locator('[data-test^="board-column-name-"]', { hasText: 'Approved' }),
    ).toHaveCount(1)

    // ── Step 3: the creator submits a link-only draft ───────────────────────
    await signOutViaApi(request)
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)

    await page.goto(`/creator/assignments/${seeded.assignmentUlid}`)
    await expect(page.locator('[data-testid="assignment-draft-form"]')).toBeVisible({
      timeout: 15_000,
    })

    await page.locator('[data-testid="assignment-draft-attach-link"]').click()
    await page
      .locator('[data-testid="assignment-draft-link-url"]')
      .locator('input')
      .fill(DRAFT_LINK)
    await page.locator('[data-testid="assignment-draft-link-add"]').click()
    await page.locator('[data-testid="assignment-draft-submit"]').click()

    await expect(page.locator('[data-testid="assignment-awaiting-review"]')).toBeVisible({
      timeout: 15_000,
    })

    // ── Step 4: the agency approves — ONE click, and that is the whole act ──
    await signOutViaApi(request)
    await signInAsAgency(page, admin.email, admin.password)

    await page.goto(`/campaigns/${seeded.campaignUlid}`)
    await page.locator('[data-test="tab-drafts"]').click()
    const review = page.locator('[data-test^="drafts-review-"]').first()
    await expect(review).toBeVisible({ timeout: 15_000 })
    await review.click()

    await expect(page.locator('[data-test="review-draft-drawer"]')).toBeVisible({ timeout: 15_000 })
    await page.locator('[data-test="review-approve"]').click()
    await expect(page.locator('[data-test="review-draft-drawer"]')).toBeHidden({ timeout: 15_000 })

    // ── Step 5: the creator sees a finished assignment, and no post form ────
    await signOutViaApi(request)
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)

    await page.goto(`/creator/assignments/${seeded.assignmentUlid}`)

    const notice = page.locator('[data-testid="assignment-completed-on-approval-notice"]')
    await expect(notice).toBeVisible({ timeout: 15_000 })
    await expect(notice).toContainText('Your draft has been approved')
    await expect(notice).toContainText('no further action is needed')

    // The banner is only half the claim. The other half is that the thing it
    // replaces is genuinely gone — a page showing both would be telling the
    // creator the work is done and asking them to do more in the same breath.
    await expect(page.locator('[data-testid="assignment-posted-form"]')).toHaveCount(0)
    await expect(page.locator('[data-testid="assignment-awaiting-verification"]')).toHaveCount(0)
    // And it never borrows the verified banner's sentence (D7's copy rule).
    await expect(page.locator('[data-testid="assignment-verified-notice"]')).toHaveCount(0)
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
