import type { Locator, Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  setFeatureFlag,
} from '../fixtures/test-helpers'
import { dt, testIds } from '../helpers/selectors'

/**
 * PL-1.3 — the wave tracker in a real browser, at a desktop width and a phone width (WORKING-PROCESS §4.2).
 *
 * Opens a fresh wave (the first open provisions its 24 steps), then checks what unit tests cannot: the three
 * stream cards stack at 390 and sit in a row at 1440, nothing makes the page scroll sideways, the edit dialog's
 * owner menu opens ABOVE the dialog, and a menu left open when the dialog closes does not survive a fast reopen
 * (the PL-1.2 D1 lesson), and the Remove stream confirm and Add stream menu work end to end.
 */

test.describe.configure({ mode: 'serial' })

const WIDTHS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '390', width: 390, height: 844 },
] as const

async function api(
  page: Page,
  method: string,
  path: string,
  body?: unknown,
): Promise<{ data: { id: string } }> {
  return page.evaluate(
    async ({ method, path, body }) => {
      const token = decodeURIComponent(
        document.cookie
          .split('; ')
          .find((row) => row.startsWith('XSRF-TOKEN='))
          ?.slice('XSRF-TOKEN='.length) ?? '',
      )
      const response = await fetch(`/api/v1${path}`, {
        method,
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': token,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      })
      if (!response.ok) throw new Error(`${method} ${path} failed: ${response.status}`)
      return (await response.json()) as { data: { id: string } }
    },
    { method, path, body },
  )
}

test.describe('Wave tracker', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
    await setFeatureFlag(request, 'waves_enabled', true)
  })

  test.afterEach(async ({ request }) => {
    await setFeatureFlag(request, 'waves_enabled', false)
    await restoreThrottle(request, 'auth-ip')
  })

  for (const size of WIDTHS) {
    test(`at ${size.name}: cards, no sideways scroll, menus above the dialog, fast reopen, stream controls`, async ({
      page,
      request,
    }) => {
      const { email, password, agencyUlid } = await seedAgencyAdmin(request)
      await page.setViewportSize({ width: size.width, height: size.height })

      await page.goto('/sign-in')
      await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
      await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
      await page.locator(dt(testIds.signInSubmit)).click()
      await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()

      const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
        name: `Tracker Bolt ${size.name}`,
        slug: `tracker-bolt-${size.name}`,
        description: 'A brand for the tracker check.',
        industry: 'Food',
        website_url: 'https://example.com',
      })
      const wave = await api(
        page,
        'POST',
        `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`,
        {
          name: 'Tracker wave',
          go_live_on: '2027-03-01',
          markets: ['IE'],
          campaign_id: null,
        },
      )

      await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}`)

      // At phone width the agency shell opens its 280 px drawer over the page (the frozen agency layout starts
      // it open). Close it, as a person would, before looking at the page itself.
      const closeDrawer = async (): Promise<void> => {
        if (size.width >= 768) return
        await page.locator('.v-app-bar-nav-icon').click()
        await expect(page.locator('.v-navigation-drawer')).toHaveAttribute('inert', '')
      }
      await closeDrawer()

      // First open: three cards, 24 open steps.
      for (const stream of ['ugc_video', 'statics', 'stills']) {
        await expect(page.locator(`[data-test="stream-card-${stream}"]`)).toBeVisible()
      }
      await expect(page.locator('[data-test^="step-row-"]')).toHaveCount(24)
      await expect(page.locator('[data-test="wave-health"]')).toContainText('On track')

      // Layout: a row of three at desktop, stacked at 390; never a sideways page scroll.
      const boxes = await Promise.all(
        ['ugc_video', 'statics', 'stills'].map((s) =>
          page.locator(`[data-test="stream-card-${s}"]`).boundingBox(),
        ),
      )
      if (size.width >= 1280) {
        expect(boxes[0]!.y).toBeCloseTo(boxes[1]!.y, 0)
        expect(boxes[1]!.y).toBeCloseTo(boxes[2]!.y, 0)
      } else {
        expect(boxes[1]!.y).toBeGreaterThan(boxes[0]!.y + boxes[0]!.height - 1)
        expect(boxes[2]!.y).toBeGreaterThan(boxes[1]!.y + boxes[1]!.height - 1)
      }
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(overflow).toBeLessThanOrEqual(1)

      // Mark Personas done: it folds into the completed line, and the bar's second segment fills.
      const personas = page
        .locator('[data-test^="step-row-"]')
        .filter({ has: page.locator('[data-test^="step-name-"]', { hasText: /^Personas$/ }) })
      await personas.locator('[data-test^="step-check-"]').click()
      await expect(page.locator('[data-test="steps-completed"]').first()).toContainText(
        '1 completed step',
      )
      await expect(
        page.locator('[data-test="wave-field-progress"] .wave-progress__segment').nth(1),
      ).toHaveAttribute('data-state', 'done')

      // The edit dialog's owner menu opens above the dialog.
      const ownerButton = page.locator('[data-test^="step-owner-"]').first()
      await ownerButton.click()
      await expect(page.locator('[data-test="step-dialog"]')).toBeVisible()
      await page.locator('[data-test="step-owner-select"]').click()
      const menu = page.locator('.wave-dialog-menu')
      await expect(menu).toBeVisible()
      await expect.poll(async () => topmost(menu)).toBe(true)
      await page.keyboard.press('Escape')
      await page.locator('[data-test="step-cancel"]').dispatchEvent('click')
      await expect(page.locator('[data-test="step-dialog"]')).toBeHidden()

      // A menu left open when the dialog closes does not survive a quick reopen.
      for (const delayMs of [0, 60, 150]) {
        await ownerButton.dispatchEvent('click')
        await expect(page.locator('[data-test="step-dialog"]')).toBeVisible()
        await page.locator('[data-test="step-owner-select"]').click()
        await expect(page.locator('.wave-dialog-menu')).toBeVisible()
        await page.locator('[data-test="step-cancel"]').dispatchEvent('click')
        await page.waitForTimeout(delayMs)
        await ownerButton.dispatchEvent('click')
        await expect(page.locator('[data-test="step-dialog"]')).toBeVisible()
        await expect(page.locator('.wave-dialog-menu')).toHaveCount(0)
        await page.locator('[data-test="step-owner-select"]').click()
        const reopened = page.locator('.wave-dialog-menu')
        await expect(reopened).toBeVisible()
        await expect.poll(async () => topmost(reopened)).toBe(true)
        await page.mouse.click(2, 2)
        await page.locator('[data-test="step-cancel"]').dispatchEvent('click')
        await expect(page.locator('[data-test="step-dialog"]')).toBeHidden()
      }

      // Remove stream: UGC has no control; Statics asks first, then goes; Add stream brings it back.
      await expect(page.locator('[data-test="stream-menu-ugc_video"]')).toHaveCount(0)
      await page.locator('[data-test="stream-menu-statics"]').click()
      await page.locator('[data-test="stream-remove-statics"]').click()
      await expect(page.locator('[data-test="confirm-body"]')).toContainText(
        'Statics and its 5 steps',
      )
      await page.locator('[data-test="confirm-cancel"]').click()
      await expect(page.locator('[data-test="confirm-dialog"]')).toBeHidden()
      await expect(page.locator('[data-test="stream-card-statics"]')).toBeVisible()

      await page.locator('[data-test="stream-menu-statics"]').click()
      await page.locator('[data-test="stream-remove-statics"]').click()
      await page.locator('[data-test="confirm-ok"]').click()
      await expect(page.locator('[data-test="stream-card-statics"]')).toHaveCount(0)
      await page.reload()
      await closeDrawer()
      await expect(page.locator('[data-test="stream-card-stills"]')).toBeVisible()
      await expect(page.locator('[data-test="stream-card-statics"]')).toHaveCount(0)

      await page.locator('[data-test="stream-add"]').click()
      await page.locator('[data-test="stream-add-statics"]').click()
      await expect(page.locator('[data-test="stream-card-statics"]')).toBeVisible()
      await expect(page.locator('[data-test="stream-add"]')).toHaveCount(0)

      const after = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(after).toBeLessThanOrEqual(1)
    })
  }
})

/** True when the element is what a click would hit, not a dialog painted over it. */
async function topmost(locator: Locator): Promise<boolean> {
  return locator.evaluate((element) => {
    const rect = element.getBoundingClientRect()
    const hit = document.elementFromPoint(
      rect.left + Math.min(12, rect.width / 2),
      rect.top + rect.height / 2,
    )
    return hit !== null && (element === hit || element.contains(hit))
  })
}
