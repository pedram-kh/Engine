/**
 * Sprint 6 Chunk 1 (D-7) — source-inspection regression test that pins the
 * `requireAgencyUser` guard onto every agency-SHELL route.
 *
 * Defense-in-depth (#40 / Sprint 2 § 5.17): `guards.spec.ts` verifies the
 * guard's branching logic in isolation and `index.spec.ts` verifies the
 * dispatcher chains guards correctly. What's left is the registration step:
 * did anyone actually wire `requireAgencyUser` into the `meta.guards` chain of
 * every agency-shell route? A creator who navigates to `/brands` or `/roster`
 * by URL must be bounced — but only if the guard is declared on that route.
 *
 * This test inspects `apps/main/src/modules/auth/routes.ts` and asserts that
 * EVERY route with `layout: 'agency'` (the agency shell) carries
 * `requireAgencyUser`, ordered AFTER `requireAuth` (the guard assumes a
 * resolved user). The one documented exception — `accept-invitation`, a public
 * pre-auth landing on `layout: 'auth'` — is explicitly NOT agency-shell and so
 * is out of scope (and is asserted to NOT carry the guard).
 *
 * Like the MFA-guard arch-test, this reads `routes.ts` as a source file with
 * TypeScript-aware regex matching rather than importing the routes module
 * (Sprint 2 § 5.15: importing routes drags in Vuetify + Pinia + the full graph
 * for an assertion that should be cheap).
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const ROUTES_PATH = path.resolve(__dirname, '../../../src/modules/auth/routes.ts')

interface RouteShape {
  name: string
  layout: string | null
  guards: string[]
}

/**
 * Hand-rolled parser. We anchor on `name:` and scan forward to the next
 * `name:` (or the closing `]`), capturing the `layout:` and `guards:` from the
 * route's `meta` block (both appear after `name:` in every record here).
 */
async function parseRoutes(): Promise<RouteShape[]> {
  const source = await fs.readFile(ROUTES_PATH, 'utf8')
  const records: RouteShape[] = []
  const routeBlockPattern = /name:\s*'([a-zA-Z0-9._-]+)'[^]*?(?=(?:name:|\n\s*\]))/g

  for (const match of source.matchAll(routeBlockPattern)) {
    const name = match[1] ?? ''
    const block = match[0]

    const layoutMatch = block.match(/layout:\s*'([a-z]+)'/)
    const layout = layoutMatch?.[1] ?? null

    const guardsMatch = block.match(/guards:\s*\[([^\]]*)\]/)
    const guardsRaw = guardsMatch?.[1] ?? ''
    const guards = Array.from(guardsRaw.matchAll(/'([a-zA-Z]+)'/g))
      .map((m) => m[1])
      .filter((g): g is string => typeof g === 'string')

    records.push({ name, layout, guards })
  }
  return records
}

describe('agency-shell routes — requireAgencyUser guard registration (Sprint 6 Chunk 1, D-7)', () => {
  it('pins the full set of agency-shell (layout: agency) routes', async () => {
    const routes = await parseRoutes()
    const agencyShell = routes
      .filter((r) => r.layout === 'agency')
      .map((r) => r.name)
      .sort()
    // The full agency shell. Adding a new agency-layout route without also
    // adding it here (and wiring the guard below) fails CI before merge — and
    // dropping the guard from one of these fails the next assertion.
    expect(agencyShell).toEqual([
      'agency-users.list',
      'app.dashboard',
      'brands.create',
      'brands.detail',
      'brands.edit',
      'brands.list',
      // Sprint 8 Chunk 1 — campaigns (list / create / detail with tabs).
      'campaigns.create',
      'campaigns.detail',
      'campaigns.list',
      'creator-invitations.bulk',
      // Sprint 6.6a — the discovery read path (browse the global pool + the
      // public profile). New agency-shell routes; both carry requireAgencyUser.
      'discover.detail',
      'discover.list',
      // AH-010b — the agency-shell relationship-messaging inbox + thread.
      'messages.inbox',
      'messages.thread',
      // S11.0 Ch3a — the agency-shell notification archive (full paginated feed).
      'notifications',
      // S11.0 Ch3b — the agency-shell notification-preferences page.
      'notifications.preferences',
      'pools.create',
      'pools.detail',
      'pools.edit',
      'pools.list',
      'roster.detail',
      'roster.list',
      'settings',
    ])
  })

  it('every agency-shell route carries requireAgencyUser, ordered after requireAuth', async () => {
    const routes = await parseRoutes()
    const agencyShell = routes.filter((r) => r.layout === 'agency')
    expect(agencyShell.length).toBeGreaterThan(0)

    for (const r of agencyShell) {
      const authIdx = r.guards.indexOf('requireAuth')
      const agencyUserIdx = r.guards.indexOf('requireAgencyUser')
      expect(
        agencyUserIdx,
        `agency-shell route ${r.name} is missing requireAgencyUser`,
      ).toBeGreaterThan(-1)
      expect(authIdx, `agency-shell route ${r.name} is missing requireAuth`).toBeGreaterThan(-1)
      expect(authIdx, `route ${r.name} declares requireAgencyUser before requireAuth`).toBeLessThan(
        agencyUserIdx,
      )
    }
  })

  it('the public accept-invitation landing does NOT carry requireAgencyUser (documented exception)', async () => {
    const routes = await parseRoutes()
    const acceptInvitation = routes.find((r) => r.name === 'accept-invitation')
    expect(acceptInvitation, 'accept-invitation route not found').toBeDefined()
    expect(acceptInvitation?.layout).toBe('auth')
    expect(acceptInvitation?.guards).not.toContain('requireAgencyUser')
  })
})
