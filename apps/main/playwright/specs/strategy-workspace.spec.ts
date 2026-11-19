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
 * PL-2.1 — the Strategy & Scripts workspace in a real browser, at a desktop width and a phone width
 * (WORKING-PROCESS §4.2).
 *
 * Checks what unit tests cannot: the entry button from the wave page, the stepper and the two live steps, every
 * dialog and menu opening above the page and surviving a quick close-and-reopen, nothing scrolling the page
 * sideways, and a hostile string typed into a USP and a persona showing as plain text (no element, no alert).
 * Also: with `strategy_enabled` off the wave page has no entry and the workspace address leaves for the wave.
 */

test.describe.configure({ mode: 'serial' })

const WIDTHS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '390', width: 390, height: 844 },
] as const

const HOSTILE = '<img src=x onerror=alert(1)>'

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
      const text = await response.text()
      return (text === '' ? {} : JSON.parse(text)) as { data: { id: string } }
    },
    { method, path, body },
  )
}

async function sideways(page: Page): Promise<number> {
  return page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )
}

test.describe('Strategy workspace', () => {
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
    test(`at ${size.name}: entry, USPs, personas, angles, dialogs and menus, hostile text`, async ({
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

      await page.goto('/sign-in')
      await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
      await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
      await page.locator(dt(testIds.signInSubmit)).click()
      await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()

      const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
        name: `Strategy Bolt ${size.name}`,
        slug: `strategy-bolt-${size.name}`,
        description: 'A brand for the strategy check.',
        industry: 'Food',
        website_url: 'https://example.com',
      })
      const wave = await api(
        page,
        'POST',
        `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`,
        {
          name: 'Strategy wave',
          go_live_on: '2027-03-01',
          markets: ['SK', 'RO'],
          campaign_id: null,
        },
      )
      const base = `/agencies/${agencyUlid}/brands/${brand.data.id}/waves/${wave.data.id}/strategy`

      const closeDrawer = async (): Promise<void> => {
        if (size.width >= 768) return
        const drawer = page.locator('.v-navigation-drawer')
        if ((await drawer.getAttribute('inert')) === null) {
          await page.locator('.v-app-bar-nav-icon').click()
          await expect(drawer).toHaveAttribute('inert', '')
        }
      }

      // Entry from the wave page.
      await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}`)
      await closeDrawer()
      await page.locator('[data-test="wave-strategy-btn"]').click()
      await expect(page).toHaveURL(/\/strategy\/usps$/)
      await closeDrawer()
      await expect(page.locator('[data-test="strategy-heading"]')).toHaveText('Strategy & Scripts')
      await expect(page.locator('[data-test="strategy-subtitle"]')).toContainText(
        `Strategy Bolt ${size.name} · Strategy wave`,
      )
      await expect(page.locator('[data-test="usps-empty"]')).toBeVisible()
      await expect(page.locator('[data-test="usp-counter"]')).toHaveText('0 of 6 USPs')
      await expect(page.locator('[data-test="usp-warning"]')).toBeVisible()

      // Stepper: six steps, two live links, four plain elements.
      await expect(page.locator('[data-test^="strategy-step-"][data-test$="-status"]')).toHaveCount(
        6,
      )
      for (const key of ['matrix', 'concepts', 'creators', 'scripts']) {
        await expect(page.locator(`[data-test="strategy-step-${key}"]`)).toHaveAttribute(
          'aria-disabled',
          'true',
        )
      }
      expect(await sideways(page)).toBeLessThanOrEqual(1)

      // Quick open, close and reopen of the Add USP dialog: three speeds.
      for (const delay of [0, 60, 150]) {
        await page.locator('[data-test="usp-add-empty"]').click()
        await expect(page.locator('[data-test="usp-dialog"]')).toBeVisible()
        await page.locator('[data-test="usp-dialog-cancel"]').click()
        await page.waitForTimeout(delay)
        await page.locator('[data-test="usp-add-empty"]').click()
        await expect(page.locator('[data-test="usp-dialog-save"]')).toBeVisible()
        await page.locator('[data-test="usp-dialog-cancel"]').click()
        await expect(page.locator('[data-test="usp-dialog"]')).toHaveCount(0)
      }

      // An empty title is refused on the page; then a hostile title saves as text.
      await page.locator('[data-test="usp-add-empty"]').click()
      await page.locator('[data-test="usp-dialog-save"]').click()
      await expect(page.locator('[data-test="usp-dialog"]')).toContainText('Enter a title.')
      await page.locator('[data-test="usp-field-title"] input').fill(HOSTILE)
      await page
        .locator('[data-test="usp-field-explainer"] textarea:not([aria-hidden])')
        .fill(HOSTILE)
      await page.locator('[data-test="usp-dialog-save"]').click()
      await expect(page.locator('[data-test="usp-card"]')).toHaveCount(1)
      await expect(page.locator('[data-test="usp-title"]').first()).toHaveText(HOSTILE)
      await expect(page.locator('[data-test="usp-explainer"]').first()).toContainText(HOSTILE)
      await expect(page.locator('img[src="x"]')).toHaveCount(0)

      // D3: a save refused because the card was removed by someone else shows its alert in full, above the
      // actions, without scrolling; Close then reloads the grid.
      const gone = await api(page, 'POST', `${base}/usps`, { title: 'Gone soon' })
      await page.reload()
      await closeDrawer()
      await expect(page.locator('[data-test="usp-card"]')).toHaveCount(2)
      const goneCard = page.locator('[data-test="usp-card"]').filter({ hasText: 'Gone soon' })
      await goneCard.locator('[data-test="usp-menu-btn"]').click()
      await page.locator('[data-test="usp-menu-edit"]').click()
      await page.locator('[data-test="usp-field-title"] input').fill('Gone soon, edited')
      await api(page, 'DELETE', `${base}/usps/${gone.data.id}`)
      await page.locator('[data-test="usp-dialog-save"]').click()
      const goneAlert = page.locator('[data-test="usp-dialog"] .strategy-dialog-error')
      await expect(goneAlert).toContainText('removed by someone else')
      const alertBox = await goneAlert.boundingBox()
      const actionsBox = await page.locator('[data-test="usp-dialog-cancel"]').boundingBox()
      if (alertBox === null || actionsBox === null) throw new Error('no box')
      expect(alertBox.y).toBeGreaterThanOrEqual(0)
      expect(alertBox.y + alertBox.height).toBeLessThanOrEqual(actionsBox.y + 1)
      expect(alertBox.y + alertBox.height).toBeLessThanOrEqual(size.height)
      expect(
        await goneAlert.evaluate((el) => el.scrollHeight - el.clientHeight),
      ).toBeLessThanOrEqual(1)
      await page.locator('[data-test="usp-dialog-cancel"]').click()
      await expect(page.locator('[data-test="usp-card"]')).toHaveCount(1)

      // Five more through the API, then the maximum.
      for (const title of ['Fast', 'Cheap', 'Local', 'Open late', 'Tracked']) {
        await api(page, 'POST', `${base}/usps`, { title })
      }
      await page.reload()
      await closeDrawer()
      await expect(page.locator('[data-test="usp-card"]')).toHaveCount(6)
      await expect(page.locator('[data-test="usp-counter"]')).toHaveText(
        '6 of 6 USPs · maximum reached',
      )
      await expect(page.locator('[data-test="usp-add"]')).toBeDisabled()
      await expect(page.locator('[data-test="usp-warning"]')).toHaveCount(0)
      expect(await sideways(page)).toBeLessThanOrEqual(1)

      // Card menu: open, close, reopen quickly; Move later keeps the full order.
      const first = page.locator('[data-test="usp-card"]').first()
      for (const delay of [0, 60, 150]) {
        await first.locator('[data-test="usp-menu-btn"]').click()
        await expect(page.locator('[data-test="usp-menu-edit"]')).toBeVisible()
        await page.keyboard.press('Escape')
        await page.waitForTimeout(delay)
      }
      await first.locator('[data-test="usp-menu-btn"]').click()
      // The order is saved on the server before the page is reloaded (the screen moves first).
      const saved = page.waitForResponse(
        (r) => r.url().endsWith('/strategy/usps/order') && r.request().method() === 'PUT',
      )
      await page.locator('[data-test="usp-menu-later"]').click()
      expect((await saved).status()).toBe(200)
      await expect(page.locator('[data-test="usp-title"]').nth(1)).toHaveText(HOSTILE)
      await page.reload()
      await closeDrawer()
      await expect(page.locator('[data-test="usp-title"]').nth(1)).toHaveText(HOSTILE)

      // Edit dialog opens above the page with the card's values; closing it sends nothing.
      await page
        .locator('[data-test="usp-card"]')
        .nth(1)
        .locator('[data-test="usp-menu-btn"]')
        .click()
      await page.locator('[data-test="usp-menu-edit"]').click()
      await expect(page.locator('[data-test="usp-field-title"] input')).toHaveValue(HOSTILE)
      await page.locator('[data-test="usp-dialog-cancel"]').click()

      // Audience title.
      await page.locator('[data-test="audience-edit"]').click()
      await page.locator('[data-test="audience-field"] input').fill(HOSTILE)
      await page.locator('[data-test="audience-save"]').click()
      await expect(page.locator('[data-test="audience-title"]')).toHaveText(HOSTILE)

      // Delete confirmation (not in use): open, cancel, reopen quickly.
      const last = page.locator('[data-test="usp-card"]').last()
      for (const delay of [0, 60, 150]) {
        await last.locator('[data-test="usp-menu-btn"]').click()
        await page.locator('[data-test="usp-menu-delete"]').click()
        await expect(page.locator('[data-test="usp-delete-dialog"]')).toBeVisible()
        await page.locator('[data-test="confirm-cancel"]').click()
        await page.waitForTimeout(delay)
      }

      // Personas step.
      await page.locator('[data-test="strategy-step-personas"]').click()
      await expect(page).toHaveURL(/\/strategy\/personas$/)
      await expect(page.locator('[data-test="market-tab-SK"]')).toHaveAttribute(
        'aria-selected',
        'true',
      )
      await expect(page.locator('[data-test="persona-warning"]')).toBeVisible()
      await expect(page.locator('[data-test="persona-tile-text"]')).toHaveText(
        '0 of 8 used in Slovakia',
      )

      for (const delay of [0, 60, 150]) {
        await page.locator('[data-test="persona-add"]').click()
        await expect(page.locator('[data-test="persona-dialog"]')).toBeVisible()
        await page.locator('[data-test="persona-dialog-cancel"]').click()
        await page.waitForTimeout(delay)
      }

      await page.locator('[data-test="persona-add"]').click()
      await page.locator('[data-test="persona-dialog-save"]').click()
      await expect(page.locator('[data-test="persona-dialog"]')).toContainText('Enter a title.')
      await page.locator('[data-test="persona-field-title"] input').fill(HOSTILE)
      await page.locator('[data-test="persona-field-name"] input').fill(HOSTILE)
      await page
        .locator('[data-test="persona-field-summary"] textarea:not([aria-hidden])')
        .fill(HOSTILE)
      await page.locator('[data-test="persona-dialog-save"]').click()
      await expect(page.locator('[data-test="persona-card"]')).toHaveCount(1)
      await expect(page.locator('[data-test="persona-title"]').first()).toHaveText(HOSTILE)
      await expect(page.locator('img[src="x"]')).toHaveCount(0)

      // A second persona by API, then angles through the UI.
      await api(page, 'POST', `${base}/personas`, {
        market: 'SK',
        title: 'Second',
        name: 'Eva',
        pronoun: 'she',
      })
      await page.reload()
      await closeDrawer()
      await expect(page.locator('[data-test="persona-card"]')).toHaveCount(2)
      await expect(page.locator('[data-test="persona-warning"]')).toHaveCount(0)
      await expect(page.locator('[data-test="market-count-SK"]')).toHaveText('2')
      await expect(page.locator('[data-test="market-dot-RO"]')).toBeVisible()

      const card = page.locator('[data-test="persona-card"]').first()
      for (const delay of [0, 60, 150]) {
        await card.locator('[data-test="angle-link-btn"]').click()
        await expect(page.locator('[data-test="angle-usp-option"]').first()).toBeVisible()
        await page.keyboard.press('Escape')
        await page.waitForTimeout(delay)
      }
      await card.locator('[data-test="angle-link-btn"]').click()
      await page.locator('[data-test="angle-usp-option"]').first().click()
      await card
        .locator(
          '[data-test="angle-text"] textarea:not([aria-hidden]), [data-test="angle-text"] input',
        )
        .first()
        .fill(HOSTILE)
      await card.locator('[data-test="angle-add"]').click()
      await expect(card.locator('[data-test="angle-explanation"]')).toHaveText(HOSTILE)
      await expect(card.locator('[data-test="angle-origin"]')).toHaveText('Manually added')
      await expect(page.locator('img[src="x"]')).toHaveCount(0)

      // That USP is now Linked in the list.
      await card.locator('[data-test="angle-link-btn"]').click()
      await expect(page.locator('[data-test="angle-usp-linked"]')).toHaveCount(1)
      await page.keyboard.press('Escape')

      // Market tab: the address carries it; the copy dialog opens above the page.
      await page.locator('[data-test="market-tab-RO"]').click()
      await expect(page).toHaveURL(/market=RO/)
      await expect(page.locator('[data-test="personas-empty"]')).toBeVisible()
      for (const delay of [0, 60, 150]) {
        await page.locator('[data-test="persona-copy"]').click()
        await expect(page.locator('[data-test="copy-dialog"]')).toBeVisible()
        await page.locator('[data-test="copy-cancel"]').click()
        await page.waitForTimeout(delay)
      }
      await page.locator('[data-test="persona-copy"]').click()
      await expect(page.locator('[data-test="copy-line"]')).toContainText(
        'Copying 2 → Romania will have 2 of 8',
      )
      await page.locator('[data-test="copy-submit"]').click()
      await expect(page.locator('[data-test="persona-card"]')).toHaveCount(2)
      expect(await sideways(page)).toBeLessThanOrEqual(1)

      // Deleting the USP the angle uses lists what is affected and says how many angles go with it.
      await page.locator('[data-test="strategy-step-usps"]').click()
      const used = page.locator('[data-test="usp-card"]').first()
      await expect(used.locator('[data-test="usp-angle-count"]')).toBeVisible()
      await used.locator('[data-test="usp-menu-btn"]').click()
      await page.locator('[data-test="usp-menu-delete"]').click()
      await expect(page.locator('[data-test="usp-delete-affected"]')).toContainText(HOSTILE)
      await expect(page.locator('[data-test="confirm-ok"]')).toContainText(
        'Delete and remove 2 angles',
      )
      await page.locator('[data-test="confirm-cancel"]').click()

      expect(alerts).toEqual([])
    })
  }

  test('strategy off: the wave page has no entry and the workspace address leaves for the wave', async ({
    page,
    request,
  }) => {
    await setFeatureFlag(request, 'strategy_enabled', false)
    const { email, password, agencyUlid } = await seedAgencyAdmin(request)
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()

    const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
      name: 'Strategy Off Brand',
      slug: 'strategy-off-brand',
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

    await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}`)
    await expect(page.locator('[data-test="wave-detail-heading"]')).toHaveText('Off wave')
    await expect(page.locator('[data-test="wave-strategy-btn"]')).toHaveCount(0)

    await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}/strategy/usps`)
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.data.id}/waves/${wave.data.id}$`))
    await expect(page.locator('[data-test="strategy-page"]')).toHaveCount(0)

    // The bare address goes to the USPs step and then, flag off, to the wave.
    await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}/strategy`)
    await expect(page).toHaveURL(new RegExp(`/brands/${brand.data.id}/waves/${wave.data.id}$`))
  })
})
