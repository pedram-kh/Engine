import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for the admin SPA E2E suite (chunk 7.6).
 *
 * Mirror of `apps/main/playwright.config.ts` (chunk 6.8) with two
 * structurally-correct admin adaptations:
 *
 *   - Ports are offset by +1 to avoid collision when `e2e-main` and
 *     `e2e-admin` run concurrently in CI (or locally side-by-side).
 *     Admin uses Vite on `:5174` (declared in `apps/admin/vite.config.ts`)
 *     and the Laravel API on `:8001` rather than `:8000`. The
 *     `CATALYST_ADMIN_API_PORT` env var is honored if exporters need
 *     to override.
 *
 *   - `testDir` points to `./playwright/specs` (chunk 7.6) rather than
 *     `./tests/e2e` (the Group 2 hotfix placeholder). The legacy
 *     `tests/e2e/smoke.spec.ts` is replaced by real specs that drive
 *     the now-wired Laravel backend (see `docs/tech-debt.md` →
 *     "Admin SPA Playwright job runs without a Laravel backend" —
 *     this config closes that entry).
 *
 * Two `webServer` entries are started: the Laravel API (so the SPA
 * has a real backend to call) and the Vite dev server (the SPA the
 * specs drive). The API is started with `CACHE_STORE=database` —
 * the database cache driver persists across `php artisan serve`'s
 * per-request PHP processes (so cache-backed state like rate-limiter
 * counters actually accumulates across attempts) AND its TTL
 * evaluation routes through `Carbon::now()` (so the chunk-6.1
 * simulated test clock is honored). See the chunks 6.8-6.9 review's
 * post-merge addendum #3 for the discovery context.
 *
 * The `TEST_HELPERS_TOKEN` env variable MUST be set when running the
 * suite. The global setup validates it and fails loudly if missing.
 * Local dev defaults to the value from `apps/api/.env.example`
 * (`local-dev-test-helpers-token-replace-me`); CI generates a fresh
 * value per run via `openssl rand -hex 32` and exports it into both
 * the API process and the Playwright runner — see
 * `.github/workflows/ci.yml` and `apps/api/app/TestHelpers/README.md`.
 *
 * Health-probe URL contract: Playwright's `webServer.url` probe is
 * implemented in `playwright-core/lib/server/utils/network.js` as
 * `statusCode >= 200 && statusCode < 404`. The API entry below uses
 * Laravel 11's built-in `/up` health route (enabled in
 * `apps/api/bootstrap/app.php` via `health: '/up'`), a GET-200
 * endpoint with no auth or gate dependencies.
 */

const TEST_HELPERS_TOKEN =
  process.env.TEST_HELPERS_TOKEN ?? 'local-dev-test-helpers-token-replace-me'

const ADMIN_API_PORT = process.env.CATALYST_ADMIN_API_PORT ?? '8001'
const ADMIN_VITE_PORT = '5174'

export default defineConfig({
  testDir: './playwright/specs',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // Workers pinned to 1 so specs that mutate the test clock + the
  // global cache do not race each other. The `_test` surface is
  // process-global; parallel workers would step on each other's
  // simulated clock and lockout cache keys. Mirrors main's chunk-6.8
  // decision verbatim.
  workers: 1,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${ADMIN_VITE_PORT}`,
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
    extraHTTPHeaders: {
      'X-Test-Helper-Token': TEST_HELPERS_TOKEN,
    },
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  globalSetup: './playwright/global-setup.ts',
  webServer: [
    {
      command: `php artisan serve --host=127.0.0.1 --port=${ADMIN_API_PORT}`,
      cwd: '../api',
      url: `http://127.0.0.1:${ADMIN_API_PORT}/up`,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      env: {
        APP_ENV: 'local',
        CACHE_STORE: 'database',
        TEST_HELPERS_TOKEN,
      },
      ignoreHTTPSErrors: true,
    },
    {
      // The admin Vite dev server proxies `/api` + `/sanctum` to the
      // Laravel API. Override the proxy target so the same `pnpm dev`
      // command works against the admin's offset API port (8001) for
      // E2E without forking the vite config.
      command: 'pnpm dev',
      url: `http://127.0.0.1:${ADMIN_VITE_PORT}`,
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
      env: {
        // Vite reads VITE_-prefixed envs at build time. The admin
        // SPA's apiClient uses the dev-server proxy by default, so
        // overriding `VITE_API_BASE_URL` is not required — the proxy
        // target is configured via the env var below.
        CATALYST_ADMIN_API_PROXY_TARGET: `http://127.0.0.1:${ADMIN_API_PORT}`,
      },
    },
  ],
})
