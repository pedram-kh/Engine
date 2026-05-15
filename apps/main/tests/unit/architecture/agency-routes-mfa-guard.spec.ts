/**
 * Sprint 3 Chunk 4 sub-step 5 — source-inspection regression test that
 * pins the guard chain on admin-gated agency routes.
 *
 * Defense-in-depth (#40 / Sprint 2 § 5.17): a runtime test verifies the
 * guard's branching logic in isolation (see `guards.spec.ts` →
 * `requireMfaEnrolled`), and a runtime test verifies the dispatcher
 * chains guards correctly (see `index.spec.ts` → `runGuards`). What's
 * left is the registration step: did anyone actually wire
 * `requireMfaEnrolled` into the right route's `meta.guards` chain?
 *
 * This test inspects `apps/main/src/modules/auth/routes.ts` and asserts
 * that the `agency-users.list` route declares its guards in the order:
 *
 *   requireAuth → requireMfaEnrolled → requireAgencyAdmin
 *
 * The order matters: auth check must come first (we need a user), then
 * MFA enforcement (so non-enrolled users are bounced to
 * `/auth/2fa/enable` before the role check leaks any information about
 * the page), then the role check. This mirrors the admin SPA's chain
 * pinned in `chunk 7.1`.
 *
 * Tests that come along for the ride (cheap structural invariants):
 *   - The chain is a tuple of strings, not the actual guard function
 *     references (the dispatcher resolves symbolic names — see
 *     `core/router/index.ts → runGuards`).
 *   - No agency route accidentally adds `requireMfaEnrolled` without
 *     also adding `requireAuth` (that combination would crash because
 *     `requireMfaEnrolled` assumes a user has been resolved).
 *
 * The test reads `routes.ts` as a source file and uses TypeScript-aware
 * regex matching. It does NOT import the routes module — Sprint 2's
 * lessons learned (Sprint 2 § 5.15) warn against pulling in real
 * route imports here because it drags in Vuetify + Pinia + the full
 * monorepo graph for an assertion that should be cheap.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const ROUTES_PATH = path.resolve(__dirname, '../../../src/modules/auth/routes.ts')

interface RouteShape {
  name: string
  guards: string[]
}

/**
 * Hand-rolled parser. We look for blocks of the form:
 *
 *     name: 'something',
 *     ...
 *     guards: ['x', 'y', 'z']
 *
 * The regex is intentionally tolerant of formatting (single-line OR
 * multi-line guards arrays). The fields can appear in either order
 * inside the meta object; we anchor on `name:` and scan forward to
 * find the matching `guards:` array within the same route record
 * (bounded by the next `{` or `},`).
 */
async function parseRoutes(): Promise<RouteShape[]> {
  const source = await fs.readFile(ROUTES_PATH, 'utf8')
  const records: RouteShape[] = []
  const routeBlockPattern = /name:\s*'([a-zA-Z0-9._-]+)'[^]*?(?=(?:name:|\n\s*\]))/g

  for (const match of source.matchAll(routeBlockPattern)) {
    const name = match[1] ?? ''
    const block = match[0]
    const guardsMatch = block.match(/guards:\s*\[([^\]]*)\]/)
    const guardsRaw = guardsMatch?.[1] ?? ''
    const guards = Array.from(guardsRaw.matchAll(/'([a-zA-Z]+)'/g))
      .map((m) => m[1])
      .filter((g): g is string => typeof g === 'string')
    records.push({ name, guards })
  }
  return records
}

describe('agency routes — MFA guard registration (Sprint 3 Chunk 4 sub-step 5)', () => {
  it('declares the agency-users.list route', async () => {
    const routes = await parseRoutes()
    const target = routes.find((r) => r.name === 'agency-users.list')
    expect(target, 'agency-users.list route record not found in routes.ts').toBeDefined()
  })

  it('chains requireAuth → requireMfaEnrolled → requireAgencyAdmin in that exact order', async () => {
    const routes = await parseRoutes()
    const target = routes.find((r) => r.name === 'agency-users.list')
    expect(target?.guards).toEqual(['requireAuth', 'requireMfaEnrolled', 'requireAgencyAdmin'])
  })
})

describe('no route declares requireMfaEnrolled without requireAuth (cross-route invariant)', () => {
  it('every route that opts into requireMfaEnrolled also opts into requireAuth first', async () => {
    const routes = await parseRoutes()
    const mfaRoutes = routes.filter((r) => r.guards.includes('requireMfaEnrolled'))
    expect(mfaRoutes.length).toBeGreaterThan(0)
    for (const r of mfaRoutes) {
      const authIdx = r.guards.indexOf('requireAuth')
      const mfaIdx = r.guards.indexOf('requireMfaEnrolled')
      expect(authIdx, `route ${r.name} is MFA-gated but does not require auth`).toBeGreaterThan(-1)
      expect(authIdx, `route ${r.name} declares MFA gate before auth gate`).toBeLessThan(mfaIdx)
    }
  })
})

/**
 * Sprint 3 Chunk 4 PMC-1 — negative-case pin on the MFA gate's reach.
 *
 * The cross-route invariant above ensures the positive cases (every
 * MFA-gated route also requires auth, in the right order). What it
 * does NOT enforce is that the SET of MFA-gated routes stays the
 * intended three: silently broadening the gate to `/brands` or
 * `/dashboard` would pass every earlier test in this file.
 *
 * The chunk-4 pre-merge spot-check pass surfaced this gap (S4c
 * break-revert added `requireMfaEnrolled` to `/brands` and CI stayed
 * green). This describe block closes it by pinning the FULL set of
 * MFA-gated routes verbatim. Adding a fourth gate, or removing one
 * of the named three, fails CI before merge.
 */
describe('selective gating — only the named routes carry requireMfaEnrolled', () => {
  it('the full set of MFA-gated routes is exactly the admin-sensitive routes + the self-service 2FA-disable page', async () => {
    const routes = await parseRoutes()
    const mfaGated = routes
      .filter((r) => r.guards.includes('requireMfaEnrolled'))
      .map((r) => r.name)
      .sort()
    // Three routes legitimately carry the gate:
    //   - `agency-users.list`         — admin-sensitive surface (Chunk 4 sub-step 5).
    //   - `creator-invitations.bulk`  — admin-sensitive surface (Chunk 4 sub-step 11).
    //   - `auth.2fa.disable`          — self-service: disable requires you be enrolled.
    // Adding a fourth (e.g., `/brands`, `/dashboard`, `/settings`)
    // would be a silent broadening of the gate — block it here.
    expect(mfaGated).toEqual(['agency-users.list', 'auth.2fa.disable', 'creator-invitations.bulk'])
  })
})
