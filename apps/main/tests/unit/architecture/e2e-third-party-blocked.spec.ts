/**
 * Source-inspection regression test — no E2E run may talk to Meta.
 *
 * `SignInPage` mounts the Meta Pixel (AH-064) and every spec in the main
 * suite starts at `/sign-in`. Unblocked, each E2E run fetched
 * `fbevents.js` and registered the PRODUCTION pixel once per spec: real
 * analytics polluted by CI, and a third-party network dependency on the
 * critical path of a suite that has nothing to do with analytics. The
 * block lives in the harness (`playwright/fixtures/test.ts`), never in
 * the app, so production ships exactly what a reviewer reads in
 * `metaPixel.ts`.
 *
 * The block only holds while specs take their `test` object FROM that
 * fixture. A spec importing `test` from `@playwright/test` gets an
 * unblocked context and starts calling Meta again with the whole suite
 * green — which is precisely the failure mode this file exists to catch.
 *
 * BOTH suites are pinned from here, deliberately. The 2026-07-13
 * DB-isolation incident was caused by a protection applied to `e2e-main`
 * and not to `e2e-admin`, reproducing the original bug; `apps/admin` has
 * no pixel today, and the fixture is there anyway so a tracker added to
 * an admin surface later is blocked on arrival. Pinning both from one
 * file is the same shape `e2e-db-isolation.spec.ts` settled on.
 */

import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO_ROOT = path.resolve(__dirname, '../../../../..')

/** The two endpoints: the SDK loader and the tracking beacon. */
const BLOCKED_PATTERNS = [
  String.raw`/^https?:\/\/connect\.facebook\.net\//`,
  String.raw`/^https?:\/\/([^/]+\.)?facebook\.com\/tr/`,
] as const

const SUITES = [
  { name: 'e2e-main', dir: 'apps/main' },
  { name: 'e2e-admin', dir: 'apps/admin' },
] as const

const read = (rel: string): string => readFileSync(path.resolve(REPO_ROOT, rel), 'utf8')

describe('E2E third-party tracking is blocked (no spec may reach Meta)', () => {
  for (const suite of SUITES) {
    describe(suite.name, () => {
      const fixture = read(`${suite.dir}/playwright/fixtures/test.ts`)

      it('declares both Meta endpoints', () => {
        for (const pattern of BLOCKED_PATTERNS) {
          expect(fixture).toContain(pattern)
        }
      })

      it('aborts them on the context, so every page in the run is covered', () => {
        expect(fixture).toContain('context.route(pattern, (route) => route.abort())')
      })

      it('applies the fixture automatically, so no spec has to opt in', () => {
        expect(fixture).toContain('{ auto: true }')
      })

      it('fails the spec if a request ever reaches Meta anyway', () => {
        // `requestfinished` fires only for a request that completed against
        // the network; an aborted route raises `requestfailed`. So a
        // non-empty collection is an escape, and asserting it after the
        // body turns every spec into its own proof.
        expect(fixture).toContain("context.on('requestfinished'")
        expect(fixture).toContain('escaped')
      })

      it('every spec takes `test` from the fixture, never from @playwright/test', () => {
        const specsDir = path.resolve(REPO_ROOT, `${suite.dir}/playwright/specs`)
        const specs = readdirSync(specsDir).filter((f) => f.endsWith('.spec.ts'))

        expect(specs.length).toBeGreaterThan(0)

        const offenders = specs.filter((file) => {
          const source = readFileSync(path.resolve(specsDir, file), 'utf8')
          // Type-only imports from the package are fine — they carry no
          // runtime behaviour and cannot hand back an unblocked context.
          return /^import\s*\{[^}]*\btest\b[^}]*\}\s*from\s*'@playwright\/test'/m.test(source)
        })

        expect(
          offenders,
          `these specs would run with an UNBLOCKED context and call Meta: ${offenders.join(', ')}`,
        ).toEqual([])
      })
    })
  }
})
