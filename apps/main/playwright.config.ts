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
 * specs drive). The API is started with `CACHE_STORE=database` —
 * the database cache driver persists across `php artisan serve`'s
 * per-request PHP processes (so the FailedLoginTracker counter for
 * spec #20 actually accumulates across attempts) AND its TTL
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
 * `statusCode >= 200 && statusCode < 404` — i.e. 404, 405, and 5xx
 * are all treated as "not yet available" and the probe keeps polling
 * until the deadline fires. This rules out POST-only routes (which
 * 405 to GET) and routes behind closed gates (which can 404). The
 * API entry below uses Laravel 11's built-in `/up` health route
 * (enabled in `apps/api/bootstrap/app.php` via `health: '/up'`),
 * which is a GET-200 endpoint with no auth or gate dependencies.
 */

const TEST_HELPERS_TOKEN =
  process.env.TEST_HELPERS_TOKEN ?? 'local-dev-test-helpers-token-replace-me'

// Isolation from the developer's real dev database (post-incident,
// 2026-07-08 — see the matching docblock in `playwright/global-setup.ts`
// for the full incident writeup). MUST match the value global-setup.ts
// uses for its `migrate:fresh` call, or the API server and the schema
// reset target different databases.
const E2E_DB_DATABASE = process.env.DB_DATABASE ?? 'catalyst_e2e'

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
      // `local` in development and CI sets the same value on the job
      // env. CACHE_STORE=database is the chunk-6.8 hermeticity
      // contract (see config docblock above).
      command: 'php artisan serve --host=127.0.0.1 --port=8000',
      cwd: '../api',
      // `/up` is Laravel 11's built-in health route, enabled in
      // `apps/api/bootstrap/app.php` (`health: '/up'`). GET-200,
      // no auth, no gate dependency. Probe-safe per Playwright's
      // `< 404` rule (see top-of-file docblock). The test-helpers
      // gate is validated separately by `playwright/global-setup.ts`
      // (which fails loudly if `TEST_HELPERS_TOKEN` is unset) and
      // by every spec's first `/_test/*` call (which would 404 if
      // the gate were closed).
      url: 'http://127.0.0.1:8000/up',
      // ⚠ Always `false` (post-incident, 2026-07-08) — NEVER
      // `!process.env.CI`. Reusing an already-running `pnpm dev` API
      // server means this `env` block (including the DB_DATABASE
      // override below) is silently never applied — the reused
      // process is still bound to whatever `apps/api/.env` says,
      // i.e. the developer's real dev database. `global-setup.ts`'s
      // `migrate:fresh` runs unconditionally regardless of reuse, so
      // that combination wiped a developer's real accounts. Forcing
      // `false` means a `pnpm test:e2e` run with a dev server already
      // on :8000 fails loudly (port already in use) instead of
      // quietly running — and possibly resetting — the wrong database.
      reuseExistingServer: false,
      timeout: 60_000,
      env: {
        APP_ENV: 'local',
        CACHE_STORE: 'database',
        TEST_HELPERS_TOKEN,
        // Isolation from the developer's real dev database — see the
        // top-of-file docblock const and `playwright/global-setup.ts`.
        // NEVER remove this override.
        DB_DATABASE: E2E_DB_DATABASE,
      },
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
