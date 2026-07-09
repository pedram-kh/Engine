import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  clearQueueMode,
  neutralizeThrottle,
  restoreThrottle,
  seedAvatar,
  seedPortfolioImage,
  setQueueMode,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * Sprint 3 Chunk 3 sub-step 11 — creator onboarding wizard happy path.
 *
 * Flow under test:
 *   1. Sign up a fresh creator (production endpoint, NOT a helper).
 *   2. Verify the email via the production verify-email endpoint
 *      (helper mints the token, posts it back). This unblocks the
 *      `verified` middleware on `/api/v1/creators/me/*`.
 *   3. Sign in via the SPA `/sign-in` UI (cookie + Sanctum CSRF
 *      handshake shared with the page — see `creator-dashboard.spec`).
 *   4. Pre-seed one portfolio image via the production POST
 *      endpoint so Step 4's "min 1 piece" gate is satisfied without
 *      driving the file-upload UI in the spec (the upload UI has
 *      dedicated Vitest coverage in `usePortfolioUpload.spec.ts`
 *      and `PortfolioUploadGrid.spec.ts`).
 *   5. Drive the SPA through the AH-003 slimmed wizard:
 *        /onboarding (Welcome Back, first-mount branch)
 *          → /onboarding/profile      (profile basics)
 *          → /onboarding/connections  (merged Social + Portfolio —
 *             connect one social account; the seeded portfolio image
 *             satisfies the portfolio sub-section, so the single
 *             "Continue" enables once both are present)
 *          → /onboarding/contract     (master agreement, flag OFF —
 *             click-through; kyc/tax/payout are build-time hidden)
 *          → /onboarding/review       (review + submit)
 *      and finally
 *          → /creator/dashboard       (pending-review banner).
 *
 * AH-003 note: Identity verification (kyc), Tax information (tax), and
 * Payout method (payout) are build-time HIDDEN (WizardStep::
 * WIZARD_HIDDEN_STEPS), so they are absent from the flow, the rail, the
 * numbering, the completeness denominator, and the submit gate. Social
 * and portfolio are merged into one "connections" step.
 *
 * Conventions (chunk-7.1):
 *   - `auth-ip` neutralised + restored (cumulative auth hits ~6
 *     per attempt; CI retries can saturate the 10/min bucket).
 *   - `setQueueMode('sync')` so any saga jobs dispatched
 *     downstream fire inline before the next bootstrap call
 *     observes their state. Pair with `clearQueueMode()` in
 *     `afterEach`.
 *   - No English-string matches — every assertion anchors on
 *     `data-test` / `data-testid` attributes or URLs.
 *
 * Feature flags: the contract flag defaults to OFF in the running app
 * (see `docs/feature-flags.md`), so the contract step renders its
 * click-through fallback. The vendor-ON paths have dedicated Vitest
 * component-test coverage; this E2E spec exercises the production
 * end-to-end shape of the slimmed wizard.
 */

const PASSWORD = 'Cata1yst-Wizard-E2E!'

