import type { APIRequestContext, Page } from '@playwright/test'
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
 * AH-108 — the Messages tab's unread badge, across the role swap.
 *
 * The claim: when the AGENCY messages a creator, the creator's Messages tab says
 * so, and opening the conversation takes it away. The three parts only meet on a
 * real stack: the agency's send writes the notification, the unread-count
 * endpoint splits it out as `message_unread_count`, and the creator shell's one
 * shared poll draws it; then opening the thread's `/read` clears the rows and
 * the shell re-reads at once (no waiting out the 45 s poll).
 *
 *   1. BEFORE: the creator has no unread messages and no badge. The BEFORE half
 *      of the pair, so the "2" below cannot pass on a page that always said it.
 *   2. The agency sends two messages through its real send endpoint.
 *   3. The creator loads the shell at an iPhone width: the Messages tab reads 2,
 *      and its accessible name carries the count.
 *   4. The creator opens the conversation. The badge goes (without a reload),
 *      and the server agrees: `message_unread_count` is 0.
 *
 * The agency send goes through the API, not the agency UI: the agency's chat
 * surface is not what is under test, the creator's tab is.
 */

const CREATOR_PASSWORD = 'Cata1yst-Badge-E2E!'
const API = 'http://127.0.0.1:8000/api/v1'

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

async function messageUnreadCount(page: Page, request: APIRequestContext): Promise<number> {
  const response = await request.get(`${API}/me/notifications/unread-count`, {
    headers: await xsrfHeaders(page),
  })
  expect(response.status()).toBe(200)
  const body = (await response.json()) as {
    data: { attributes: { message_unread_count: number } }
  }
  return body.data.attributes.message_unread_count
}

test.describe('AH-108 — the creator Messages tab badge', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await restoreThrottle(page.context().request, 'auth-ip')
    await signOutViaApi(page.context().request)
  })

  test('the agency messages the creator; the tab badges it; opening the thread clears it', async ({
    page,
  }) => {
    test.setTimeout(180_000)

    const request = page.context().request
    const creatorEmail = uniqueEmail('badge-creator')

    // ── Provisioning: a CONTRACTED assignment (the campaign chat is open) ───
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, CREATOR_PASSWORD, 'Badge Creator')
    await verifyEmailViaApi(request, creatorEmail)
    const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail)

    await page.setViewportSize({ width: 390, height: 844 })
    const messagesTab = page.locator('[data-test="creator-bottom-nav-messages"]')
    const badge = page.locator('[data-test="creator-messages-unread-badge-bottom"]')
    const badgeGlyph = badge.locator('.v-badge__badge')

    // ── Step 1: BEFORE — nothing unread, so no badge ────────────────────────
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)
    await page.goto('/creator/dashboard')
    await expect(messagesTab).toBeVisible({ timeout: 15_000 })
    await expect(badgeGlyph).toBeHidden()
    expect(await messageUnreadCount(page, request)).toBe(0)

    // ── Step 2: the agency sends two messages through the real endpoint ─────
    await signOutViaApi(request)
    await signInAsAgency(page, admin.email, admin.password)
    const sendUrl = `${API}/agencies/${seeded.agencyUlid}/campaigns/${seeded.campaignUlid}/assignments/${seeded.assignmentUlid}/messages`
    for (const body of ['Quick question about the brief.', 'And the deadline is Friday.']) {
      const sent = await request.post(sendUrl, {
        headers: await xsrfHeaders(page),
        data: { body },
      })
      expect(sent.status(), await sent.text()).toBe(201)
    }

    // ── Step 3: the creator's tab reads 2 ───────────────────────────────────
    await signOutViaApi(request)
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)
    await page.goto('/creator/dashboard')
    await expect(badgeGlyph).toHaveText('2', { timeout: 15_000 })
    await expect(messagesTab).toHaveAttribute('aria-label', /2/)
    expect(await messageUnreadCount(page, request)).toBe(2)

    // ── Step 4: opening the conversation clears it, with no reload ──────────
    const readResponse = page.waitForResponse(
      (r) => r.url().endsWith('/messages/read') && r.request().method() === 'POST',
    )
    await page.goto(`/creator/assignments/${seeded.assignmentUlid}`)
    await expect(page.locator('[data-testid="assignment-chat"]')).toBeVisible({ timeout: 15_000 })
    expect((await readResponse).status()).toBe(200)
    // Client-side navigation back: the shared shell poll is the thing under test, a
    // full page load would re-fetch and pass for the wrong reason.
    await page.locator('[data-test="creator-bottom-nav-dashboard"]').click()
    await expect(page).toHaveURL(/\/creator\/dashboard/)
    await expect(badgeGlyph).toBeHidden({ timeout: 10_000 })
    expect(await messageUnreadCount(page, request)).toBe(0)
  })
})

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
