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
 *   5. Drive the SPA through:
 *        /onboarding (Welcome Back, first-mount branch)
 *          → /onboarding/profile  (Step 2)
 *          → /onboarding/social   (Step 3)
 *          → /onboarding/portfolio (Step 4 — gallery already
 *             carries the seeded item, so advance is enabled)
 *          → /onboarding/kyc      (Step 5, flag OFF — skipped)
 *          → /onboarding/tax      (Step 6 — form save)
 *          → /onboarding/payout   (Step 7, flag OFF — skipped)
 *          → /onboarding/contract (Step 8, flag OFF — click-through)
 *          → /onboarding/review   (Step 9)
 *      and finally
 *          → /creator/dashboard   (pending-review banner).
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
 * Feature flags: every vendor-gated flag (kyc, payout, contract)
 * defaults to OFF in the running app (see `docs/feature-flags.md`).
 * Steps 5, 7, and 8 therefore render their "skipped" surface and
 * the spec does NOT need to drive a vendor-bounce. The vendor-ON
 * paths have dedicated Vitest component-test coverage; this E2E
 * spec exercises the production end-to-end shape.
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
    // /onboarding — Welcome Back page renders on first mount in this
    // tab. Module-scoped `priorBootstrap` flag in WelcomeBackPage.vue
    // starts false, so we see the Welcome Back UI rather than the
    // auto-advance branch.
    // -----------------------------------------------------------------
    await page.goto('/onboarding')
    await expect(page.locator(dt(testIds.welcomeBackPage))).toBeVisible({ timeout: 10_000 })
    await expect(page.locator(dt(testIds.welcomeBackContinueBtn))).toBeVisible()
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

    await page.locator('[data-testid="profile-primary-language"]').click()
    await page.getByRole('option', { name: 'English' }).click()

    await page.locator('[data-testid="profile-categories"]').click()
    await page.getByRole('option').first().click()
    await page.keyboard.press('Escape')

    await page.locator('[data-testid="profile-submit"]').click()

    // -----------------------------------------------------------------
    // Step 3 — Social accounts. Connect Instagram with a fake handle.
    // The "save and continue" advance button is enabled once we see
    // at least one connected account in the bootstrap state.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL(/\/onboarding\/social/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="step-social-accounts"]')).toBeVisible()

    await page
      .locator('[data-testid="social-handle-instagram"]')
      .locator('input')
      .fill('wizard_e2e')
    await page.locator('[data-testid="social-connect-instagram"]').click()

    // Wait for the connected-accounts list to reflect the new row.
    await expect(page.locator('[data-testid="social-account-row-instagram"]')).toBeVisible({
      timeout: 10_000,
    })

    await expect(page.locator('[data-testid="social-advance"]')).toBeEnabled()
    await page.locator('[data-testid="social-advance"]').click()

    // -----------------------------------------------------------------
    // Step 4 — Portfolio. We pre-seeded one image via the API helper,
    // so the gallery hydrates with one item and the advance button
    // is enabled on mount.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL(/\/onboarding\/portfolio/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="step-portfolio"]')).toBeVisible()
    await expect(page.locator('[data-testid="portfolio-advance"]')).toBeEnabled({
      timeout: 10_000,
    })
    await page.locator('[data-testid="portfolio-advance"]').click()

    // -----------------------------------------------------------------
    // Step 5 — KYC. Flag OFF in the local env, so the "skipped"
    // surface renders. The advance button is unconditionally enabled
    // in the flag-OFF branch (the disabled binding only applies when
    // `kycFlag.enabled === true`).
    // -----------------------------------------------------------------
    await expect(page).toHaveURL(/\/onboarding\/kyc/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="step-kyc"]')).toBeVisible()
    await expect(page.locator('[data-testid="kyc-flag-off"]')).toBeVisible()
    await page.locator('[data-testid="kyc-advance"]').click()

    // -----------------------------------------------------------------
    // Step 6 — Tax. Form-only step; fill all seven required fields,
    // save (turns the status chip green), then advance.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL(/\/onboarding\/tax/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="step-tax"]')).toBeVisible()

    await page.locator('[data-testid="tax-legal-name"]').locator('input').fill('E2E Creator Ltd')
    await page.locator('[data-testid="tax-id"]').locator('input').fill('IE1234567A')
    await page.locator('[data-testid="tax-address-street"]').locator('input').fill('1 Test Lane')
    await page.locator('[data-testid="tax-address-city"]').locator('input').fill('Dublin')
    await page.locator('[data-testid="tax-address-postal"]').locator('input').fill('D01XYZ')
    await page.locator('[data-testid="tax-address-country"]').locator('input').fill('IE')

    await page.locator('[data-testid="tax-save"]').click()
    await expect(page.locator('[data-testid="tax-advance"]')).toBeEnabled({ timeout: 10_000 })
    await page.locator('[data-testid="tax-advance"]').click()

    // -----------------------------------------------------------------
    // Step 7 — Payout. Flag OFF; skipped surface; advance.
    // -----------------------------------------------------------------
    await expect(page).toHaveURL(/\/onboarding\/payout/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="step-payout"]')).toBeVisible()
    await expect(page.locator('[data-testid="payout-flag-off"]')).toBeVisible()
    await expect(page.locator('[data-testid="payout-advance"]')).toBeEnabled({
      timeout: 10_000,
    })
    // Pair the click with an explicit `waitForURL` so we don't poll
    // `toHaveURL` against a navigation that has not yet been
    // initiated (CI run 25896340741 attempt #1 symptom: the click
    // fired during a re-render flush and the navigation-to-poll race
    // exhausted the budget).
    //
    // 60s budget — not 30s — because this is the only hop in the
    // spec that crosses into a route chunk with a heavy transitive
    // import graph (Step8ContractPage pulls `ContractStatusBadge`
    // from @catalyst/ui + `ClickThroughAccept` + `useVendorBounce`).
    // Under Playwright's `webServer: vite` configuration, the chunk
    // is compiled on-demand on first navigation and the cold-compile
    // cost stacks on top of the guard's `bootstrap()` call. CI run
    // 25934883993 attempt #1 timed out at 30s on exactly this hop +
    // passed on retry once Vite's chunk was warm. Other wizard hops
    // do NOT need 60s because their target chunks are lighter (KYC
    // and payout flag-OFF surfaces are `<v-alert>`-only) — keeping
    // those at the standard 10s poll budget surfaces real bugs
    // without absorbing them in a generous timeout.
    await Promise.all([
      page.waitForURL(/\/onboarding\/contract/, { timeout: 60_000 }),
      page.locator('[data-testid="payout-advance"]').click(),
    ])

    // -----------------------------------------------------------------
    // Step 8 — Contract. Flag OFF; click-through fallback. Wait for
    // the server-rendered terms to load, tick the checkbox, submit.
    // The ClickThroughAccept component emits 'accepted' on success,
    // which the page handler translates into a router.push to review.
    //
    // The waitForURL above already pinned us to /onboarding/contract;
    // assert step-contract is rendered before driving the click-through.
    // -----------------------------------------------------------------
    await expect(page.locator('[data-testid="step-contract"]')).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('[data-testid="contract-flag-off"]')).toBeVisible()
    await expect(page.locator('[data-testid="click-through-terms"]')).toBeVisible({
      timeout: 10_000,
    })
    await expect(page.locator('[data-testid="click-through-version"]')).toBeVisible()

    // Drill into the nested `<input type="checkbox">` — Vuetify's
    // v-checkbox renders a Vuetify-styled wrapper around the native
    // input, and clicks on the OUTER wrapper element don't reliably
    // dispatch a 'change' on the input element bound to v-model
    // (the click landing target is the styled selection-control
    // wrapper, which propagates a click but not the toggle when
    // hit on dead space). `check()` on the input itself drives the
    // 'change' event directly so `accepted` flips and the submit
    // button enables.
    await page.locator('[data-testid="click-through-checkbox"] input[type="checkbox"]').check()
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
