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
 * AH-115 L4 — the creator's unified inbox on a real stack, behind `messages_v2_creator_inbox_enabled`.
 *
 *   ON:  one list; the Direct row is tagged "Direct", each campaign row "Brand · Campaign"; the
 *        search field finds a message by its body and marks the hit; a campaign row (and a hit
 *        inside one) deep-links to that assignment's chat (`/creator/assignments/:ulid?focus=messages`).
 *   OFF: the previous list renders (no tag, no search field), and the new endpoint answers 404.
 *
 * The sends go through the real API: the chat is not what is under test.
 */

const PASSWORD = 'Cata1yst-CreatorInbox-E2E!'
const API = 'http://127.0.0.1:8000/api/v1'
const FLAG = 'messages_v2_creator_inbox_enabled'
const NEEDLE = 'zebracrossing'

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

async function seed(page: Page) {
  const request = page.context().request
  const creatorEmail = uniqueEmail('creator-inbox')
  const admin = await seedAgencyAdmin(request)
  await signUpUser(request, creatorEmail, PASSWORD, 'Inbox Creator')
  await verifyEmailViaApi(request, creatorEmail)
  const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
    campaignName: 'Inbox Alpha',
    brandName: 'Inbox Brand',
  })
  await signIn(page, creatorEmail, PASSWORD)
  // The agency speaks first (a creator's own send would also surface the thread; this is the real path).
  return { request, creatorEmail, admin, seeded }
}

