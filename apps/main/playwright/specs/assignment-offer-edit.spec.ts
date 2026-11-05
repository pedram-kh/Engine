import type { Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  editOfferAsAgency,
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
 * AH-118 (item 2) — the agency edits an assignment's offer amount, the creator
 * sees it (assignment page, conversation history line, in-app notification and
 * its deep link), and an offer that is past the lock is refused.
 *
 * Provisioning goes through `contracted-assignment` with `status: invited`
 * (an editable EUR 100.00 offer) and the default `contracted` (locked). The
 * flag is the real Pennant flag, switched through the allow-listed helper and
 * restored in `afterEach`.
 */

const CREATOR_PASSWORD = 'Cata1yst-OfferEdit-E2E!'
const FLAG = 'assignment_offer_edit_enabled'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

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

test.describe('AH-118 — editing an offer amount', () => {
  test.beforeEach(async ({ page }) => {
    await neutralizeThrottle(page.context().request, 'auth-ip')
  })

  test.afterEach(async ({ page }) => {
    await setFeatureFlag(page.context().request, FLAG, false)
    await restoreThrottle(page.context().request, 'auth-ip')
    await signOutViaApi(page.context().request)
  })

  test('the agency edits an invited offer; the creator sees the new amount, the history line and the notification', async ({
    page,
  }) => {
    test.setTimeout(150_000)
    const request = page.context().request
    const creatorEmail = uniqueEmail('offer-edit-creator')

    await setFeatureFlag(request, FLAG, true)
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, CREATOR_PASSWORD, 'Offer Edit')
    await verifyEmailViaApi(request, creatorEmail)
    const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: 'Offer edit campaign',
      status: 'invited',
    })

    // ── The agency edits 100.00 → 250.50 through the real dialog ────────────
    await signInAsAgency(page, admin.email, admin.password)
    await editOfferAsAgency(page, seeded.campaignUlid, seeded.assignmentUlid, '250.5')
    // Re-read from the server, not local optimism.
    await expect(
      page.locator('[data-test="panel-creators"], [data-test="tab-creators"]').first(),
    ).toBeVisible()
    await expect(page.locator('body')).toContainText('€250.50', { timeout: 15_000 })

    // ── The creator sees it ─────────────────────────────────────────────────
    await signOutViaApi(request)
    await signInAsCreator(page, creatorEmail, CREATOR_PASSWORD)

    await page.goto(`/creator/assignments/${seeded.assignmentUlid}`)
    await expect(page.locator('body')).toContainText('€250.50', { timeout: 15_000 })
    await expect(page.locator('body')).toContainText('Fee: €250.50')
    // The history line in the conversation, formatted from the structured data.
    await expect(page.locator('body')).toContainText('The offer changed from €100.00 to €250.50.', {
      timeout: 15_000,
    })

    // The in-app notification, and its tap lands on the assignment.
    await page.goto('/creator/dashboard')
    await page.locator('[data-test="notification-bell-btn"]').click()
    const row = page.locator('[data-test~="notification-row--unread"]').first()
    await expect(row).toBeVisible({ timeout: 15_000 })
    await expect(row).toContainText('€250.50')
    await row.click()
    await expect(page).toHaveURL(new RegExp(`/creator/assignments/${seeded.assignmentUlid}`), {
      timeout: 10_000,
    })
  })

  test('an offer past the lock opens the dialog locked with the reason, and a direct PATCH is refused', async ({
    page,
  }) => {
    test.setTimeout(120_000)
    const request = page.context().request
    const creatorEmail = uniqueEmail('offer-lock-creator')

    await setFeatureFlag(request, FLAG, true)
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, CREATOR_PASSWORD, 'Offer Lock')
    await verifyEmailViaApi(request, creatorEmail)
    // A contract is waiting for the creator: past the lock (Q1, `contract_pending`).
    const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: 'Offer lock campaign',
      status: 'invited',
      contractSent: true,
    })

    await signInAsAgency(page, admin.email, admin.password)
    await page.goto(`/campaigns/${seeded.campaignUlid}`)
    await page.locator('[data-test="tab-creators"]').click()

    const edit = page.locator(`[data-test="creators-edit-offer-${seeded.assignmentUlid}"]`)
    await expect(edit).toBeVisible({ timeout: 15_000 })
    await edit.click()
    await expect(page.locator('[data-test="edit-offer-locked"]')).toBeVisible()
    await expect(page.locator('[data-test="edit-offer-locked"]')).toHaveAttribute(
      'data-reason',
      'contract_pending',
    )
    await expect(page.locator('[data-test="edit-offer-submit"]')).toBeDisabled()

    // The server is the boundary: a PATCH past the UI is refused with the code.
    const cookies = await page.context().cookies()
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN')
    const refused = await request.patch(
      `http://127.0.0.1:8000/api/v1/agencies/${seeded.agencyUlid}/campaigns/${seeded.campaignUlid}/assignments/${seeded.assignmentUlid}/offer`,
      {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          Referer: 'http://127.0.0.1:5173/',
          'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.value) : '',
        },
        data: { agreed_fee_minor_units: 25000 },
      },
    )
    expect(refused.status()).toBe(422)
    expect(await refused.text()).toContain('assignment.offer_locked')
    expect(await refused.text()).toContain('contract_pending')
  })

  test('with the edit flag OFF there is no Edit offer entry point, and the endpoint 404s', async ({
    page,
  }) => {
    const request = page.context().request
    const creatorEmail = uniqueEmail('offer-off-creator')

    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, CREATOR_PASSWORD, 'Offer Off')
    await verifyEmailViaApi(request, creatorEmail)
    const seeded = await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      status: 'invited',
    })

    await signInAsAgency(page, admin.email, admin.password)
    await page.goto(`/campaigns/${seeded.campaignUlid}`)
    await page.locator('[data-test="tab-creators"]').click()
    await expect(
      page.locator('[data-test="creators-list"], [data-test="panel-creators"]').first(),
    ).toBeVisible({
      timeout: 15_000,
    })
    await expect(
      page.locator(`[data-test="creators-edit-offer-${seeded.assignmentUlid}"]`),
    ).toHaveCount(0)

    const cookies = await page.context().cookies()
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN')
    const off = await request.patch(
      `http://127.0.0.1:8000/api/v1/agencies/${seeded.agencyUlid}/campaigns/${seeded.campaignUlid}/assignments/${seeded.assignmentUlid}/offer`,
      {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          Referer: 'http://127.0.0.1:5173/',
          'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.value) : '',
        },
        data: { agreed_fee_minor_units: 25000 },
      },
    )
    expect(off.status()).toBe(404)
  })
})
