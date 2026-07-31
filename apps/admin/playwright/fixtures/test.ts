/**
 * The suite's `test` object — import `{ expect, test }` from HERE, never
 * from `@playwright/test` directly.
 *
 * Mirror of `apps/main/playwright/fixtures/test.ts`, kept per-suite the
 * way `test-helpers.ts` and `helpers/selectors.ts` are: each Playwright
 * tree is self-contained, and there is no shared E2E package to hang this
 * off. `apps/main/tests/unit/architecture/e2e-third-party-blocked.spec.ts`
 * pins BOTH suites, so the two cannot drift.
 *
 * WHY THIS EXISTS HERE TOO
 *
 * The Meta Pixel (AH-064) lives in `apps/main` only — the admin sign-in
 * page does not mount it, so today this suite has nothing to block. It
 * carries the fixture anyway, because the 2026-07-13 DB-isolation incident
 * came from exactly this shape: a protection applied to `e2e-main` and not
 * to `e2e-admin`, which then reproduced the original bug. A tracker added
 * to any admin surface later is blocked on arrival rather than on the day
 * someone remembers.
 */

import { test as base, expect } from '@playwright/test'

/**
 * Meta's two endpoints: the SDK loader and the tracking beacon.
 *
 * Regexes rather than globs because the match runs against the whole URL
 * and the beacon carries a query string — `facebook.com/tr?id=…` and
 * `facebook.com/tr/?id=…` are both live forms, and a trailing-slash glob
 * would miss one of them.
 */
export const META_TRACKING_URLS: ReadonlyArray<RegExp> = [
  /^https?:\/\/connect\.facebook\.net\//,
  /^https?:\/\/([^/]+\.)?facebook\.com\/tr/,
]

function isMetaTracking(url: string): boolean {
  return META_TRACKING_URLS.some((pattern) => pattern.test(url))
}

interface BlockingFixtures {
  /** Auto-applied; no spec names it. */
  blockThirdPartyTracking: void
}

export const test = base.extend<BlockingFixtures>({
  blockThirdPartyTracking: [
    async ({ context }, use) => {
      for (const pattern of META_TRACKING_URLS) {
        await context.route(pattern, (route) => route.abort())
      }

      // The proof, not just the intent. `requestfinished` fires only when a
      // request actually completed against the network, and an aborted
      // route raises `requestfailed` instead — so anything collected here
      // is a request that ESCAPED the block. Asserted after the test body
      // rather than thrown from the listener, so the failure lands on the
      // spec with its name attached.
      const escaped: string[] = []
      context.on('requestfinished', (request) => {
        if (isMetaTracking(request.url())) {
          escaped.push(request.url())
        }
      })

      await use()

      expect(
        escaped,
        'a request reached Meta during this spec — the route block in playwright/fixtures/test.ts is not covering it',
      ).toEqual([])
    },
    { auto: true },
  ],
})

export { expect }