test.describe('AH-115 L4 — the creator unified inbox', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await setFeatureFlag(page.context().request, FLAG, false)
    await restoreThrottle(page.context().request, 'auth-ip')
    await signOutViaApi(page.context().request)
  })

  test('ON: one list, Direct and Brand · Campaign tags, search by body, deep link to the assignment chat', async ({
    page,
  }) => {
    test.setTimeout(240_000)
    await setFeatureFlag(page.context().request, FLAG, true)
    const { request, seeded } = await seed(page)

    for (const [url, body] of [
      [`${API}/creators/me/relationship-threads/${seeded.agencyUlid}/messages`, 'Direct hello.'],
      [
        `${API}/creators/me/assignments/${seeded.assignmentUlid}/messages`,
        `Campaign note with ${NEEDLE} inside.`,
      ],
    ]) {
      const sent = await request.post(url, {
        headers: await xsrfHeaders(page),
        data: { body },
      })
      expect(sent.status(), `${url}: ${await sent.text()}`).toBe(201)
    }

    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/creator/messages')

    // One list, two rows, two tags.
    const rows = page.locator('[data-test^="relationship-inbox-row-"]')
    await expect(rows).toHaveCount(2, { timeout: 15_000 })
    // Order is not asserted (both sends can land in the same second); the set of tags is.
    const tags = await page.locator('[data-test^="relationship-inbox-tag-"]').allTextContents()
    expect(tags.map((t) => t.trim()).sort()).toEqual(['Direct', 'Inbox Brand · Inbox Alpha'])

    // Search: the hit carries the marked term, and links to the campaign chat.
    await page.locator('[data-test="creator-inbox-search"] input').fill(NEEDLE)
    const results = page.locator('[data-test="message-search-results"]')
    await expect(results).toBeVisible({ timeout: 15_000 })
    await expect(results.locator('[data-test="search-term-match"]').first()).toContainText(NEEDLE)
    await results.locator('[data-test^="message-hit-"]').first().click()
    await expect(page).toHaveURL(
      new RegExp(`/creator/assignments/${seeded.assignmentUlid}\\?focus=messages`),
    )
    await expect(page.locator('[data-testid="assignment-chat"]')).toBeVisible({ timeout: 15_000 })

    // The campaign row deep-links the same way.
    await page.goto('/creator/messages')
    const campaignRow = page.locator(
      `[data-test^="relationship-inbox-row-"][href*="/creator/assignments/${seeded.assignmentUlid}"]`,
    )
    await expect(campaignRow).toHaveAttribute('href', /focus=messages/, { timeout: 15_000 })
    await campaignRow.click()
    await expect(page).toHaveURL(new RegExp(`/creator/assignments/${seeded.assignmentUlid}`))
  })

  test('ON: the All | Unread control and the Direct chip filter the list and live in the URL', async ({
    page,
  }) => {
    test.setTimeout(300_000)
    const request = page.context().request
    const creatorEmail = uniqueEmail('creator-inbox-filters')
    await setFeatureFlag(request, FLAG, true)
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, PASSWORD, 'Filter Creator')
    await verifyEmailViaApi(request, creatorEmail)
    const alpha = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: 'Filter Alpha',
      brandName: 'Filter Brand One',
    })
    const beta = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: 'Filter Beta',
      brandName: 'Filter Brand Two',
    })

    // The creator speaks in Beta only: that thread has nothing unread for the creator.
    await signIn(page, creatorEmail, PASSWORD)
    const own = await request.post(
      `${API}/creators/me/assignments/${beta.assignmentUlid}/messages`,
      {
        headers: await xsrfHeaders(page),
        data: { body: 'Hello from the creator.' },
      },
    )
    expect(own.status(), await own.text()).toBe(201)
    await signOutViaApi(request)

    // The agency answers in Alpha and opens a Direct thread: both unread for the creator.
    await signIn(page, admin.email, admin.password)
    for (const url of [
      `${API}/agencies/${admin.agencyUlid}/creators/${alpha.creatorUlid}/relationship-messages`,
      `${API}/agencies/${admin.agencyUlid}/campaigns/${alpha.campaignUlid}/assignments/${alpha.assignmentUlid}/messages`,
    ]) {
      const sent = await request.post(url, {
        headers: await xsrfHeaders(page),
        data: { body: 'Hello from the agency.' },
      })
      expect(sent.status(), `${url}: ${await sent.text()}`).toBe(201)
    }
    await signOutViaApi(request)

    await signIn(page, creatorEmail, PASSWORD)
    await page.setViewportSize({ width: 1280, height: 800 })
    await page.goto('/creator/messages')
    const rows = page.locator('[data-test^="relationship-inbox-row-"]')
    await expect(rows).toHaveCount(3, { timeout: 15_000 })

    // Unread: Direct and Alpha stay, Beta (the creator's own message) goes.
    await page.locator('[data-test="creator-inbox-toggle-unread"]').click()
    await expect(page).toHaveURL(/unread=1/)
    await expect(page).not.toHaveURL(/direct=1/)
    await expect(rows).toHaveCount(2, { timeout: 15_000 })

    // Direct on top of Unread: the Direct row alone.
    await page.locator('[data-test="creator-inbox-direct"]').click()
    await expect(page).toHaveURL(/direct=1/)
    await expect(rows).toHaveCount(1, { timeout: 15_000 })
    await expect(page.locator('[data-test^="relationship-inbox-tag-"]')).toHaveText('Direct')

    // The state survives a reload.
    await page.reload()
    await expect(page).toHaveURL(/unread=1/)
    await expect(page).toHaveURL(/direct=1/)
    await expect(rows).toHaveCount(1, { timeout: 15_000 })

    // All + Direct: still the Direct row; Direct off + All: everything again.
    await page.locator('[data-test="creator-inbox-toggle-all"]').click()
    await expect(page).not.toHaveURL(/unread=1/)
    await expect(rows).toHaveCount(1, { timeout: 15_000 })
    await page.locator('[data-test="creator-inbox-direct"]').click()
    await expect(page).not.toHaveURL(/direct=1/)
    await expect(rows).toHaveCount(3, { timeout: 15_000 })
  })

  test('OFF: the previous list, no tag, no search field, and the new endpoint answers 404', async ({
    page,
  }) => {
    test.setTimeout(180_000)
    await setFeatureFlag(page.context().request, FLAG, false)
    const { request, seeded } = await seed(page)

    const sent = await request.post(
      `${API}/creators/me/relationship-threads/${seeded.agencyUlid}/messages`,
      { headers: await xsrfHeaders(page), data: { body: 'Direct hello.' } },
    )
    expect(sent.status()).toBe(201)

    await page.goto('/creator/messages')
    await expect(page.locator('[data-test="relationship-inbox"]')).toBeVisible({ timeout: 15_000 })
    await expect(page.locator('[data-test^="relationship-inbox-row-"]')).toHaveCount(1)
    await expect(page.locator('[data-test^="relationship-inbox-tag-"]')).toHaveCount(0)
    await expect(page.locator('[data-test="creator-inbox-search"]')).toHaveCount(0)

    const probe = await request.get(`${API}/creators/me/message-inbox`, {
      headers: await xsrfHeaders(page),
    })
    expect(probe.status()).toBe(404)
  })
})