function uniqueEmail(): string {
  return `wizard-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('Sprint 3 Chunk 3 — creator wizard happy path', () => {
  // Twelve wizard hops + bootstrap-on-every guarded navigation + lazy
  // route chunks reliably exceeds Playwright's default 30s per-test
  // budget on a cold GH runner — run 25895881681 tripped TEST TIMEOUT at
  // the payout→contract hop even though navigation was still in flight.
  test.describe.configure({ timeout: 180_000 })

  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
    await setQueueMode(request, 'sync')
  })

  test.afterEach(async ({ request }) => {
    await clearQueueMode(request)
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('full wizard traversal from sign-up to /creator/dashboard pending banner', async ({
    page,
  }) => {
    const request = page.context().request
    const email = uniqueEmail()

    // -----------------------------------------------------------------
    // Setup: sign up, verify, sign in, seed one portfolio item.
    // -----------------------------------------------------------------
    await signUpUser(request, email, PASSWORD, 'Wizard E2E')
    await verifyEmailViaApi(request, email)

    // Sign in via the SPA UI (not a `request.post`) so the browser
    // engages Sanctum's stateful pipeline — the SPA's apiClient
    // handles the `/sanctum/csrf-cookie` handshake + `X-XSRF-TOKEN`
    // header forwarding automatically. See the matching block in
    // `creator-dashboard.spec.ts` for the full reasoning.
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    // Seed via page-bound helpers so they inherit the post-sign-in
    // cookies AND can read the `XSRF-TOKEN` value to forward as
    // `X-XSRF-TOKEN` — required for the `auth:web` upload routes to
    // clear Sanctum's CSRF check.
    //
    // We seed BOTH the portfolio image AND the avatar because the
    // backend's `CompletenessScoreCalculator::isProfileComplete`
    // (apps/api/.../CompletenessScoreCalculator.php) requires
    // `avatar_path !== null` for the profile step's `is_complete`
    // to flip to true. Without the avatar seed the SPA happily
    // advances client-side, but on Step 9 the review-submit button
    // stays disabled because `incompleteSteps` keeps `profile` in
    // the list. Driving the AvatarUploadDrop UI from the spec is
    // unnecessary noise — that surface has its own Vitest coverage
    // (`useAvatarUpload.spec.ts` + `AvatarUploadDrop.spec.ts`).
    await seedPortfolioImage(page)
    await seedAvatar(page)

    // -----------------------------------------------------------------
    // /onboarding — the Welcome Back landing now renders on EVERY login
    // for any non-submitted creator (a fresh sign-in is a fresh page
    // load, so the tab-scoped auto-advance flag is unset). A brand-new
    // creator (score 0) gets the "Let's get started" copy; the CTA
    // routes to `next_step` (still `profile` here). We click it to enter
    // Step 1. The copy/auto-advance variants are covered by
    // WelcomeBackPage.spec.ts.
    // -----------------------------------------------------------------
    await page.goto('/onboarding')
    await expect(page.locator(dt(testIds.welcomeBackPage))).toBeVisible({ timeout: 10_000 })
    await page.locator(dt(testIds.welcomeBackContinueBtn)).click()

    // -----------------------------------------------------------------
    // Step 2 — Profile basics. Fill the required fields and submit.
    // The page hydrates from the bootstrap state on mount, but a
    // fresh creator has empty defaults, so we have to type every
    // gating field ourselves.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL(/\/onboarding\/profile/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="step-profile-basics"]')).toBeVisible()

    await page
      .locator('[data-testid="profile-display-name"]')
      .locator('input')
      .fill('Wizard E2E Creator')

    // Vuetify v-select needs a click + menu-item pick (driving the
    // hidden native <select> via .fill() does not propagate to the
    // store's `country_code` ref).
    await page.locator('[data-testid="profile-country"]').click()
    await page.getByRole('option', { name: 'Ireland' }).click()

    // Region joined the six-field floor (AH-026 D1): the Step-2 forward gate
    // now aligns to the full floor (D2), so without a region the "Save and
    // continue" button stays disabled and this traversal would stall here.
    // It's a plain text field.
    await page.locator('[data-testid="profile-region"]').locator('input').fill('Leinster')

    await page.locator('[data-testid="profile-primary-language"]').click()
    await page.getByRole('option', { name: 'English' }).click()

    // Categories render as a visible chip grid — click the first chip.
    await page.locator('[data-testid="profile-category-chip-fashion"]').click()

    await page.locator('[data-testid="profile-submit"]').click()

    // -----------------------------------------------------------------
    // Merged Connections step (AH-003) — Social + Portfolio in one
    // page. Connect Instagram with a fake handle; the pre-seeded
    // portfolio image already satisfies the portfolio sub-section. The
    // single "Continue" enables once BOTH sub-sections are satisfied.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL(/\/onboarding\/connections/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="step-connections"]')).toBeVisible()
    await expect(page.locator('[data-testid="step-social-accounts"]')).toBeVisible()
    await expect(page.locator('[data-testid="step-portfolio"]')).toBeVisible()

    await page
      .locator('[data-testid="social-handle-instagram"]')
      .locator('input')
      .fill('wizard_e2e')
    await page.locator('[data-testid="social-connect-instagram"]').click()

    // Wait for the connected-accounts list to reflect the new row.
    await expect(page.locator('[data-testid="social-account-row-instagram"]')).toBeVisible({
      timeout: 10_000,
    })

    // Both sub-sections satisfied (1 social + the seeded portfolio item)
    // → the single Continue button enables. kyc/tax/payout are hidden,
    // so the next step is the master agreement (contract).
    await expect(page.locator('[data-testid="connections-advance"]')).toBeEnabled({
      timeout: 10_000,
    })
    const advanceToContract = async (): Promise<void> => {
      await Promise.all([
        page.waitForURL(/\/onboarding\/contract/, { timeout: 30_000 }),
        page.locator('[data-testid="connections-advance"]').click(),
      ])
      await expect(page.locator('[data-testid="step-contract"]')).toBeVisible({
        timeout: 10_000,
      })
    }
    try {
      await advanceToContract()
    } catch {
      // Fresh-chunk / router-race retry (see docs/tech-debt.md
      // "Residual Playwright-retry flakiness"): the second attempt has
      // Vite chunks warm and empirically succeeds.
      await advanceToContract()
    }

    // -----------------------------------------------------------------
    // Contract step. Flag OFF; click-through fallback. Wait for
    // the server-rendered terms to load, tick the checkbox, submit.
    // The ClickThroughAccept component emits 'accepted' on success,
    // which the page handler translates into a router.push to review.
    //
    // `advanceToContract()` above already asserted step-contract is
    // visible (so the bounce-variant flake is caught inside the
    // retry block) — continue with the contract-specific assertions.
    // -----------------------------------------------------------------
    await expect(page.locator('[data-testid="contract-flag-off"]')).toBeVisible()
    const termsRegion = page.locator('[data-testid="click-through-terms"]')
    await expect(termsRegion).toBeVisible({
      timeout: 10_000,
    })
    await expect(page.locator('[data-testid="click-through-version"]')).toBeVisible()

    // AH-028: the checkbox is disabled until the terms region is scrolled to
    // the end (a legal-attestation gate, not a rendering artifact) — the real
    // master-agreement markdown is long enough to overflow the region, so
    // this is the genuine gated path, not the short-content auto-satisfy
    // branch (that branch has dedicated Vitest coverage). Scroll the native
    // element directly: `scrollTop` assignment fires a real 'scroll' event
    // in Chromium, which the component's handler listens for.
    const checkbox = page.locator('[data-testid="click-through-checkbox"] input[type="checkbox"]')
    await expect(checkbox).toBeDisabled()
    await termsRegion.evaluate((el) => {
      el.scrollTop = el.scrollHeight
    })
    await expect(checkbox).toBeEnabled({ timeout: 5_000 })

    // Drill into the nested `<input type="checkbox">` — Vuetify's
    // v-checkbox renders a Vuetify-styled wrapper around the native
    // input, and clicks on the OUTER wrapper element don't reliably
    // dispatch a 'change' on the input element bound to v-model
    // (the click landing target is the styled selection-control
    // wrapper, which propagates a click but not the toggle when
    // hit on dead space). `check()` on the input itself drives the
    // 'change' event directly so `accepted` flips and the submit
    // button enables.
    await checkbox.check()
    await expect(page.locator('[data-testid="click-through-submit"]')).toBeEnabled({
      timeout: 5_000,
    })
    await page.locator('[data-testid="click-through-submit"]').click()

    // -----------------------------------------------------------------
    // Step 9 — Review. Every step row should show isComplete=true,
    // and the submit button should be enabled. Click it.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL(/\/onboarding\/review/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="step-review"]')).toBeVisible()
    await expect(page.locator('[data-testid="review-submit"]')).toBeEnabled({ timeout: 30_000 })
    await page.locator('[data-testid="review-submit"]').click()

    // -----------------------------------------------------------------
    // Land on /creator/dashboard with the pending-review banner.
    // The CreatorDashboardPage hydrates the bootstrap state on mount;
    // application_status flipped to `pending` on submit so the
    // info-typed banner is what we expect to see.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL(/\/creator\/dashboard/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="creator-dashboard"]')).toBeVisible()
    await expect(page.locator('[data-testid="dashboard-banner-pending"]')).toBeVisible()
  })
})
