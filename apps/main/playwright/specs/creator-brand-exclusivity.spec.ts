import type { APIRequestContext, Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedContractedAssignment,
  seedListableCampaign,
  seedListedJob,
  setFeatureFlag,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * AH-120 — brand exclusivity between campaigns, end to end against a real API.
 *
 * A creator contracted on "Zava Summer", which blocks the Yahoo brand, cannot apply to Yahoo's
 * campaigns; the job stays visible and says only "Not available to you". The unit specs mount
 * the pages with a mocked client and the Pest suite drives the endpoints with no browser, so
 * the seam walked here is the flag, the `/me` features, the job-detail payload, the Apply
 * refusal and the agency Applications badge, all in one real stack.
 *
 * Flag: `campaign_brand_exclusivity_enabled`, OFF by default and reset after every test.
 */

const PASSWORD = 'Cata1yst-Exclusivity-E2E!'
const FLAG = 'campaign_brand_exclusivity_enabled'
const BLOCKER = 'Zava Summer'

function uniqueEmail(tag: string): string {
  return `${tag}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

async function signInAs(page: Page, email: string, password = PASSWORD): Promise<void> {
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 15_000 })
}

/** A real creator with a listed Yahoo job on a fresh agency. */
async function seedCreatorWithYahooJob(request: APIRequestContext) {
  const email = uniqueEmail('excl')
  await signUpUser(request, email, PASSWORD, 'Exclusivity E2E')
  await verifyEmailViaApi(request, email)
  const job = await seedListedJob(request, email, {
    campaignName: 'Yahoo Spring',
    brandName: 'Yahoo',
  })
  return { email, job }
}

async function postApply(page: Page, jobUlid: string) {
  const cookies = await page.context().cookies()
  const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN')
  return page
    .context()
    .request.post(`http://127.0.0.1:8000/api/v1/creators/me/jobs/${jobUlid}/apply`, {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        Referer: 'http://127.0.0.1:5173/',
        'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.value) : '',
      },
      data: {},
    })
}

