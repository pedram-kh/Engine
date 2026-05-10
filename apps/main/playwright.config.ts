import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for the main SPA E2E suite.
 *
 * Specs live under `playwright/specs/` (chunk 6.8 contract). Fixtures
 * and selector constants live under `playwright/fixtures/` and
 * `playwright/helpers/`. The legacy `tests/e2e/smoke.spec.ts` is
 * moved into the same tree so the runner picks up everything from
 * one place.
 *
 * Two `webServer` entries are started: the Laravel API (so the SPA
 * has a real backend to call) and the Vite dev server (the SPA the
 * specs drive). The API is started with `CACHE_STORE=array` because
 * the chunk 6.1 test-helpers clock pins `Carbon::setTestNow()`,
 * which the application cache must honor for the 15-minute lockout
 * cache key (chunk 6.8 spec #20). Redis EXPIRE uses real wall-clock
 * time and would not honor a `Carbon::setTestNow()` fast-forward; the
 * array driver computes TTL from `Carbon::now()` on read, which does.
 *
 * The `TEST_HELPERS_TOKEN` env variable MUST be set when running the
 * suite. The global setup validates it and fails loudly if missing.
 * Local dev defaults to the value from `apps/api/.env.example`
 * (`local-dev-test-helpers-token-replace-me`); CI generates a fresh
 * value per run via `openssl rand -hex 32` and exports it into both
 * the API process and the Playwright runner — see
 * `.github/workflows/ci.yml` and `apps/api/app/TestHelpers/README.md`.
 */

const TEST_HELPERS_TOKEN =
  process.env.TEST_HELPERS_TOKEN ?? 'local-dev-test-helpers-token-replace-me'

export default defineConfig({
  testDir: './playwright/specs',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // Workers pinned to 1 so specs that mutate the test clock + the
  // global cache do not race each other. The `_test` surface is
  // process-global; parallel workers would step on each other's
  // simulated clock and lockout cache keys.
  workers: 1,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: 'http://127.0.0.1:5173',
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // Forward the token into Playwright's `request` fixture so test
    // helpers reach the gated `/_test/*` routes. Spec-side fixtures
    // wrap the calls so individual tests never spell the header.
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
  // Global setup validates env + runs `migrate:fresh` so every
  // suite execution starts from an empty database state.
  globalSetup: './playwright/global-setup.ts',
  webServer: [
    {
      // Laravel API. `php artisan serve` is the dev-grade server; the
      // chunk 6.1 test-helpers gate is honored because APP_ENV is
      // `local` in development and `testing` in CI's hermetic env.
      // CACHE_STORE=array is the chunk-6.8 hermeticity contract for
      // spec #20 (see config docblock at the top of this file).
      command: 'php artisan serve --host=127.0.0.1 --port=8000',
      cwd: '../api',
      url: 'http://127.0.0.1:8000/api/v1/_test/clock/reset',
      // The reset endpoint is the cheapest gate-checked route — a
      // 200 response means the API is up AND the test-helpers gate
      // is open, both of which the spec suite needs.
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      env: {
        APP_ENV: 'local',
        CACHE_STORE: 'array',
        TEST_HELPERS_TOKEN,
      },
      // The reset endpoint is a POST; the standard `url` health probe
      // issues a GET that 405s here (which Playwright treats as
      // "server is up"). The 405 is exactly the signal we want — it
      // proves Laravel is routing requests AND the gate is open
      // (a closed gate would return a bare 404).
      ignoreHTTPSErrors: true,
    },
    {
      command: 'pnpm dev',
      url: 'http://127.0.0.1:5173',
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
    },
  ],
})
