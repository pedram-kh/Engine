import { execSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

/**
 * Global setup for the chunk-7.6 admin Playwright suite.
 *
 * Mirror of `apps/main/playwright/global-setup.ts` (chunks 6.8 + 7.1
 * post-merge addendum #2 for the ESM-safe `__dirname` substitution).
 *
 * Two responsibilities:
 *
 *   1. Validate `TEST_HELPERS_TOKEN` is present in the environment.
 *      The specs are useless without it — every `/_test/*` call would
 *      bounce off the gate as a bare 404 and the failure mode would
 *      be a confusing "selector never appeared" instead of a clear
 *      "the test-helpers surface is closed". Failing here makes the
 *      misconfiguration loud at suite start.
 *
 *   2. Run `php artisan migrate:fresh` against the API working
 *      directory so each suite execution starts from a clean database
 *      state. The Pest suite is hermetic via `RefreshDatabase`; the
 *      Playwright suite is hermetic via this single migrate:fresh
 *      plus the per-spec `afterEach` clock reset (see
 *      `playwright/fixtures/test-helpers.ts`).
 *
 * Setup runs once per `pnpm test:e2e` invocation. It does NOT touch
 * the test clock — the per-spec fixtures own the clock lifecycle.
 *
 * Concurrency note: the e2e-admin job runs on its own ephemeral
 * Postgres + Redis stack (see `.github/workflows/ci.yml § e2e-admin`),
 * so this `migrate:fresh` only ever wipes the admin-suite DB, never
 * e2e-main's. The two jobs run in parallel service containers with
 * disjoint port assignments (admin's API: 8001; main's API: 8000).
 */
export default async function globalSetup(): Promise<void> {
  if (process.env.TEST_HELPERS_TOKEN === undefined || process.env.TEST_HELPERS_TOKEN === '') {
    throw new Error(
      [
        'TEST_HELPERS_TOKEN is required to run the admin Playwright suite.',
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

  // ESM-safe equivalent of `path.resolve(__dirname, '../../api')`.
  // `apps/admin/package.json` declares `"type": "module"`, so this
  // file is loaded as ESM and the CommonJS `__dirname` global is
  // undefined here. The `fileURLToPath(new URL(rel, import.meta.url))`
  // idiom matches `apps/admin/vite.config.ts` and the vitest configs.
  // Vitest polyfills `__dirname` for transformed test modules (which
  // is why the architecture tests under `tests/unit/architecture/`
  // can keep using it) but Playwright's TS loader does not — see the
  // chunks 6.8-6.9 review's post-merge addendum #2 for the
  // CI-discovery context.
  const apiDir = fileURLToPath(new URL('../../api', import.meta.url))
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