test.describe('AH-120 — brand exclusivity', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    await setFeatureFlag(request, FLAG, false)
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('flag ON: the blocked job stays visible, says "Not available to you", and Apply is refused', async ({
    page,
  }) => {
    const request = page.context().request
    const { email, job } = await seedCreatorWithYahooJob(request)
    await seedContractedAssignment(request, job.agencyUlid, email, {
      campaignName: BLOCKER,
      exclusiveBrandIds: [job.brandUlid],
    })
    await setFeatureFlag(request, FLAG, true)

    await signInAs(page, email)
    await page.goto('/creator/jobs')
    await expect(page.locator(`[data-testid="creator-job-${job.campaignUlid}"]`)).toBeVisible({
      timeout: 15_000,
    })

    await page.goto(`/creator/jobs/${job.campaignUlid}`)
    const locked = page.locator('[data-testid="creator-job-brand-locked"]')
    await expect(locked).toHaveText('Not available to you', { timeout: 15_000 })
    await expect(page.locator('[data-testid="creator-job-apply"]')).toHaveCount(0)
    // The blocking campaign is never named anywhere on the page, and there is nowhere to go.
    await expect(page.locator('body')).not.toContainText(BLOCKER)
    await expect(locked.locator('a')).toHaveCount(0)

    // The refusal lives on the server, not in the hidden button.
    const refused = await postApply(page, job.campaignUlid)
    expect(refused.status()).toBe(422)
    expect(await refused.text()).toContain('job.brand_exclusive')
    expect(await refused.text()).not.toContain(BLOCKER)
  })

  test('a block whose end date has passed lifts: Apply is back', async ({ page }) => {
    const request = page.context().request
    const { email, job } = await seedCreatorWithYahooJob(request)
    await seedContractedAssignment(request, job.agencyUlid, email, {
      campaignName: BLOCKER,
      exclusiveBrandIds: [job.brandUlid],
      exclusivityEndsOn: '2020-01-01',
    })
    await setFeatureFlag(request, FLAG, true)

    await signInAs(page, email)
    await page.goto(`/creator/jobs/${job.campaignUlid}`)

    await expect(page.locator('[data-testid="creator-job-apply"]')).toBeVisible({
      timeout: 15_000,
    })
    await expect(page.locator('[data-testid="creator-job-brand-locked"]')).toHaveCount(0)
  })

  test('flag OFF: the same data blocks nothing, and the application goes through', async ({
    page,
  }) => {
    const request = page.context().request
    const { email, job } = await seedCreatorWithYahooJob(request)
    await seedContractedAssignment(request, job.agencyUlid, email, {
      campaignName: BLOCKER,
      exclusiveBrandIds: [job.brandUlid],
    })

    await signInAs(page, email)
    await page.goto(`/creator/jobs/${job.campaignUlid}`)
    await expect(page.locator('[data-testid="creator-job-brand-locked"]')).toHaveCount(0)
    await page.locator('[data-testid="creator-job-apply"]').click()
    await page.locator('[data-testid="creator-job-apply-submit"]').click()

    await expect(page.locator('[data-testid="creator-job-applied-notice"]')).toBeVisible({
      timeout: 10_000,
    })
  })

  test('an application made before the block keeps reading "Applied"', async ({ page }) => {
    const request = page.context().request
    const { email, job } = await seedCreatorWithYahooJob(request)
    await setFeatureFlag(request, FLAG, true)

    await signInAs(page, email)
    await page.goto(`/creator/jobs/${job.campaignUlid}`)
    await page.locator('[data-testid="creator-job-apply"]').click()
    await page.locator('[data-testid="creator-job-apply-submit"]').click()
    await expect(page.locator('[data-testid="creator-job-applied-notice"]')).toBeVisible({
      timeout: 10_000,
    })

    // Selected elsewhere afterwards: the existing application is not rewritten.
    await seedContractedAssignment(request, job.agencyUlid, email, {
      campaignName: BLOCKER,
      exclusiveBrandIds: [job.brandUlid],
    })
    await page.reload()

    await expect(page.locator('[data-testid="creator-job-applied-notice"]')).toBeVisible({
      timeout: 15_000,
    })
    await expect(page.locator('[data-testid="creator-job-brand-locked"]')).toHaveCount(0)
    await page.goto('/creator/jobs')
    await expect(
      page.locator(`[data-testid="creator-job-applied-${job.campaignUlid}"]`),
    ).toHaveText('Applied')
  })

  test('agency: a pending applicant selected on a blocking campaign carries a Conflict badge', async ({
    page,
  }) => {
    test.setTimeout(120_000)
    const request = page.context().request
    const creatorEmail = uniqueEmail('excl-agency')
    const admin = await seedAgencyAdmin(request)
    await signUpUser(request, creatorEmail, PASSWORD, 'Exclusivity Agency E2E')
    await verifyEmailViaApi(request, creatorEmail)
    const listable = await seedListableCampaign(request, admin.agencyUlid, creatorEmail, {
      campaignName: 'Yahoo Spring',
      brandName: 'Yahoo',
    })
    await setFeatureFlag(request, FLAG, true)

    // The agency lists the campaign (the same toggle the full-lifecycle spec walks).
    await signInAs(page, admin.email, admin.password)
    await page.goto('/campaigns')
    const toggle = page.locator(`[data-test="campaign-job-board-toggle-${listable.campaignUlid}"]`)
    await expect(toggle).toBeVisible({ timeout: 15_000 })
    await toggle.locator('input').click()
    await page.locator('[data-test="campaign-listing-confirm-submit"]').click()
    await expect(toggle.locator('input')).toBeChecked({ timeout: 10_000 })

    // The creator applies while nothing blocks them.
    await signOutViaApi(request)
    await signInAs(page, creatorEmail)
    await page.goto(`/creator/jobs/${listable.campaignUlid}`)
    await page.locator('[data-testid="creator-job-apply"]').click()
    await page.locator('[data-testid="creator-job-apply-submit"]').click()
    await expect(page.locator('[data-testid="creator-job-applied-notice"]')).toBeVisible({
      timeout: 10_000,
    })

    // Then the same agency selects them on a campaign that blocks the Yahoo brand.
    await seedContractedAssignment(request, admin.agencyUlid, creatorEmail, {
      campaignName: BLOCKER,
      exclusiveBrandIds: [listable.brandUlid],
    })

    await signOutViaApi(request)
    await signInAs(page, admin.email, admin.password)
    await page.goto(`/campaigns/${listable.campaignUlid}`)
    await page.locator('[data-test="tab-applications"]').click()

    const badge = page.locator('[data-test^="applications-conflict-"]')
    await expect(badge).toBeVisible({ timeout: 15_000 })
    await expect(badge).toContainText('Conflict')
    // Advisory only: the agency can still accept.
    await expect(page.locator('[data-test^="applications-accept-"]').first()).toBeEnabled()
  })
})
