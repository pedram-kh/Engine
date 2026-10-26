import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  mintVerificationToken,
  neutralizeThrottle,
  restoreThrottle,
  setFeatureFlag,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * AH-104d (S7) — the web side of in-app sign-up, end to end.
 *
 * The creator app reuses the web's sign-up, verification and wizard pages, so
 * the web is where the shared parts can be driven for real:
 *
 *   1. /sign-up shows the consent line with the Privacy Policy link (an
 *      external link, a new tab) and nothing about Terms (no Terms page exists
 *      yet), signs up through the form, and lands on the pending page.
 *   2. The pending page on the web is the page it always was: a back link, none
 *      of the app-only controls.
 *   3. The confirm page (token minted through the `_test` helper) shows the
 *      verified state and the "return to the app" line.
 *   4. A signed-in creator in the wizard has the account menu in the topbar
 *      while `account_deletion_enabled` is ON, and it opens Security; with the
 *      flag OFF the menu is not there at all.
 *
 * The app-only pieces (the sheet look, "I've verified, sign in", the Security
 * link on the pending page, the header menu inside the native chrome) are
 * Vitest-covered and are on the device walk: a Capacitor shell cannot be driven
 * from here.
 *
 * Conventions (chunk-7.1): `auth-ip` is neutralised and restored; no English
 * text is matched, every assertion anchors on `data-test`, a URL or an attribute.
 * The flag is armed through `setFeatureFlag` (E2E database only) and always
 * switched back in `afterEach`.
 */

const PASSWORD = 'Cata1yst-Signup-E2E!'
const FLAG = 'account_deletion_enabled'
const PRIVACY_URL = 'https://www.catalyst-growth.com/legal/privacy'

function uniqueEmail(): string {
  return `signup-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('AH-104d — sign-up consent, verification and the wizard account menu', () => {
  test.describe.configure({ timeout: 90_000 })

  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
    await neutralizeThrottle(request, 'auth-signup-email')
    await neutralizeThrottle(request, 'auth-resend-verification')
  })

  test.afterEach(async ({ request }) => {
    setFeatureFlag(FLAG, false)
    await restoreThrottle(request, 'auth-resend-verification')
    await restoreThrottle(request, 'auth-signup-email')
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('the sign-up form shows the consent line, signs up, and lands on the web pending page', async ({
    page,
  }) => {
    const email = uniqueEmail()

    await page.goto('/sign-up')
    const consent = page.locator(dt(testIds.signUpConsent))
    await expect(consent).toBeVisible()
    const privacy = page.locator(dt(testIds.signUpConsentPrivacy))
    await expect(privacy).toHaveAttribute('href', PRIVACY_URL)
    await expect(privacy).toHaveAttribute('target', '_blank')
    await expect(privacy).toHaveAttribute('rel', 'noopener noreferrer')
    // Privacy only: no Terms page exists, so the line must not link to one.
    await expect(consent.locator('a')).toHaveCount(1)

    await page.locator(dt(testIds.signUpName)).locator('input').fill('Nova')
    await page.locator(dt(testIds.signUpLastName)).locator('input').fill('Creator')
    await page.locator(dt(testIds.signUpEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signUpPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signUpPasswordConfirmation)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signUpSubmit)).click()

    await expect(page).toHaveURL(/\/verify-email\/pending/, { timeout: 10_000 })
    await expect(page.locator(dt(testIds.emailVerificationPendingPage))).toBeVisible()
    // The web pending page is unchanged: a way back, none of the app-only controls.
    await expect(page.locator(dt(testIds.emailVerificationPendingBack))).toBeVisible()
    await expect(page.locator(dt(testIds.emailVerificationPendingVerified))).toHaveCount(0)
  })

  test('the confirm page shows the verified state and the return-to-the-app line', async ({
    page,
  }) => {
    const email = uniqueEmail()
    await signUpUser(page.context().request, email, PASSWORD, 'Nova')
    const { token } = await mintVerificationToken(page.context().request, email)

    await page.goto(`/auth/verify-email?token=${encodeURIComponent(token)}`)

    await expect(page.locator(dt(testIds.emailVerificationConfirmSuccess))).toBeVisible({
      timeout: 10_000,
    })
    await expect(page.locator(dt(testIds.emailVerificationConfirmAppHint))).toBeVisible()
    // The error region is an always-present live region (so a screen reader
    // announces a later failure); success means it holds no text.
    await expect(page.locator(dt(testIds.emailVerificationConfirmError))).toHaveText('')
  })

  test('wizard: the account menu opens Security while the flag is ON, and is absent when it is OFF', async ({
    page,
  }) => {
    const request = page.context().request
    const email = uniqueEmail()
    await signUpUser(request, email, PASSWORD, 'Nova')
    await verifyEmailViaApi(request, email)

    setFeatureFlag(FLAG, true)
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).toHaveURL(/\/onboarding/, { timeout: 15_000 })

    const menuButton = page.locator(dt(testIds.onboardingAccountMenuBtn))
    await expect(menuButton).toBeVisible({ timeout: 10_000 })
    await menuButton.click()
    await page.locator(dt(testIds.onboardingAccountSecurity)).click()
    await expect(page).toHaveURL(/\/creator\/security/, { timeout: 10_000 })

    // Flag OFF: a fresh load re-reads /me, and the menu is not there at all.
    setFeatureFlag(FLAG, false)
    await page.goto('/onboarding')
    await expect(page).toHaveURL(/\/onboarding/, { timeout: 10_000 })
    await expect(page.locator('[data-test="onboarding-topbar"]')).toBeVisible()
    await expect(page.locator(dt(testIds.onboardingAccountMenuBtn))).toHaveCount(0)
  })
})
