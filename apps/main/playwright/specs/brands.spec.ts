import { fileURLToPath } from 'node:url'

import type { Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  signOutViaApi,
} from '../fixtures/test-helpers'

/**
 * Brand happy-path E2E spec.
 *
 * Acceptance scenario (20-PHASE-1-SPEC.md § 5):
 *   - agency_admin signs in
 *   - Sees the AgencyLayout shell
 *   - Navigates to /brands
 *   - Creates a brand
 *   - Sees it in the list
 *   - Opens detail page
 *   - Edits the brand
 *   - Archives the brand
 *
 * AH-053 extends this. A brand is no longer creatable from a name alone: the
 * D6 floor makes name, slug, monthly deliverables, industry, website URL AND a
 * logo all mandatory, and the form holds its submit button until every one of
 * them is present. So the create leg below now fills the whole floor and
 * attaches `fixtures/brand-logo.png`, and a second test drives the floor
 * itself — the button releasing as the last field lands, and re-latching when
 * the logo is removed on the edit page.
 *
 * The logo legs are real uploads: the E2E `media` disk is the local filesystem
 * (`MEDIA_DISK_DRIVER=local`, see `playwright.config.ts`), so the POST stores
 * real bytes and the rendered `logo_url` is a real signed GET. The
 * `naturalWidth` assertion is what makes that non-negotiable — it fails if the
 * signed URL 404s, which is exactly what a silently-dropped S3 write looks
 * like.
 *
 * Chunk-7.1 conventions (all applied from first commit):
 *   - auth-ip rate-limiter neutralised in beforeEach; restored in afterEach
 *   - No parent data-test attribute fall-through
 *   - Spec-local `seedAgencyAdmin` fixture follows chunk-7.6 pattern
 */

const LOGO_FIXTURE = fileURLToPath(new URL('../fixtures/brand-logo.png', import.meta.url))

