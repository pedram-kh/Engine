import type { Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedContractedAssignment,
  setFeatureFlag,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * AH-119 (Stage A) — the agency's unified inbox on a real stack.
 *
 *   1. A creator (one account, two campaigns under two brands) sends a Direct message and one
 *      message in each campaign conversation, so the agency has three unread threads.
 *   2. The agency opens Messages with `messages_v2_inbox_enabled` ON: one Direct row and two
 *      assignment rows.
 *   3. The brand popover lists both brands with a count of 1 each; choosing one leaves its
 *      assignment row only (Direct rows carry no brand), and the choice rides in the URL.
 *   4. The Mine chip writes `owner=me` and the inbox reloads for it.
 *   5. Opening an assignment row shows that conversation in the agency thread view; the agency
 *      sends a message containing a link and it renders as an anchor (with rel), surviving a
 *      reload.
 *
 * The creator's sends go through the real API: the creator's chat is not what is under test.
 */

const PASSWORD = 'Cata1yst-Unified-E2E!'
const API = 'http://127.0.0.1:8000/api/v1'
const FLAG = 'messages_v2_inbox_enabled'
const LINK = 'https://example.com/wave-1-brief'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

async function xsrfHeaders(page: Page): Promise<Record<string, string>> {
  const cookies = await page.context().cookies()
  const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN')
  return {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    Referer: 'http://127.0.0.1:5173/',
    'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.value) : '',
  }
}

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 15_000 })
}

