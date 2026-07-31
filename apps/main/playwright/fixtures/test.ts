/**
 * The suite's `test` object — import `{ expect, test }` from HERE, never
 * from `@playwright/test` directly.
 *
 * Everything this module adds is `auto: true`, so a spec gets it by
 * importing `test` and nothing else. `tests/unit/architecture/
 * e2e-third-party-blocked.spec.ts` pins the import path for every spec in
 * both suites, so a new spec cannot quietly opt out by reaching for the
 * package.
 *
 * WHY THIS EXISTS
 *
 * `SignInPage` mounts the Meta Pixel (AH-064), and all 18 specs in this
 * suite start at `/sign-in`. Left alone, every E2E run would fetch
 * `fbevents.js` from Meta and register the PRODUCTION pixel once per
 * spec — CI traffic landing in real analytics, and a third-party network
 * dependency on the critical path of a suite that has nothing to do with
 * analytics. The pixel code is deliberately untouched: the block belongs
 * to the test harness, not to the app, so what ships to production is
 * exactly what a reviewer reads in `metaPixel.ts`.
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
