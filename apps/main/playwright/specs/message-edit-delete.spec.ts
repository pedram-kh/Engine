import type { Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  ageMessage,
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
 * AH-119 (Stage B) — edit and delete a sent message, on a real stack.
 *
 *   1. The agency opens an assignment conversation and sends a message.
 *   2. Edit (inside the 15-minute window): the "⋯" menu offers Edit, the new text is saved, the
 *      bubble shows "edited", and both survive a reload.
 *   3. After the message is aged past the window the menu offers no Edit, only the hint and
 *      Delete; a message from the creator carries no menu at all.
 *   4. Delete asks first; confirming leaves "Message deleted" (no text), also after a reload,
 *      and the creator's own API read gets the masked message (no body, `is_deleted`).
 *   5. With `messages_v2_edit_search_enabled` OFF no message carries a menu.
 *
 * Both Stage A (`messages_v2_inbox_enabled`) and Stage B flags are switched ON for the first
 * test and restored in `afterEach`.
 */

const PASSWORD = 'Cata1yst-EditDel-E2E!'
const API = 'http://127.0.0.1:8000/api/v1'
const INBOX_FLAG = 'messages_v2_inbox_enabled'
const STAGE_B_FLAG = 'messages_v2_edit_search_enabled'

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

async function sendAsAgency(page: Page, body: string): Promise<string> {
  await page
    .locator('[data-test="relationship-compose-body"] textarea.v-field__input:not([readonly])')
    .fill(body)
  await page.locator('[data-test="relationship-send"]').click()
  const own = page.locator('.agency-bubble-row--own', { hasText: body })
  await expect(own).toBeVisible({ timeout: 15_000 })
  const testId = await own.getAttribute('data-test')
  return (testId ?? '').replace('relationship-message-', '')
}

test.describe('AH-119 Stage B — edit and delete a message', () => {
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

  test('edit inside the window, no Edit after it, delete with confirmation', async ({ page }) => {
    test.setTimeout(240_000)
    const request = page.context().request
    const creatorEmail = uniqueEmail('editdel-creator')

    await setFeatureFlag(request, INBOX_FLAG, true)
    await setFeatureFlag(request, STAGE_B_FLAG, true)
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, PASSWORD, 'EditDel Creator')
    await verifyEmailViaApi(request, creatorEmail)
    const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: 'EditDel Campaign',
      brandName: 'EditDel Brand',
    })

    // The creator speaks first, so there is a message that is not the agency's own.
    await signIn(page, creatorEmail, PASSWORD)
    const hello = await request.post(
      `${API}/creators/me/assignments/${seeded.assignmentUlid}/messages`,
      { headers: await xsrfHeaders(page), data: { body: 'Hello from the creator.' } },
    )
    expect(hello.status(), await hello.text()).toBe(201)
    await signOutViaApi(request)

    await signIn(page, admin.email, admin.password)
    await page.goto(`/messages/c/${seeded.campaignUlid}/${seeded.assignmentUlid}`)
    await expect(page.locator('[data-test="relationship-thread"]')).toBeVisible({ timeout: 15_000 })
    await expect(page.locator('body')).toContainText('Hello from the creator.')

    // The creator's message has no menu.
    const theirs = page.locator('.agency-bubble-row:not(.agency-bubble-row--own)', {
      hasText: 'Hello from the creator.',
    })
    await expect(theirs.locator('[data-test="message-actions"]')).toHaveCount(0)

    // ── Step 1 and 2: send, then edit inside the window ─────────────────────
    const messageId = await sendAsAgency(page, 'Draft with a typo.')
    const row = page.locator(`[data-test="relationship-message-${messageId}"]`)
    await expect(row.locator('[data-test="message-edited"]')).toHaveCount(0)

    await row.locator('[data-test="message-actions"]').click()
    await page.locator('[data-test="message-action-edit"]').click()
    const input = page.locator(
      '[data-test="message-edit-input"] textarea.v-field__input:not([readonly])',
    )
    await expect(input).toHaveValue('Draft with a typo.')
    await input.fill('Draft without the typo.')
    await page.locator('[data-test="message-edit-save"]').click()

    await expect(row).toContainText('Draft without the typo.', { timeout: 15_000 })
    await expect(row.locator('[data-test="message-edited"]')).toBeVisible()
    await expect(row).not.toContainText('Draft with a typo.')

    await page.reload()
    await expect(row).toContainText('Draft without the typo.', { timeout: 15_000 })
    await expect(row.locator('[data-test="message-edited"]')).toBeVisible()

    // ── Step 3: past the window there is no Edit, only the hint and Delete ──
    await ageMessage(request, messageId, 16)
    await page.reload()
    await expect(row).toContainText('Draft without the typo.', { timeout: 15_000 })
    await row.locator('[data-test="message-actions"]').click()
    await expect(page.locator('[data-test="message-actions-menu"]')).toBeVisible()
    await expect(page.locator('[data-test="message-action-edit"]')).toHaveCount(0)
    await expect(page.locator('[data-test="message-action-edit-hint"]')).toBeVisible()
    await expect(page.locator('[data-test="message-action-delete"]')).toBeVisible()

    // ── Step 4: delete asks first, then leaves the placeholder ──────────────
    await page.locator('[data-test="message-action-delete"]').click()
    await expect(page.locator('[data-test="message-delete-dialog"]')).toBeVisible()
    await page.locator('[data-test="message-delete-cancel"]').click()
    await expect(row).toContainText('Draft without the typo.')

    await row.locator('[data-test="message-actions"]').click()
    await page.locator('[data-test="message-action-delete"]').click()
    // The delete is optimistic (the placeholder shows at once), so wait for the server's answer
    // before reloading: a reload now would abort the CSRF preflight and the DELETE never leaves.
    const deleted = page.waitForResponse(
      (r) => r.request().method() === 'DELETE' && r.url().includes(`/messages/${messageId}`),
    )
    await page.locator('[data-test="message-delete-confirm"]').click()
    expect((await deleted).status()).toBe(200)
    await expect(row.locator('[data-test="message-deleted"]')).toHaveText('Message deleted', {
      timeout: 15_000,
    })
    await expect(row).not.toContainText('Draft without the typo.')
    await expect(row.locator('[data-test="message-actions"]')).toHaveCount(0)

    await page.reload()
    await expect(row.locator('[data-test="message-deleted"]')).toBeVisible({ timeout: 15_000 })
    await expect(row).not.toContainText('Draft without the typo.')

    // The creator's read gets the masked message: no text, flagged deleted.
    await signOutViaApi(request)
    await signIn(page, creatorEmail, PASSWORD)
    const list = await request.get(
      `${API}/creators/me/assignments/${seeded.assignmentUlid}/messages`,
      { headers: await xsrfHeaders(page) },
    )
    expect(list.status()).toBe(200)
    const body = (await list.json()) as {
      data: Array<{ id: string; attributes: { body: string | null; is_deleted?: boolean } }>
    }
    const masked = body.data.find((m) => m.id === messageId)
    expect(masked?.attributes.is_deleted).toBe(true)
    expect(masked?.attributes.body ?? null).toBeNull()
  })

  test('with the Stage B flag OFF no message carries a menu', async ({ page }) => {
    test.setTimeout(180_000)
    const request = page.context().request
    const creatorEmail = uniqueEmail('editdel-off-creator')

    await setFeatureFlag(request, INBOX_FLAG, true)
    await setFeatureFlag(request, STAGE_B_FLAG, false)
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, PASSWORD, 'EditDel Off Creator')
    await verifyEmailViaApi(request, creatorEmail)
    const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail)

    await signIn(page, admin.email, admin.password)
    await page.goto(`/messages/c/${seeded.campaignUlid}/${seeded.assignmentUlid}`)
    await expect(page.locator('[data-test="relationship-thread"]')).toBeVisible({ timeout: 15_000 })
    await sendAsAgency(page, 'No menu on this one.')
    await expect(page.locator('[data-test="message-actions"]')).toHaveCount(0)
    await expect(page.locator('[data-test="message-edited"]')).toHaveCount(0)

    // The server agrees: PATCH is a 404 while the flag is off.
    const own = page.locator('.agency-bubble-row--own', { hasText: 'No menu on this one.' })
    const id = ((await own.getAttribute('data-test')) ?? '').replace('relationship-message-', '')
    const patch = await request.patch(
      `${API}/agencies/${admin.agencyUlid}/campaigns/${seeded.campaignUlid}/assignments/${seeded.assignmentUlid}/messages/${id}`,
      { headers: await xsrfHeaders(page), data: { body: 'sneaky' } },
    )
    expect(patch.status()).toBe(404)
  })
})