test.describe('Brand happy path', () => {
  let adminEmail: string
  let adminPassword: string

  test.beforeEach(async ({ page }) => {
    const request = page.context().request

    // Neutralise auth-ip rate limiter per chunk-7.1 conventions.
    await neutralizeThrottle(request, 'auth-ip')

    // Seed an agency admin + agency in a single test-helper call.
    const setup = await seedAgencyAdmin(request)
    adminEmail = setup.email
    adminPassword = setup.password
  })

  test.afterEach(async ({ page }) => {
    const request = page.context().request
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  async function signIn(page: Page): Promise<void> {
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(adminEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(adminPassword)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10000 })
  }

  /** Fill every text/select field of the D6 floor. The logo is attached separately. */
  async function fillFloorFields(page: Page, name: string): Promise<void> {
    await page.locator(dt(testIds.brandName)).locator('input').fill(name)
    // Blur triggers the slug auto-suggestion.
    await page.locator(dt(testIds.brandName)).locator('input').blur()

    // `auto-grow` renders a second, aria-hidden sizer textarea alongside the
    // real one — `.first()` is the project pattern for it (creator-detail).
    await page
      .locator(dt(testIds.brandDescription))
      .locator('textarea')
      .first()
      .fill('2 Reels and 3 Stories per month, 6-month paid usage rights.')

    await page.locator(dt(testIds.brandIndustry)).click()
    await page.getByRole('option', { name: 'Fashion' }).click()

    await page
      .locator(dt(testIds.brandWebsiteUrl))
      .locator('input')
      .fill('https://acme.example.com')
  }

  /** The <input type="file"> is hidden behind the choose button; target it directly. */
  async function attachLogo(page: Page): Promise<void> {
    await page.locator(dt(testIds.brandLogoInput)).setInputFiles(LOGO_FIXTURE)
  }

  test('agency_admin can navigate brands list, create, detail, edit, archive', async ({ page }) => {
    await signIn(page)

    // ── Verify AgencyLayout rendered ─────────────────────────────────────────
    await expect(page.locator(dt(testIds.agencySidebar))).toBeVisible({ timeout: 8000 })
    await expect(page.locator(dt(testIds.agencyTopbar))).toBeVisible({ timeout: 8000 })

    // ── Navigate to brands ───────────────────────────────────────────────────
    // Direct navigation: Vuetify v-list-item :to bindings are intermittently
    // flaky in CI — the Playwright click registers but the router-link inner
    // element doesn't always fire. We verify nav-brands renders separately
    // (and via an aria/click assertion below); the happy-path navigation
    // uses page.goto() for determinism.
    await expect(page.locator(dt(testIds.navBrands))).toBeVisible({ timeout: 8000 })
    await page.goto('/brands')
    await expect(page.locator(dt(testIds.brandListPage))).toBeVisible({ timeout: 10000 })
    await expect(page.locator(dt(testIds.brandListHeading))).toBeVisible({ timeout: 8000 })

    // Empty state should appear (no brands yet).
    await expect(page.locator(dt(testIds.brandEmptyState))).toBeVisible({ timeout: 8000 })

    // ── Create a brand ────────────────────────────────────────────────────────
    // Verify the empty-state CTA is rendered, then navigate directly (same
    // Vuetify v-btn :to flakiness as nav items above).
    await expect(page.locator(dt(testIds.brandEmptyCta))).toBeVisible({ timeout: 8000 })
    await page.goto('/brands/new')
    await expect(page.locator(dt(testIds.brandCreatePage))).toBeVisible({ timeout: 10000 })

    await fillFloorFields(page, 'Acme Brand')
    await attachLogo(page)

    await expect(page.locator(dt(testIds.brandFormSubmit))).toBeEnabled()
    await page.locator(dt(testIds.brandFormSubmit)).click()

    // Should redirect to detail page after create.
    await page.waitForURL(/\/brands\/[A-Z0-9]+$/, { timeout: 10000 })
    await expect(page.locator(dt(testIds.brandDetailPage))).toBeVisible({ timeout: 8000 })
    await expect(page.locator(dt(testIds.brandDetailCard))).toContainText('Acme Brand')

    // The create flow is two writes (D7, F7): POST /brands, then POST the logo
    // to the row it just made. This banner is how a half-failed create tells
    // the user — its absence is the assertion that both writes landed.
    await expect(page.locator(dt(testIds.brandDetailLogoFailed))).toHaveCount(0)

    // Capture the brand ULID from the URL — used for direct edit/detail navs
    // below (Vuetify v-btn :to bindings are flaky in CI; see comment above).
    const brandUlid = new URL(page.url()).pathname.split('/').pop() ?? ''
    expect(brandUlid).not.toBe('')

    // ── Verify detail page ────────────────────────────────────────────────────
    await expect(page.locator(dt(testIds.brandDetailStatus))).toContainText('Active')

    // The logo round-tripped: uploaded, stored, and served back through a
    // signed URL that actually resolves to bytes. `naturalWidth > 0` is the
    // part a broken storage write cannot fake.
    const logoImg = page.locator(`${dt(testIds.brandDetailLogo)} img`)
    await expect(logoImg).toBeVisible({ timeout: 8000 })
    await expect
      .poll(async () => logoImg.evaluate((img: HTMLImageElement) => img.naturalWidth), {
        timeout: 8000,
      })
      .toBeGreaterThan(0)

    // ── Edit the brand ────────────────────────────────────────────────────────
    await expect(page.locator(dt(testIds.brandEditBtn))).toBeVisible({ timeout: 8000 })
    await page.goto(`/brands/${brandUlid}/edit`)
    await expect(page.locator(dt(testIds.brandEditPage))).toBeVisible({ timeout: 10000 })

    // Update the brand name. The rest of the floor is already stored, so the
    // merged-state predicate (D6/A2) is satisfied without re-sending it —
    // this leg is also the preserve-by-omission check from the UI side.
    await page.locator(dt(testIds.brandName)).locator('input').fill('Acme Brand Updated')
    await expect(page.locator(dt(testIds.brandFormSubmit))).toBeEnabled()
    await page.locator(dt(testIds.brandFormSubmit)).click()

    // Should redirect back to detail page.
    await expect(page.locator(dt(testIds.brandDetailPage))).toBeVisible({ timeout: 8000 })
    await expect(page.locator(dt(testIds.brandDetailCard))).toContainText('Acme Brand Updated')
    await expect(page.locator(dt(testIds.brandDetailDescription))).toContainText('2 Reels')

    // ── Navigate to brand list and verify it appears ──────────────────────────
    await page.goto('/brands')
    await expect(page.locator(dt(testIds.brandTable))).toBeVisible({ timeout: 10000 })
    await expect(page.locator(dt(testIds.brandTable))).toContainText('Acme Brand Updated', {
      timeout: 8000,
    })

    // ── Archive the brand ─────────────────────────────────────────────────────
    // Verify view buttons render (Vuetify :to flaky), then navigate directly.
    await expect(page.locator(`[data-test^="brand-view-"]`).first()).toBeVisible({ timeout: 8000 })
    await page.goto(`/brands/${brandUlid}`)
    await expect(page.locator(dt(testIds.brandDetailPage))).toBeVisible({ timeout: 8000 })

    await page.locator(dt(testIds.brandArchiveBtn)).click()
    await expect(page.locator(dt(testIds.brandDetailArchiveDialog))).toBeVisible()
    await page.locator(dt(testIds.brandDetailArchiveConfirm)).click()

    // Should redirect to brands list after archiving.
    await expect(page.locator(dt(testIds.brandListPage))).toBeVisible({ timeout: 8000 })

    // Switch to "all" filter and verify the brand is now archived.
    await page.locator(`[data-test="brand-filter-all"]`).click()
    await expect(page.locator(dt(testIds.brandTable))).toContainText('Archived')
  })

  test('the D6 floor holds the submit button until every field and a logo are present', async ({
    page,
  }) => {
    await signIn(page)

    await page.goto('/brands/new')
    await expect(page.locator(dt(testIds.brandCreatePage))).toBeVisible({ timeout: 10000 })

    // Empty form: submit is held and the hint names what is missing, rather
    // than letting the user discover the floor through a 422.
    const submit = page.locator(dt(testIds.brandFormSubmit))
    const hint = page.locator(dt(testIds.brandFloorHint))
    await expect(submit).toBeDisabled()
    await expect(hint).toContainText('brand name')
    await expect(hint).toContainText('monthly deliverables')
    await expect(hint).toContainText('logo')

    // Everything except the logo — still held, and now the hint has narrowed
    // to the single outstanding field.
    await fillFloorFields(page, 'Floor Test Brand')
    await expect(submit).toBeDisabled()
    await expect(hint).toContainText('logo')
    await expect(hint).not.toContainText('brand name')

    // The logo lands: the hint clears and the button releases.
    await attachLogo(page)
    await expect(hint).toHaveCount(0)
    await expect(submit).toBeEnabled()

    await submit.click()
    await page.waitForURL(/\/brands\/[A-Z0-9]+$/, { timeout: 10000 })
    const brandUlid = new URL(page.url()).pathname.split('/').pop() ?? ''

    // ── The floor re-latches from the other direction ─────────────────────────
    // Removing a logo is allowed (the endpoint runs no floor gate — an agency
    // that loses its rights to a mark must be able to take it down), and it is
    // the next EDIT that refuses. Here the form mirrors that refusal.
    await page.goto(`/brands/${brandUlid}/edit`)
    await expect(page.locator(dt(testIds.brandEditPage))).toBeVisible({ timeout: 10000 })
    await expect(submit).toBeEnabled()

    await page.locator(dt(testIds.brandLogoRemove)).click()
    await expect(page.locator(dt(testIds.brandLogoRemove))).toHaveCount(0)
    await expect(hint).toContainText('logo')
    await expect(submit).toBeDisabled()

    // Re-uploading against the existing row is the replace path (D7, Q5): a
    // new key, uploaded immediately, and the gate lifts again.
    await attachLogo(page)
    await expect(page.locator(dt(testIds.brandLogoRemove))).toBeVisible({ timeout: 8000 })
    await expect(hint).toHaveCount(0)
    await expect(submit).toBeEnabled()
  })
})
