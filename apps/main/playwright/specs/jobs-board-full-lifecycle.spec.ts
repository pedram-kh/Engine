import type { Locator, Page } from '@playwright/test'
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
 * The jobs-board arc's regression net (AH-059, D6) — one spec, both roles, the
 * whole loop.
 *
 * Chunks 3, 4 and 5 each proved their own half against a real API: the creator
 * browses and applies (AH-056), the agency answers and the accept lands on the
 * board (AH-058), and the surfaces reflect it (this chunk). What no leg has ever
 * walked is the JOIN — the same campaign carried from an unlisted row on the
 * agency's campaigns table all the way to a creator-accepted engagement, with
 * the roles swapping at every step. Each hand-off is a place where two specs
 * agreeing on a fixture can hide a product that does not actually connect.
 *
 * ── The seven steps, and what each one is the only proof of ─────────────────
 *
 *   1. The agency LISTS the campaign from the list-page toggle (D3) — the
 *      confirm-on-ON dialog, the PATCH, the row re-read.
 *   2. The creator SEES it on the board — the visibility predicate, live.
 *   3. The creator APPLIES with a note.
 *   4. The agency finds the application in the board's Applications column (D4)
 *      — not the tab, deliberately: the column is the new surface, and this is
 *      the only place its accept path is exercised end to end.
 *   5. The accept produces an offer, and the invited assignment appears in the
 *      board's Invited column through the chunk-4 listener + automation.
 *   6. The creator's job surfaces REFLECT the engagement (D1 + D5): the
 *      lifecycle chip replaces "Applied", and the detail offers the bridge to
 *      the assignment.
 *   7. The creator ACCEPTS the offer, and the board shows it — the card STAYS
 *      in Invited and only its chip changes (to Contracted: the campaign needs
 *      no per-campaign contract, so the accept auto-advances past that step).
 *
 * ── Where it stops, and why that is the honest line (Q8) ────────────────────
 *
 * Step 7 is the end. Drafts and posting would need the review cycle, media
 * uploads and the verification hop — three more surfaces, each with its own
 * feature coverage, and a spec that owned all of them would fail for reasons
 * that have nothing to do with the jobs board. The named future spec is the
 * draft → review → posted → verified leg, recorded in the chunk's review.
 *
 * ── Step 7 asserts a NON-motion, and that is deliberate ─────────────────────
 *
 * Accepting an offer does not move the card between columns: the seeded
 * automations map `assignment.invited` → Invited, and there is no
 * `assignment.accepted` rule in the default set. The card stays where it is and
 * its status chip changes. Asserting the card is STILL in Invited is the point —
 * a spec that expected a column move would be encoding a wish rather than the
 * product.
 *
 * ── Cross-role mechanics ────────────────────────────────────────────────────
 *
 * Role swaps go through `signOutViaApi` + a fresh SPA sign-in rather than two
 * browser contexts. The SPA sign-in is what engages Sanctum's stateful pipeline
 * (the creator-dashboard spec's note applies verbatim), and a second context
 * would double the sign-in cost without proving anything the single session
 * does not. Neither role is MFA-gated on the routes this spec drives.
 *
 * This is the suite's longest spec by design, and its timeout says so.
 */

const CREATOR_PASSWORD = 'Cata1yst-FullLoop-E2E!'

const CAMPAIGN_NAME = 'Midwinter UGC push'
const BRAND_NAME = 'Northwind Coffee'
const APPLICATION_NOTE = 'I shoot food content weekly, mostly in daylight.'
const OFFER_FEE = '750'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('AH-059 (D6) — the jobs board, end to end, both roles', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await restoreThrottle(page.context().request, 'auth-ip')
    await signOutViaApi(page.context().request)
  })

  test('an agency lists a job, a creator applies, the agency accepts on the board, and the creator accepts the offer', async ({
    page,
  }) => {
    // Four sign-ins and two SPAs' worth of surfaces; the default 30s would fail
    // on machine speed rather than on product behaviour.
    test.setTimeout(180_000)

    const request = page.context().request
    const creatorEmail = uniqueEmail('loop-creator')

    // ── Provisioning ────────────────────────────────────────────────────────
    // The creator account is REAL (production sign-up + verification) because
    // the spec signs in as them. The campaign is seeded floor-complete but
    // UNLISTED: listing it is step 1, and a pre-listed fixture would delete it.
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, CREATOR_PASSWORD, 'Full Loop')
    await verifyEmailViaApi(request, creatorEmail)

    const seeded = await seedListableCampaign(request, admin.agencyUlid, creatorEmail, {
      campaignName: CAMPAIGN_NAME,
      brandName: BRAND_NAME,
    })

    // ── Step 1: the agency lists the campaign from the list page (D3) ───────
    await signInAsAgency(page, admin.email, admin.password)
    await page.goto('/campaigns')

    const toggle = page.locator(`[data-test="campaign-job-board-toggle-${seeded.campaignUlid}"]`)
    await expect(toggle).toBeVisible({ timeout: 15_000 })
    await expect(toggle.locator('input')).not.toBeChecked()

    // ON asks first — the flip is reachable to rostered creators, so it earns a
    // confirmation. (OFF stays immediate; that asymmetry has unit coverage.)
    await toggle.locator('input').click()
    const confirm = page.locator('[data-test="campaign-listing-confirm"]')
    await expect(confirm).toBeVisible()
    await expect(page.locator('[data-test="campaign-listing-confirm-body"]')).toContainText(
      CAMPAIGN_NAME,
    )
    await page.locator('[data-test="campaign-listing-confirm-submit"]').click()

    await expect(page.locator('[data-test="campaign-listing-snackbar"]')).toContainText(
      CAMPAIGN_NAME,
      { timeout: 10_000 },
    )
    // The row re-read from the server's response, not from local optimism.
    await expect(toggle.locator('input')).toBeChecked()

    // ── Step 2 + 3: the creator sees the job and applies ────────────────────
    await signOutViaApi(request)
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)

    await page.goto('/creator/jobs')
    const card = page.locator(`[data-testid="creator-job-${seeded.campaignUlid}"]`)
    await expect(card).toBeVisible({ timeout: 15_000 })
    await expect(
      page.locator(`[data-testid="creator-job-brand-${seeded.campaignUlid}"]`),
    ).toHaveText(BRAND_NAME)

    await card.click()
    await expect(page).toHaveURL(new RegExp(`/creator/jobs/${seeded.campaignUlid}$`))
    await page.locator('[data-testid="creator-job-apply"]').click()
    await page
      .locator('[data-testid="creator-job-apply-note"]')
      .locator('textarea')
      .first()
      .fill(APPLICATION_NOTE)
    await page.locator('[data-testid="creator-job-apply-submit"]').click()
    await expect(page.locator('[data-testid="creator-job-applied-notice"]')).toBeVisible({
      timeout: 10_000,
    })

    // ── Step 4: the agency finds it in the board's Applications column (D4) ─
    await signOutViaApi(request)
    await signInAsAgency(page, admin.email, admin.password)

    await page.goto(`/campaigns/${seeded.campaignUlid}`)
    await expect(page.locator('[data-test="campaign-detail-heading"]')).toBeVisible({
      timeout: 15_000,
    })
    await page.locator('[data-test="tab-board"]').click()
    await expect(page.locator('[data-test="board-view"]')).toBeVisible({ timeout: 15_000 })

    // The pseudo-column is the board's first column and carries its own count.
    const applicationsColumn = page.locator('[data-test="board-applications-column"]')
    await expect(applicationsColumn).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('[data-test="board-applications-count"]')).toHaveText('1', {
      timeout: 10_000,
    })
    await expect(applicationsColumn).toContainText(APPLICATION_NOTE)

    // No drag machinery reached the DOM — the §5.34 negative, asserted against
    // the real render rather than a mounted stub.
    await expect(applicationsColumn.locator('.board-column__drag')).toHaveCount(0)

    // ── Step 5: accept, with a real offer ───────────────────────────────────
    await applicationsColumn.locator('[data-test^="board-application-accept-"]').first().click()
    await expect(page.locator('[data-test="accept-application-dialog"]')).toBeVisible()
    await page
      .locator('[data-test="accept-application-fee"]')
      .locator('input')
      .first()
      .fill(OFFER_FEE)
    await page.locator('[data-test="accept-application-submit"]').click()

    // The dual refetch: the application leaves the column…
    await expect(page.locator('[data-test="board-applications-empty"]')).toBeVisible({
      timeout: 15_000,
    })
    await expect(page.locator('[data-test="board-applications-count"]')).toHaveText('0')

    // …and the invited assignment arrives in Invited, carried there by the
    // chunk-4 listener + automation. Nothing in chunk 5 creates a card.
    const invitedColumn = page.locator('.board-column').filter({
      has: page.locator('[data-test^="board-column-name-"]', { hasText: 'Invited' }),
    })
    await expect(invitedColumn.locator('.board-card')).toHaveCount(1, { timeout: 15_000 })
    const boardCard = invitedColumn.locator('.board-card').first()
    const boardCardId = await cardIdOf(boardCard)
    await expect(page.locator(`[data-test="board-card-status-${boardCardId}"]`)).toHaveText(
      'Invited',
    )

    // ── Step 6: the creator's surfaces reflect the engagement (D1 + D5) ─────
    await signOutViaApi(request)
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)

    await page.goto('/creator/jobs')
    // The lifecycle chip, NOT "Applied": once an assignment exists it is the
    // truth of the pair, and the application chip is the one that yields.
    await expect(
      page.locator(`[data-testid="creator-job-lifecycle-${seeded.campaignUlid}"]`),
    ).toHaveText('In progress', { timeout: 15_000 })
    await expect(
      page.locator(`[data-testid="creator-job-applied-${seeded.campaignUlid}"]`),
    ).toHaveCount(0)

    await page.goto(`/creator/jobs/${seeded.campaignUlid}`)
    await expect(page.locator('[data-testid="creator-job-lifecycle-notice"]')).toContainText(
      'This job is under way.',
      { timeout: 15_000 },
    )
    // The bridge to the full picture — the detail keeps the assignment link.
    await expect(page.locator('[data-testid="creator-job-lifecycle-link"]')).toBeVisible()

    // ── Step 7: the creator accepts the offer ───────────────────────────────
    await page.goto('/creator/assignments')
    const acceptOffer = page.locator('[data-testid^="creator-assignment-accept-"]').first()
    await expect(acceptOffer).toBeVisible({ timeout: 15_000 })
    await expect(page.locator('[data-testid="creator-assignments-list"]')).toContainText(
      CAMPAIGN_NAME,
    )
    await acceptOffer.click()
    await expect(page.locator('[data-testid="creator-assignments-snackbar"]')).toBeVisible({
      timeout: 15_000,
    })
    await expect(acceptOffer).toHaveCount(0)

    // ── The board, one last time: the chip moved, the card did not ──────────
    await signOutViaApi(request)
    await signInAsAgency(page, admin.email, admin.password)

    await page.goto(`/campaigns/${seeded.campaignUlid}`)
    await page.locator('[data-test="tab-board"]').click()
    await expect(page.locator('[data-test="board-view"]')).toBeVisible({ timeout: 15_000 })

    // CONTRACTED, not "Accepted" — and the difference is a real product fact,
    // not a looser assertion. The campaign does not require a per-campaign
    // contract, so accepting the offer auto-advances the assignment past the
    // contract step in the same request. Pinning the literal end state is what
    // makes this spec notice if that auto-advance ever silently stops.
    await expect(page.locator(`[data-test="board-card-status-${boardCardId}"]`)).toHaveText(
      'Contracted',
      { timeout: 15_000 },
    )
    // Still in Invited: no default automation maps `assignment.accepted` or
    // `assignment.contracted`, so the honest end state is a chip change with
    // the card exactly where the invite put it.
    // The SAME card, by the ULID read off it before the creator ever answered —
    // not a lookalike in the same column.
    await expect(
      page
        .locator('.board-column')
        .filter({
          has: page.locator('[data-test^="board-column-name-"]', { hasText: 'Invited' }),
        })
        .locator(`[data-test="board-card-${boardCardId}"]`),
    ).toHaveCount(1)
  })
})

// ---------------------------------------------------------------------------
// Role helpers. Both sign in through the SPA (not the API) so Sanctum's
// stateful pipeline engages for everything the page does afterwards.
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

/**
 * The board card's ULID, read off its own `data-test`. The spec never learns it
 * from a fixture — the card is created by the listener, so its identity is a
 * product output, and asserting on it later is what proves the SAME card is
 * still there after the accept rather than a replacement.
 */
async function cardIdOf(card: Locator): Promise<string> {
  const attribute = await card.getAttribute('data-test')
  const id = attribute?.replace('board-card-', '')
  if (id === undefined || id === '') {
    throw new Error(`Could not read the board card id from ${attribute ?? 'a missing attribute'}`)
  }
  return id
}
