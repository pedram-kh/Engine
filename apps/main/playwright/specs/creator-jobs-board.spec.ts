import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedListedJob,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * Jobs Board chunk 3 (AH-056, D10) — the creator's browse → detail → apply loop,
 * end to end against a real API.
 *
 * The unit specs mount the pages with a mocked client and the feature tests
 * drive the endpoints with no browser, so the one thing neither can see is the
 * seam between them: the nav item that only appears for an approved creator,
 * the route that carries it, the client that builds the URL, the resource the
 * server emits, and the 409 the second tap earns. That seam is what this leg
 * walks.
 *
 * Provisioning goes through the `_test/creators/listed-job` helper — see its
 * controller docblock for why the production path is not a reasonable way to
 * reach a listed job.
 */

const PASSWORD = 'Cata1yst-JobsBoard-E2E!'

function uniqueEmail(): string {
  return `jobs-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('AH-056 — creator jobs board', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('an approved rostered creator browses the board, opens a job, and applies', async ({
    page,
  }) => {
    const request = page.context().request
    const email = uniqueEmail()

    await signUpUser(request, email, PASSWORD, 'Jobs Board E2E')
    await verifyEmailViaApi(request, email)

    const job = await seedListedJob(request, email, {
      campaignName: 'Autumn UGC push',
      brandName: 'Northwind Coffee',
      listingFee: '€300 per video',
      listingDuration: '4 weeks',
      description: 'Three short-form videos per month, shot in your own kitchen.',
    })

    // Sign in through the SPA so Sanctum's stateful pipeline engages (the
    // creator-dashboard spec's note applies verbatim).
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    // ── The nav item (D9) — approved-only, and it is how the board is reached.
    await page.goto('/creator/dashboard')
    const navJobs = page.locator('[data-test="creator-nav-jobs"]')
    await expect(navJobs).toBeVisible({ timeout: 10_000 })
    await navJobs.click()
    await expect(page).toHaveURL(/\/creator\/jobs$/)

    // ── The card (D4) — brand subset, fee, applicant count, recency chip.
    const card = page.locator(`[data-testid="creator-job-${job.campaignUlid}"]`)
    await expect(card).toBeVisible({ timeout: 10_000 })
    await expect(page.locator(`[data-testid="creator-job-brand-${job.campaignUlid}"]`)).toHaveText(
      'Northwind Coffee',
    )
    await expect(page.locator(`[data-testid="creator-job-fee-${job.campaignUlid}"]`)).toContainText(
      '€300 per video',
    )
    await expect(
      page.locator(`[data-testid="creator-job-applicants-${job.campaignUlid}"]`),
    ).toHaveText('0 applicants')
    await expect(
      page.locator(`[data-testid="creator-job-recency-${job.campaignUlid}"]`),
    ).toHaveText('Listed today')

    // ── The detail page (D3/D4).
    await card.click()
    await expect(page).toHaveURL(new RegExp(`/creator/jobs/${job.campaignUlid}$`))
    await expect(page.locator('[data-testid="creator-job-detail-name"]')).toHaveText(
      'Autumn UGC push',
    )
    await expect(page.locator('[data-testid="creator-job-detail-description"]')).toContainText(
      'Three short-form videos per month',
    )
    // The third and last brand field allowed to cross to a creator (D3).
    await expect(page.locator('[data-testid="creator-job-website"]')).toHaveAttribute(
      'href',
      'https://northwind.example',
    )

    // ── Apply, with a note (D5).
    await page.locator('[data-testid="creator-job-apply"]').click()
    await page
      .locator('[data-testid="creator-job-apply-note"]')
      .locator('textarea')
      .first()
      .fill('I shoot food content weekly.')
    await page.locator('[data-testid="creator-job-apply-submit"]').click()

    await expect(page.locator('[data-testid="creator-job-snackbar"]')).toContainText(
      'Application sent.',
      { timeout: 10_000 },
    )

    // The page re-reads after the write, so the applied state and the moved
    // applicant count both come from the server, not from local optimism.
    await expect(page.locator('[data-testid="creator-job-applied-notice"]')).toBeVisible()
    await expect(page.locator('[data-testid="creator-job-apply"]')).toHaveCount(0)
    await expect(page.locator('[data-testid="creator-job-detail-applicants"]')).toHaveText(
      '1 applicant',
    )

    // ── The board reflects it too (the caller's own status, annotated server-side).
    await page.goto('/creator/jobs')
    await expect(
      page.locator(`[data-testid="creator-job-applied-${job.campaignUlid}"]`),
    ).toHaveText('Applied')

    // ── The second tap is refused by the SERVER, not by the hidden button (D5).
    // The UI no longer offers Apply, so the refusal is asserted where it lives:
    // a direct POST past the UI earns the 409 and its code. Referer +
    // X-XSRF-TOKEN are forwarded so Sanctum's stateful gate clears (the
    // seedAvatar precedent).
    const cookies = await page.context().cookies()
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN')
    const duplicate = await request.post(
      `http://127.0.0.1:8000/api/v1/creators/me/jobs/${job.campaignUlid}/apply`,
      {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          Referer: 'http://127.0.0.1:5173/',
          'X-XSRF-TOKEN': xsrfCookie ? decodeURIComponent(xsrfCookie.value) : '',
        },
        data: {},
      },
    )
    expect(duplicate.status()).toBe(409)
    expect(await duplicate.text()).toContain('job.already_applied')
  })
})