test.describe('AH-119 — the agency unified inbox', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await setFeatureFlag(page.context().request, FLAG, false)
    await restoreThrottle(page.context().request, 'auth-ip')
    await signOutViaApi(page.context().request)
  })

  test('Direct and assignment rows, brand filter with counts, Mine, open a thread, send a link', async ({
    page,
  }) => {
    test.setTimeout(240_000)
    const request = page.context().request
    const creatorEmail = uniqueEmail('unified-creator')

    await setFeatureFlag(request, FLAG, true)
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, PASSWORD, 'Unified Creator')
    await verifyEmailViaApi(request, creatorEmail)
    const first = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: 'Unified Alpha',
      brandName: 'Unified Brand One',
    })
    const second = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: 'Unified Beta',
      brandName: 'Unified Brand Two',
    })

    // ── The creator speaks first: one Direct, one per campaign ──────────────
    await signIn(page, creatorEmail, PASSWORD)
    for (const url of [
      `${API}/creators/me/relationship-threads/${first.agencyUlid}/messages`,
      `${API}/creators/me/assignments/${first.assignmentUlid}/messages`,
      `${API}/creators/me/assignments/${second.assignmentUlid}/messages`,
    ]) {
      const sent = await request.post(url, {
        headers: await xsrfHeaders(page),
        data: { body: 'Hello from the creator.' },
      })
      expect(sent.status(), `${url}: ${await sent.text()}`).toBe(201)
    }
    await signOutViaApi(request)

    // ── Step 2: the unified inbox ───────────────────────────────────────────
    await signIn(page, admin.email, admin.password)
    await page.goto('/messages')
    await expect(page.locator('[data-test="unified-inbox"]')).toBeVisible({ timeout: 15_000 })
    const direct = page.locator('[data-test^="inbox-row-relationship_thread"]')
    const assignments = page.locator('[data-test^="inbox-row-assignment_thread"]')
    await expect(direct).toHaveCount(1, { timeout: 15_000 })
    await expect(assignments).toHaveCount(2)
    await expect(page.locator('[data-test="agency-inbox-unread-pill"]')).toContainText('3')

    // ── Step 3: the brand popover, with counts ──────────────────────────────
    await page.locator('[data-test="inbox-filter-brand"]').click()
    const popover = page.locator('[data-test="inbox-filter-brand-popover"]')
    await expect(popover).toBeVisible()
    await expect(popover).toContainText('Unified Brand One')
    await expect(popover).toContainText('Unified Brand Two')
    await expect(popover.locator('[data-test^="inbox-filter-brand-count-"]')).toHaveText(['1', '1'])
    await popover.getByText('Unified Brand One').click()
    await expect(page).toHaveURL(/brand=/)
    await expect(assignments).toHaveCount(1, { timeout: 10_000 })
    await expect(direct).toHaveCount(0)
    await page.keyboard.press('Escape')
    await page.locator('[data-test="agency-inbox-clear-filters"]').click()
    await expect(assignments).toHaveCount(2, { timeout: 10_000 })
    await expect(direct).toHaveCount(1)

    // ── Step 4: Mine ────────────────────────────────────────────────────────
    const mineList = page.waitForResponse(
      (r) => r.url().includes('/message-inbox') && r.url().includes('owner=me'),
    )
    await page.locator('[data-test="agency-inbox-mine"]').click()
    expect((await mineList).status()).toBe(200)
    await expect(page).toHaveURL(/owner=me/)
    await page.locator('[data-test="agency-inbox-mine"]').click()
    await expect(page).not.toHaveURL(/owner=me/)
    await expect(assignments).toHaveCount(2, { timeout: 10_000 })

    // ── Step 4b: Direct (only relationship threads; in the URL; combines with Mine) ──
    const directList = page.waitForResponse(
      (r) => r.url().includes('/message-inbox') && r.url().includes('direct=1'),
    )
    await page.locator('[data-test="agency-inbox-direct"]').click()
    expect((await directList).status()).toBe(200)
    await expect(page).toHaveURL(/direct=1/)
    await expect(assignments).toHaveCount(0, { timeout: 10_000 })
    await expect(direct).toHaveCount(1)
    await page.locator('[data-test="agency-inbox-mine"]').click()
    await expect(page).toHaveURL(/owner=me/)
    await expect(page).toHaveURL(/direct=1/)
    await page.locator('[data-test="agency-inbox-mine"]').click()
    await page.locator('[data-test="agency-inbox-direct"]').click()
    await expect(page).not.toHaveURL(/direct=1/)
    await expect(assignments).toHaveCount(2, { timeout: 10_000 })

    // ── Step 4c: filter area is two rows, and the chips row is ONE line (Clear shown) ──
    await page.locator('[data-test="agency-inbox-mine"]').click()
    await page.locator('[data-test="agency-inbox-direct"]').click()
    await page.locator('[data-test="agency-inbox-toggle-unread"]').click()
    await expect(page.locator('[data-test="agency-inbox-clear-filters"]')).toBeVisible()
    // The pane is as wide as the layout makes it at 1280; 460 and 380 pin the filter area itself
    // to those widths (a 460 px *viewport* would shrink the pane to the room beside the sidebar).
    for (const width of [null, 460, 380]) {
      await page.locator('[data-test="agency-inbox-filters"]').evaluate((el, w) => {
        ;(el as HTMLElement).style.width = w === null ? '' : `${w}px`
      }, width)
      // The container query reacts to the resize a frame later: poll until the layout settles.
      await expect(async () => {
        const row = page.locator('[data-test="agency-inbox-filters-chips"]')
        const rowBox = (await row.boundingBox())!
        const kids = await row.locator(':scope > *').all()
        expect(kids).toHaveLength(4)
        for (const kid of kids) {
          const box = (await kid.boundingBox())!
          // Same line: every child sits inside the row's height, nothing wrapped under another.
          expect(box.y).toBeGreaterThanOrEqual(rowBox.y - 1)
          expect(box.y + box.height).toBeLessThanOrEqual(rowBox.y + rowBox.height + 1)
          expect(box.x + box.width).toBeLessThanOrEqual(rowBox.x + rowBox.width + 1)
        }
        expect(rowBox.height).toBeLessThan(44)
        const clearBox = (await kids[3]!.boundingBox())!
        expect(clearBox.x).toBeGreaterThan((await kids[2]!.boundingBox())!.x)
        const popovers = (await page
          .locator('[data-test="agency-inbox-filters-popovers"]')
          .boundingBox())!
        expect(popovers.y + popovers.height).toBeLessThanOrEqual(rowBox.y + 1)
      }).toPass({ timeout: 5_000 })
    }
    await page.setViewportSize({ width: 1280, height: 720 })
    await page.locator('[data-test="agency-inbox-clear-filters"]').click()
    await expect(assignments).toHaveCount(2, { timeout: 10_000 })

    // ── Step 5: open an assignment thread, send a message with a link ───────
    await assignments.first().click()
    await expect(page).toHaveURL(/\/messages\/c\/[^/]+\/[^/]+/)
    await expect(page.locator('[data-test="relationship-thread"]')).toBeVisible({ timeout: 15_000 })
    await expect(page.locator('body')).toContainText('Hello from the creator.')

    const body = `Brief is here: ${LINK} thanks`
    await page
      .locator('[data-test="relationship-compose-body"] textarea.v-field__input:not([readonly])')
      .fill(body)
    await page.locator('[data-test="relationship-send"]').click()
    const anchor = page.locator(`[data-test^="relationship-message-"] a[href="${LINK}"]`)
    await expect(anchor).toBeVisible({ timeout: 15_000 })
    await expect(anchor).toHaveAttribute('rel', /noopener/)

    await page.reload()
    await expect(anchor).toBeVisible({ timeout: 15_000 })
  })

  test('with the flag OFF the old inbox renders and the unified list does not', async ({
    page,
  }) => {
    test.setTimeout(120_000)
    const request = page.context().request
    await setFeatureFlag(request, FLAG, false)
    const admin = await seedAgencyAdmin(request)
    await signIn(page, admin.email, admin.password)
    await page.goto('/messages')
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 15_000 })
    await expect(page.locator('[data-test="unified-inbox"]')).toHaveCount(0)
    await expect(page.locator('[data-test="agency-inbox-mine"]')).toHaveCount(0)
  })
})
