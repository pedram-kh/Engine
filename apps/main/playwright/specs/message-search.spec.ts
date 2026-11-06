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
 * AH-119 (Stage B) — message search in the agency inbox, on a real stack.
 *
 *   1. The creator sends a Direct message and a campaign message that share a rare word, and a
 *      third message without it.
 *   2. The agency opens Messages with both flags ON. One character shows the minimum hint and
 *      the network sees no search; the full word shows the "Messages" group with the two hits
 *      (never the third), each "in {creator}".
 *   3. A prefix of the word finds them too (prefix match).
 *   4. A hit opens its conversation (Direct and campaign).
 *   5. A term that matches nothing says "No messages match".
 *   6. With the Stage B flag OFF the field is the client-side filter and `message-search` is
 *      never requested.
 *
 * Flags are restored in `afterEach`.
 */

const PASSWORD = 'Cata1yst-Search-E2E!'
const API = 'http://127.0.0.1:8000/api/v1'
const INBOX_FLAG = 'messages_v2_inbox_enabled'
const STAGE_B_FLAG = 'messages_v2_edit_search_enabled'
const WORD = 'zeppelinbrief'

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

async function seedConversations(page: Page) {
  const request = page.context().request
  const creatorEmail = uniqueEmail('search-creator')
  const admin = await seedAgencyAdmin(request)
  await signUpUser(request, creatorEmail, PASSWORD, 'Search Creator')
  await verifyEmailViaApi(request, creatorEmail)
  const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
    campaignName: 'Search Campaign',
    brandName: 'Search Brand',
  })
  await signIn(page, creatorEmail, PASSWORD)
  const posts: Array<[string, string]> = [
    [
      `${API}/creators/me/relationship-threads/${seeded.agencyUlid}/messages`,
      `Direct note about the ${WORD} for you.`,
    ],
    [
      `${API}/creators/me/assignments/${seeded.assignmentUlid}/messages`,
      `Campaign note: the ${WORD} is attached.`,
    ],
    [
      `${API}/creators/me/assignments/${seeded.assignmentUlid}/messages`,
      'Nothing to see in this one.',
    ],
  ]
  for (const [url, body] of posts) {
    const res = await request.post(url, { headers: await xsrfHeaders(page), data: { body } })
    expect(res.status(), `${url}: ${await res.text()}`).toBe(201)
  }
  await signOutViaApi(request)
  return { admin, seeded }
}

test.describe('AH-119 Stage B — message search', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    const request = page.context().request
    await setFeatureFlag(request, STAGE_B_FLAG, false)
    await setFeatureFlag(request, INBOX_FLAG, false)
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('minimum length, hits in both thread kinds, prefix, open a hit, empty result', async ({
    page,
  }) => {
    test.setTimeout(240_000)
    const request = page.context().request
    await setFeatureFlag(request, INBOX_FLAG, true)
    await setFeatureFlag(request, STAGE_B_FLAG, true)
    const { admin } = await seedConversations(page)

    const searches: string[] = []
    page.on('request', (r) => {
      if (r.url().includes('/message-search')) {
        searches.push(r.url())
      }
    })

    await signIn(page, admin.email, admin.password)
    await page.goto('/messages')
    await expect(page.locator('[data-test="unified-inbox"]')).toBeVisible({ timeout: 15_000 })
    const field = page.locator('[data-test="agency-inbox-search"] input')
    const results = page.locator('[data-test="message-search-results"]')

    // One character: the hint, no request.
    await field.fill('z')
    await expect(page.locator('[data-test="message-search-min"]')).toBeVisible()
    await page.waitForTimeout(600)
    expect(searches).toHaveLength(0)

    // The whole word: both hits, not the third message.
    const found = page.waitForResponse(
      (r) => r.url().includes('/message-search') && r.status() === 200,
    )
    await field.fill(WORD)
    await found
    await expect(results.locator('.agency-msg-search__heading')).toHaveText('Messages')
    const hits = results.locator(
      '[data-test^="message-hit-"]:not([data-test="message-hit-snippet"])',
    )
    await expect(hits).toHaveCount(2, { timeout: 15_000 })
    // A creator with no finished profile reads as "Participant", exactly as the inbox row does.
    await expect(results).toContainText('in Participant')
    await expect(results).not.toContainText('Nothing to see in this one.')

    // A prefix finds them as well.
    await field.fill(WORD.slice(0, 7))
    await expect(hits).toHaveCount(2, { timeout: 15_000 })

    // Open the campaign hit, then the Direct hit.
    await field.fill(WORD)
    await expect(hits).toHaveCount(2, { timeout: 15_000 })
    await results.locator('a[href*="/messages/c/"]').first().click()
    await expect(page).toHaveURL(/\/messages\/c\/[^/]+\/[^/]+/)
    await expect(page.locator('[data-test="relationship-thread"]')).toBeVisible({ timeout: 15_000 })
    await expect(page.locator('body')).toContainText(WORD)

    // Nothing matches.
    await field.fill('qqqnomatchqqq')
    await expect(page.locator('[data-test="message-search-none"]')).toHaveText(
      'No messages match',
      {
        timeout: 15_000,
      },
    )
  })

  test('with the Stage B flag OFF the field only filters the list and nothing is searched', async ({
    page,
  }) => {
    test.setTimeout(240_000)
    const request = page.context().request
    await setFeatureFlag(request, INBOX_FLAG, true)
    await setFeatureFlag(request, STAGE_B_FLAG, false)
    const { admin } = await seedConversations(page)

    const searches: string[] = []
    page.on('request', (r) => {
      if (r.url().includes('/message-search')) {
        searches.push(r.url())
      }
    })

    await signIn(page, admin.email, admin.password)
    await page.goto('/messages')
    await expect(page.locator('[data-test="unified-inbox"]')).toBeVisible({ timeout: 15_000 })
    await page.locator('[data-test="agency-inbox-search"] input').fill(WORD)
    await page.waitForTimeout(800)
    expect(searches).toHaveLength(0)
    await expect(page.locator('[data-test="message-search-results"]')).toHaveCount(0)
  })
})
