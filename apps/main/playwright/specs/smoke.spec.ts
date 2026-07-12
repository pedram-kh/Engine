import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'

/**
 * Smoke test — proves the full SPA stack boots and the chunk-6.8
 * App.vue layout switcher hands an auth-meta route to AuthLayout.
 *
 * The previous Sprint-0 version of this spec navigated to `/` and
 * asserted on the "Catalyst Engine" h1 hardcoded into App.vue. After
 * the chunk-6.8 layout-switcher refactor, App.vue no longer carries
 * any user-visible text; the brand mark lives in `AuthLayout.vue` and
 * only renders for routes whose `meta.layout` is `'auth'` or
 * `'error'`. We now navigate to `/sign-in` (which has `meta.layout: 'auth'`)
 * and verify both the layout shell AND the routed page mounted.
 */

test('SPA boots and renders the AuthLayout shell on /sign-in', async ({ page }) => {
  await page.goto('/sign-in')

  await expect(page).toHaveTitle(/Catalyst Engine/)
  await expect(page.locator(dt(testIds.authBrand))).toBeVisible()
  await expect(page.locator(dt(testIds.signInPage))).toBeVisible()
})
