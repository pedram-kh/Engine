/**
 * Source-inspection regression test — E2E database isolation.
 *
 * Both Playwright suites (`apps/main` + `apps/admin`) run
 * `php artisan migrate:fresh --force` in their `global-setup.ts`, which
 * DROPS + recreates every table. If that runs against a developer's real
 * dev database it destroys local data with no confirmation — this has
 * happened twice:
 *
 *   - 2026-07-08: e2e-main inherited `DB_DATABASE` from `apps/api/.env`
 *     (the dev `catalyst` DB) and wiped a developer's accounts.
 *   - 2026-07-13: the same class of bug — e2e-main was fixed but the fix
 *     was never applied to e2e-admin — wiped a dev DB again.
 *
 * The fix (both times) is two-fold, and BOTH suites must carry it:
 *
 *   1. `DB_DATABASE` is hard-overridden to a dedicated `catalyst_e2e`
 *      database (never the dev DB), honoring CI's own per-job value via
 *      a `process.env.DB_DATABASE ?? 'catalyst_e2e'` fallback. The
 *      override MUST appear in BOTH the API `webServer.env` (so the
 *      served API talks to the isolated DB) AND `global-setup.ts`'s
 *      `migrate:fresh` env (so the schema reset targets the same DB).
 *   2. The API `webServer` forces `reuseExistingServer: false` — a stray
 *      dev server on the API port (which reads the unmodified `.env`)
 *      must fail loudly on a port clash rather than silently absorbing
 *      the run against the real DB.
 *
 * This spec pins all of that by source inspection so a future edit that
 * drops the override (or relaxes `reuseExistingServer` back to
 * `!process.env.CI`) fails at CI time, before it can wipe anyone's DB.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')

const DB_OVERRIDE_CONST = "const E2E_DB_DATABASE = process.env.DB_DATABASE ?? 'catalyst_e2e'"
const DB_OVERRIDE_USE = 'DB_DATABASE: E2E_DB_DATABASE'

const SUITES = [
  { name: 'e2e-main', dir: 'apps/main' },
  { name: 'e2e-admin', dir: 'apps/admin' },
] as const

const read = (rel: string): string => readFileSync(path.resolve(REPO_ROOT, rel), 'utf8')

describe('E2E database isolation (migrate:fresh must never target the dev DB)', () => {
  for (const suite of SUITES) {
    describe(suite.name, () => {
      const config = read(`${suite.dir}/playwright.config.ts`)
      const globalSetup = read(`${suite.dir}/playwright/global-setup.ts`)

      it('playwright.config.ts defines the catalyst_e2e DB_DATABASE override', () => {
        expect(config).toContain(DB_OVERRIDE_CONST)
      })

      it('playwright.config.ts applies the override to the API webServer env', () => {
        expect(config).toContain(DB_OVERRIDE_USE)
      })

      it('playwright.config.ts forces reuseExistingServer: false on the API server', () => {
        expect(config).toContain('reuseExistingServer: false')
      })

      it('global-setup.ts defines the same catalyst_e2e DB_DATABASE override', () => {
        expect(globalSetup).toContain(DB_OVERRIDE_CONST)
      })

      it('global-setup.ts passes the override into the migrate:fresh env', () => {
        expect(globalSetup).toContain(DB_OVERRIDE_USE)
        expect(globalSetup).toContain('migrate:fresh --force')
      })
    })
  }
})
