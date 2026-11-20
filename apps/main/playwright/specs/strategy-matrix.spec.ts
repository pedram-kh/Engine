import type { Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  setFeatureFlag,
} from '../fixtures/test-helpers'
import { dt, testIds } from '../helpers/selectors'

/**
 * PL-2.2 — the messaging matrix in a real browser, at a desktop width and a phone width (WORKING-PROCESS §4.2).
 *
 * Each test builds its own wave through the API (two markets, three USPs, two personas in SK), then drives the
 * screen: the cell editor opening above the page and surviving a quick close-and-reopen, a hostile string typed into a
 * rationale and a message line showing as plain text on the cell and on the synced angle, the synced angle read-only on
 * the persona card, and nothing scrolling the page sideways. A second test: strategy off sends the address to the wave.
 */

test.describe.configure({ mode: 'serial' })

const WIDTHS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '390', width: 390, height: 844 },
] as const

const HOSTILE = '<img src=x onerror=alert(1)>'

async function api(page: Page, method: string, path: string, body?: unknown) {
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
      const text = await response.text()
      return (text === '' ? {} : JSON.parse(text)) as { data: { id: string } }
    },
    { method, path, body },
  )
}

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()
}

test.describe('Messaging matrix', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
    await setFeatureFlag(request, 'waves_enabled', true)
    await setFeatureFlag(request, 'strategy_enabled', true)
  })

  test.afterEach(async ({ request }) => {
    await setFeatureFlag(request, 'strategy_enabled', false)
    await setFeatureFlag(request, 'waves_enabled', false)
    await restoreThrottle(request, 'auth-ip')
  })

  for (const size of WIDTHS) {
    test(`at ${size.name}: editor, quick reopen, hostile text, synced angle`, async ({
      page,
      request,
    }) => {
      const alerts: string[] = []
      page.on('dialog', async (dialog) => {
        alerts.push(dialog.message())
        await dialog.dismiss()
      })

      const { email, password, agencyUlid } = await seedAgencyAdmin(request)
      await page.setViewportSize({ width: size.width, height: size.height })
      await signIn(page, email, password)

      const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
        name: `Matrix Bolt ${size.name}`,
        slug: `matrix-bolt-${size.name}`,
        description: 'A brand for the matrix check.',
        industry: 'Food',
        website_url: 'https://example.com',
      })
      const wave = await api(
        page,
        'POST',
        `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`,
        {
          name: 'Matrix wave',
          go_live_on: '2027-03-01',
          markets: ['SK', 'RO'],
          campaign_id: null,
        },
      )
      const base = `/agencies/${agencyUlid}/brands/${brand.data.id}/waves/${wave.data.id}/strategy`
      for (const title of ['Fast', 'Cheap', 'Wide range']) {
        await api(page, 'POST', `${base}/usps`, { title })
      }
      for (const [title, name] of [
        ['First persona', 'Zuzana'],
        ['Second persona', 'Peter'],
      ]) {
        await api(page, 'POST', `${base}/personas`, { market: 'SK', title, name, pronoun: 'she' })
      }

      await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}/strategy/matrix`)
      if (size.width < 768) {
        const drawer = page.locator('.v-navigation-drawer')
        if ((await drawer.getAttribute('inert')) === null) {
          await page.locator('.v-app-bar-nav-icon').click()
          await expect(drawer).toHaveAttribute('inert', '')
        }
      }

      const cells = page.locator('[data-test="matrix-cell"]')
      await expect(cells).toHaveCount(6)
      await expect(page.locator('[data-test="matrix-pill"]').first()).toHaveText(/not set/i)
      await expect(page.locator('[data-test="matrix-lead-none"]')).toHaveCount(2)
      expect(
        await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        ),
      ).toBeLessThanOrEqual(1)

      // Quick open, cancel and reopen: three speeds, never two editors, always a clean one.
      for (const delay of [0, 60, 150]) {
        await cells.first().click()
        await expect(page.locator('[data-test="matrix-editor"]')).toBeVisible()
        await page.locator('[data-test="matrix-cancel"]').click()
        await page.waitForTimeout(delay)
      }
      await cells.first().click({ timeout: 8000 })
      await expect(page.locator('[data-test="matrix-editor"]')).toHaveCount(1)
      // Once the sheet has settled it lies inside the viewport (a phone sheet slides up from below).
      await expect
        .poll(async () => {
          const box = await page.locator('[data-test="matrix-editor"]').boundingBox()
          return (
            box !== null &&
            box.x >= 0 &&
            box.x + box.width <= size.width + 1 &&
            box.y + box.height <= size.height + 1
          )
        })
        .toBe(true)

      // The rule: Priority needs a message line; a 141-character line is refused with the reason visible.
      await page.locator('[data-test="matrix-level-priority"]').click()
      await expect(page.locator('[data-test="matrix-save"]')).toBeDisabled()
      await page.locator('[data-test="matrix-line-input"]').fill('x'.repeat(141))
      await expect(page.locator('[data-test="matrix-line-error"]')).toBeVisible()
      await expect(page.locator('[data-test="matrix-save"]')).toBeDisabled()

      // Hostile text in both fields, saved: plain text on the cell.
      await page.locator('[data-test="matrix-rationale-input"]').fill(HOSTILE)
      await page.locator('[data-test="matrix-line-input"]').fill(HOSTILE)
      await page.locator('[data-test="matrix-save"]').click()
      await expect(page.locator('[data-test="matrix-editor"]')).toHaveCount(0)
      await expect(cells.first()).toContainText(HOSTILE)
      await expect(page.locator('img[src="x"]')).toHaveCount(0)

      // The synced angle on the persona card: read-only, says where to change it, plain text.
      await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}/strategy/personas`)
      await expect(page.locator('[data-test="persona-card"]').first()).toBeVisible()
      const origin = page.locator('[data-test="angle-origin"]').first()
      await expect(origin).toHaveText('Synced from matrix')
      await expect(page.locator('[data-test="angle-matrix-link"]').first()).toBeVisible()
      await expect(page.locator('[data-test="angle-explanation"]').first()).toHaveText(HOSTILE)
      await expect(page.locator('[data-test="angle-menu-btn"]')).toHaveCount(0)
      await expect(page.locator('img[src="x"]')).toHaveCount(0)
      expect(alerts).toEqual([])
    })
  }

  test('with strategy off the matrix address leaves for the wave', async ({ page, request }) => {
    await setFeatureFlag(request, 'strategy_enabled', false)
    const { email, password, agencyUlid } = await seedAgencyAdmin(request)
    await signIn(page, email, password)
    const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
      name: 'Matrix Off Brand',
      slug: 'matrix-off-brand',
      description: 'A brand for the flag-off check.',
      industry: 'Food',
      website_url: 'https://example.com',
    })
    const wave = await api(page, 'POST', `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`, {
      name: 'Off wave',
      go_live_on: '2027-03-01',
      markets: ['SK'],
      campaign_id: null,
    })
    await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}/strategy/matrix`)
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.data.id}/waves/${wave.data.id}$`))
    await expect(page.locator('[data-test="matrix-step"]')).toHaveCount(0)
  })
})
