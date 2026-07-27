import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedListedJob,
  signOutViaApi,
  signUpUser,
  verifyEmailViaApi,
} from '../fixtures/test-helpers'

/**
 * AH-057 — the creator shell's bottom navigation, at phone width, in a real
 * browser. Runs under the `mobile` project ONLY (iPhone 13); the desktop
 * project ignores this file.
 *
 * Why this exists as its own project rather than another desktop leg: AH-056
 * added a sixth nav item and clipped two tabs off the bottom bar on a real
 * phone while the full 24/24 desktop suite stayed green. It could not have
 * caught it — `v-bottom-navigation` only renders under Vuetify's `smAndDown`,
 * so at desktop width the bar does not exist to be broken. The AH-057 unit
 * specs pin the STRUCTURE (four slots + More, the union of bar and sheet being
 * the whole nav) by narrowing jsdom's window; what they cannot do is measure,
 * because jsdom has no layout engine. This leg measures.
 *
 * The one assertion that matters here is therefore geometric: every bottom-bar
 * item's box must sit inside the viewport. That is the exact fact the eyes-on
 * screenshot disproved, and the exact fact no other gate can see.
 */

const PASSWORD = 'Cata1yst-MobileShell-E2E!'

function uniqueEmail(): string {
  return `mobile-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('AH-057 — creator shell at phone width', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
  })

  test.afterEach(async ({ request }) => {
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('fits every bottom-bar item in the viewport and reaches the rest through More', async ({
    page,
  }) => {
    const request = page.context().request
    const email = uniqueEmail()

    await signUpUser(request, email, PASSWORD, 'Mobile Shell E2E')
    await verifyEmailViaApi(request, email)

    // An APPROVED creator is the worst case for the bar: it is the only status
    // that gets all six sections, and six is what broke it.
    await seedListedJob(request, email, {
      campaignName: 'Mobile shell job',
      brandName: 'Northwind Coffee',
      listingFee: '€200 per video',
      listingDuration: '2 weeks',
      description: 'Seeded so the creator is approved and rostered.',
    })

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    await page.goto('/creator/dashboard')

    const bar = page.locator('[data-test="creator-bottom-nav"]')
    await expect(bar).toBeVisible({ timeout: 10_000 })

    // The bar renders before the onboarding store resolves `application_status`,
    // so for a moment it holds four buttons rather than five — waiting on the
    // approved-only item is what makes the count below deterministic.
    await expect(page.locator('[data-test="creator-bottom-nav-jobs"]')).toBeVisible({
      timeout: 10_000,
    })

    // The desktop topbar nav is the other half of the same array and must NOT
    // render here — if it did, the shell would be showing both navs at once.
    await expect(page.locator('[data-test="creator-nav"]')).toHaveCount(0)

    // ── The geometric assertion: nothing hangs off either edge. ──────────────
    const viewport = page.viewportSize()
    expect(viewport).not.toBeNull()
    const width = viewport?.width ?? 0
    expect(width).toBeGreaterThan(0)

    const barItems = bar.locator('button, a')
    const itemCount = await barItems.count()
    // Four primary slots + More. Non-vacuity: a bar that rendered nothing would
    // pass every "inside the viewport" check below.
    expect(itemCount).toBe(5)

    // The row itself must not overflow. This is the fact that actually failed on
    // a real phone: `scrollWidth > clientWidth` means the bar is wider than the
    // screen, and a bar that cannot scroll or wrap answers that by cutting its
    // outermost items off at the edge.
    const overflow = await bar.evaluate((el) => {
      const content = el.querySelector('.v-bottom-navigation__content') ?? el
      return {
        bar: el.scrollWidth - el.clientWidth,
        content: content.scrollWidth - content.clientWidth,
      }
    })
    expect(overflow.bar, 'the bottom bar overflows its own width').toBeLessThanOrEqual(1)
    expect(overflow.content, 'the bottom bar row overflows the bar').toBeLessThanOrEqual(1)

    for (let i = 0; i < itemCount; i += 1) {
      const item = barItems.nth(i)
      const label = (await item.getAttribute('data-test')) ?? `item-${i}`
      const box = await item.boundingBox()
      expect(box, `${label} has no box`).not.toBeNull()
      // `>= -1` rather than `>= 0`: sub-pixel layout rounding can put a
      // flush-left box at -0.5, which is not clipping.
      expect(box?.x ?? -99, `${label} runs off the LEFT edge`).toBeGreaterThanOrEqual(-1)
      expect(
        (box?.x ?? 0) + (box?.width ?? 0),
        `${label} runs off the RIGHT edge (viewport ${width}px)`,
      ).toBeLessThanOrEqual(width + 1)

      // And the part a creator actually reads. A button box can sit inside the
      // viewport while its label spills out of it, so the label is asserted in
      // its own right — the eyes-on screenshot was of clipped LABELS.
      const text = item.locator('.creator-bottom-nav__label')
      const textBox = await text.boundingBox()
      expect(textBox, `${label} has no label box`).not.toBeNull()
      expect(textBox?.x ?? -99, `${label}'s label runs off the LEFT edge`).toBeGreaterThanOrEqual(
        -1,
      )
      expect(
        (textBox?.x ?? 0) + (textBox?.width ?? 0),
        `${label}'s label runs off the RIGHT edge (viewport ${width}px)`,
      ).toBeLessThanOrEqual(width + 1)
    }

    // ── The overflow is reachable, and carries what the bar dropped. ─────────
    await expect(page.locator('[data-test="creator-bottom-nav-profile"]')).toHaveCount(0)
    await page.locator('[data-test="creator-bottom-nav-more"]').click()

    const moreList = page.locator('[data-test="creator-bottom-nav-more-list"]')
    await expect(moreList).toBeVisible()
    await expect(
      moreList.locator('[data-test="creator-bottom-nav-more-availability"]'),
    ).toBeVisible()

    // Displaced does not mean unreachable — the sheet must actually navigate.
    await moreList.locator('[data-test="creator-bottom-nav-more-profile"]').click()
    await expect(page).toHaveURL(/\/creator\/profile$/, { timeout: 10_000 })

    // ── And the primary slots still navigate at this width. ──────────────────
    await page.locator('[data-test="creator-bottom-nav-jobs"]').click()
    await expect(page).toHaveURL(/\/creator\/jobs$/, { timeout: 10_000 })
    await expect(page.locator('[data-testid="creator-jobs"]')).toBeVisible()
  })
})
