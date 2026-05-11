import { test, expect } from '@playwright/test'

/**
 * Admin SPA Playwright smoke test.
 *
 * Pre-7.4: hit `/` and asserted the static admin title content
 * rendered by the old `App.vue`. Post-7.4: `App.vue` is a
 * `<router-view />` shell and `/` resolves to `app.dashboard` behind
 * `requireAuth + requireMfaEnrolled`. `requireAuth` calls
 * `store.bootstrap()` → `/admin/me`, which hangs on a proxy timeout
 * in this job (no Laravel backend until chunk 7.6 — see
 * `docs/tech-debt.md`).
 *
 * Until 7.6 lands a backend in the admin E2E job, the smoke spec
 * targets `/sign-in`. The sign-in route mounts `requireGuest`, which
 * does NOT call `bootstrap()`, so the route is reachable
 * backend-less. The same `PlaceholderPage.vue` mounts there and
 * renders the i18n `app.title` ("Catalyst Engine — Admin").
 *
 * What this spec verifies is unchanged in intent — admin Playwright
 * config wired correctly + Vite dev server boots + SPA mounts + i18n
 * resolves + a route renders. The CI job docblock at
 * `.github/workflows/ci.yml` § "E2E — admin SPA (placeholder until
 * chunk 7)" documents the same scope.
 */
test('admin SPA sign-in page renders', async ({ page }) => {
  await page.goto('/sign-in')
  await expect(page).toHaveTitle(/Admin/)
  await expect(page.getByRole('heading', { level: 1, name: /Admin/ })).toBeVisible()
})
