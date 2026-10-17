import { expect, test } from '../fixtures/test'

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
      // The trailing bold fragment exercises AH-081's real trust-boundary
      // conversion end to end: this is agency-authored markdown, sanitized
      // by RichBrief, rendered in the CREATOR's browser below.
      description:
        'Three short-form videos per month, shot in your own kitchen. **Bring your own props.**',
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
    // AH-081 — the markdown became real HTML, not literal asterisks. This is
    // the c3 leg's proof that the job-detail trust-boundary conversion holds
    // end to end, not just in the unit-level sanitizer specs.
    await expect(page.locator('[data-testid="creator-job-detail-description"] strong')).toHaveText(
      'Bring your own props.',
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

  // ── Campaign attachments from the Files library, chunk B (AH-092, S8) ─────
  //
  // Render-only leg (the build-time feasibility gate, Q3): the two rows are
  // seeded directly via factories (seedListedJob's `attachments` option), NOT
  // the presigned-upload flow. The download-click itself is NOT joined here —
  // the E2E `media` disk is `local` (see `playwright.config.ts`'s
  // `MEDIA_DISK_DRIVER` note), so `AgencyFileUploadService::signedDownloadUrl`
  // returns null in this environment (it only mints against a real S3
  // adapter) and there is no missing receiver to click through. That gap is
  // AH-091 debt #3, extended rather than duplicated — see docs/tech-debt.md.
  test('the visible attachment renders on the job detail page; the not-visible one is absent', async ({
    page,
  }) => {
    const request = page.context().request
    const email = uniqueEmail()

    await signUpUser(request, email, PASSWORD, 'Jobs Board Attachments E2E')
    await verifyEmailViaApi(request, email)

    const job = await seedListedJob(request, email, {
      campaignName: 'Winter Lookbook',
      attachments: [
        { filename: 'brief-visible.pdf', visible: true },
        { filename: 'internal-notes.pdf', visible: false },
      ],
    })

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    await page.goto(`/creator/jobs/${job.campaignUlid}`)
    await expect(page.locator('[data-testid="creator-job-detail-name"]')).toHaveText(
      'Winter Lookbook',
    )

    const block = page.locator('[data-testid="creator-attachments-block"]')
    await expect(block).toBeVisible({ timeout: 10_000 })
    await expect(block).toContainText('brief-visible.pdf')
    // The not-visible row is absent — not present-but-hidden, not blank —
    // from the exact-keyset the server sends (review priority #1).
    await expect(block).not.toContainText('internal-notes.pdf')
  })

  // AH-106 (item 2) — the "Applied" chip was cut off on a real phone. Every
  // assertion above reads `toHaveText('Applied')`, which matches the DOM text
  // and cannot see a chip that is rendered 40px wide with its label clipped, so
  // this leg measures instead. The title is long on purpose: the chip is only
  // squeezed when the row overflows (a short title never triggered it).
  //
  // Reproduced, and the fix verified, in real Chromium against Vuetify's own chip
  // CSS by `playwright-report/ah106-chip-repro.mjs` (before: 23-44px of label cut
  // off; after: 0). This leg is the same fact against the real page.
  test('AH-106 — the Applied chip is not clipped on a long-titled card at phone width', async ({
    page,
  }) => {
    const request = page.context().request
    const email = uniqueEmail()

    await signUpUser(request, email, PASSWORD, 'Jobs Board Chip E2E')
    await verifyEmailViaApi(request, email)

    const job = await seedListedJob(request, email, {
      campaignName: 'Autumn UGC push for the whole family kitchen collection 2026',
      brandName: 'Northwind Coffee',
      listingFee: '€300 per video',
      listingDuration: '4 weeks',
      description: 'Long title, so the card row overflows at phone width.',
    })

    await page.setViewportSize({ width: 360, height: 740 })

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    await page.goto(`/creator/jobs/${job.campaignUlid}`)
    await page.locator('[data-testid="creator-job-apply"]').click()
    await page.locator('[data-testid="creator-job-apply-submit"]').click()
    await expect(page.locator('[data-testid="creator-job-applied-notice"]')).toBeVisible({
      timeout: 10_000,
    })

    await page.goto('/creator/jobs')
    const chip = page.locator(`[data-testid="creator-job-applied-${job.campaignUlid}"]`)
    await expect(chip).toHaveText('Applied', { timeout: 10_000 })

    const cutOff = await chip.evaluate((el) => el.scrollWidth - el.clientWidth)
    expect(cutOff, 'the Applied chip clips its own label').toBeLessThanOrEqual(1)

    // And the chip sits inside its card rather than hanging off it.
    const card = await page.locator(`[data-testid="creator-job-${job.campaignUlid}"]`).boundingBox()
    const box = await chip.boundingBox()
    expect(card).not.toBeNull()
    expect(box).not.toBeNull()
    expect((box?.x ?? 0) + (box?.width ?? 0)).toBeLessThanOrEqual(
      (card?.x ?? 0) + (card?.width ?? 0) + 1,
    )
  })
})
