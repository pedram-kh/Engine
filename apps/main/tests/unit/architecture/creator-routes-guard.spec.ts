/**
 * AH-056 (C3) — the missing sibling of `agency-routes-agency-user-guard.spec.ts`:
 * a source-inspection regression test over the CREATOR shell.
 *
 * The agency shell has had its registration step pinned since Sprint 6: every
 * `layout: 'agency'` route must declare `requireAgencyUser`. The creator shell
 * never got the same treatment, so its invariant — "every creator-shell route
 * declares `requireAuth`, and creator-shell routes are declared in the creators
 * module and nowhere else" — has been true by habit rather than by test. This
 * chunk adds two creator-shell routes, which is the right moment to stop
 * relying on habit.
 *
 * Two assertions, deliberately:
 *
 *  1. **The positive.** Parse `modules/creators/routes.ts`; every route with
 *     `layout: 'creator'` carries `requireAuth`. The guard is what stands
 *     between an anonymous visitor and a creator surface.
 *  2. **The negative.** `modules/auth/routes.ts` — the module that owns every
 *     agency-shell and public route — declares NO `layout: 'creator'` route.
 *     Without this, someone could add a creator route in the agency table, skip
 *     the guard, and assertion (1) would still pass because it never looks
 *     there.
 *
 * A non-vacuity guard sits under both: if the parser silently stops matching
 * (a refactor to a different record shape, say), an empty result set would make
 * assertion (1) trivially true. The expected route-name list is exact for the
 * same reason a snapshot is exact — a new creator route must be added here
 * consciously.
 *
 * Reads the route tables as SOURCE with regex, not as imported modules: an
 * import drags Vuetify, Pinia and the whole page graph into a test that only
 * needs to know what the file says (§5.15).
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const CREATOR_ROUTES = path.resolve(__dirname, '../../../src/modules/creators/routes.ts')
const AUTH_ROUTES = path.resolve(__dirname, '../../../src/modules/auth/routes.ts')

interface RouteShape {
  name: string
  layout: string | null
  guards: string[]
}

/**
 * Hand-rolled parser, identical in shape to the agency spec's: anchor on
 * `name:` and scan to the next `name:` (or the table's closing `]`), pulling
 * `layout:` and `guards:` out of the record's `meta` block.
 *
 * The creators table nests one child route (`creator.messages.thread`), and the
 * parser handles it for free — a child record is just another `name:` anchor,
 * and it declares its own `layout` + `guards`, which is exactly what should be
 * asserted about it.
 */
async function parseRoutes(file: string): Promise<RouteShape[]> {
  const source = await fs.readFile(file, 'utf8')
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

describe('creator-shell routes — requireAuth registration (AH-056, C3)', () => {
  it('pins the full set of creator-shell (layout: creator) routes', async () => {
    const routes = await parseRoutes(CREATOR_ROUTES)
    const creatorShell = routes
      .filter((r) => r.layout === 'creator')
      .map((r) => r.name)
      .sort()

    // Adding a creator-layout route without adding it here fails CI before
    // merge — and dropping its guard fails the next assertion.
    expect(creatorShell).toEqual([
      'creator.assignment.detail',
      'creator.assignments',
      'creator.availability',
      'creator.dashboard',
      // AH-056 — the jobs board (list + detail).
      'creator.job.detail',
      'creator.jobs',
      'creator.messages',
      'creator.messages.thread',
      'creator.notifications',
      'creator.notifications.preferences',
      'creator.profile',
    ])
  })

  it('every creator-shell route declares requireAuth', async () => {
    const routes = await parseRoutes(CREATOR_ROUTES)
    const creatorShell = routes.filter((r) => r.layout === 'creator')

    // Non-vacuity: a parser that stops matching would make the loop below pass
    // by having nothing to check.
    expect(creatorShell.length).toBeGreaterThanOrEqual(11)

    for (const r of creatorShell) {
      expect(r.guards, `creator-shell route ${r.name} is missing requireAuth`).toContain(
        'requireAuth',
      )
    }
  })

  it('declares NO creator-shell route outside the creators module', async () => {
    const authRoutes = await parseRoutes(AUTH_ROUTES)

    // Non-vacuity: the auth table is large and must have parsed.
    expect(authRoutes.length).toBeGreaterThan(10)

    const strays = authRoutes.filter((r) => r.layout === 'creator').map((r) => r.name)

    expect(
      strays,
      'creator-shell routes must be declared in modules/creators/routes.ts, where this spec can see them',
    ).toEqual([])
  })
})
