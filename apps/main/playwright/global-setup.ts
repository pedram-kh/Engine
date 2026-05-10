import { execSync } from 'node:child_process'
import path from 'node:path'

/**
 * Global setup for the chunk-6.8 Playwright suite.
 *
 * Two responsibilities:
 *
 *   1. Validate `TEST_HELPERS_TOKEN` is present in the environment.
 *      The specs are useless without it — every `/_test/*` call would
 *      bounce off the gate as a bare 404, and the failure mode would
 *      be a confusing "selector never appeared" instead of a clear
 *      "the test-helpers surface is closed". Failing here makes the
 *      misconfiguration loud at suite start.
 *
 *   2. Run `php artisan migrate:fresh` against the API working
 *      directory so each suite execution starts from a clean database
 *      state. The Pest suite is hermetic via `RefreshDatabase`; the
 *      Playwright suite is hermetic via this single migrate:fresh
 *      plus the per-test `afterEach` clock reset (see
 *      `playwright/fixtures/test-helpers.ts`).
 *
 * Setup runs once per `pnpm test:e2e` invocation. It does NOT touch
 * the test clock — the per-spec fixtures own the clock lifecycle.
 */
export default async function globalSetup(): Promise<void> {
  if (process.env.TEST_HELPERS_TOKEN === undefined || process.env.TEST_HELPERS_TOKEN === '') {
    throw new Error(
      [
        'TEST_HELPERS_TOKEN is required to run the Playwright suite.',
        '',
        'Local dev:  export TEST_HELPERS_TOKEN=local-dev-test-helpers-token-replace-me',
        '            (matches the value in apps/api/.env.example so the API gate opens)',
        '',
        'CI:         the workflow generates a fresh value per run via',
        '            `openssl rand -hex 32` and exports it into both the',
        '            API process and the Playwright runner. See',
        '            apps/api/app/TestHelpers/README.md for the operator runbook.',
      ].join('\n'),
    )
  }

  const apiDir = path.resolve(__dirname, '../../api')
  // `--force` skips the production-environment confirmation prompt;
  // the gate above already asserts we're in a test-friendly env.
  // `migrate:fresh` drops + recreates the schema, then seeds nothing
  // (no seeders are needed — every spec creates its own user via
  // the SignUpController).
  execSync('php artisan migrate:fresh --force', {
    cwd: apiDir,
    stdio: 'inherit',
    env: {
      ...process.env,
      // Same hermeticity contract the webServer block in
      // `playwright.config.ts` documents — keep both in lock-step.
      APP_ENV: 'local',
      CACHE_STORE: 'array',
    },
  })
}
