import type { APIRequestContext, Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  setFeatureFlag,
} from '../fixtures/test-helpers'
import { dt, testIds } from '../helpers/selectors'

/**
 * PL-2.4 — markets in a real browser. Short tests, each with its own setup, serial (one shared e2e database).
 *
 * 1. The builder in the New wave dialog: a single-country market takes the country's name and short code, a country in
 *    another market of the wave is unavailable and says which, a multi-country market is created, and the wave page and the
 *    Brand waves table show the NAME. A hostile market name is text.
 * 2. The market tabs of the strategy steps: the name is the tab, hover shows the countries, a tap and Tab+Enter open the
 *    same list, and the tab address carries the market's ULID.
 */

test.describe.configure({ mode: 'serial' })

const HOSTILE = '<img src=x onerror=alert(1)>Bad & "co"'

type Json = { data: { id: string; attributes?: { markets?: { id: string; code: string }[] } } }

async function api(page: Page, method: string, path: string, body?: unknown): Promise<Json> {
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
      return (text === '' ? {} : JSON.parse(text)) as Json
    },
    { method, path, body },
  )
}

async function signedInBrand(page: Page, request: APIRequestContext, slug: string) {
  const { email, password, agencyUlid } = await seedAgencyAdmin(request)
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()
  const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
    name: `Markets ${slug}`,
    slug,
    description: 'A brand for the markets check.',
    industry: 'Food',
    website_url: 'https://example.com',
  })
  return { agencyUlid, brandId: brand.data.id }
}

async function pickCountry(page: Page, card: number, code: string): Promise<void> {
  await page.locator(`[data-test="market-card-${card}"] [data-test="market-add-country"]`).click()
  await page.locator(`[data-test="market-option-${code}"]`).click()
  await page.keyboard.press('Escape')
}

test.describe('Market builder and tabs (PL-2.4)', () => {
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

  test('the builder makes a single-country and a multi-country market, and names show everywhere', async ({
    page,
    request,
  }) => {
    const { brandId } = await signedInBrand(page, request, 'mk-builder')
    await page.setViewportSize({ width: 1600, height: 1000 })
    await page.goto(`/brands/${brandId}`)
    await page.locator('[data-test="wave-new-btn"]').click()
    await expect(page.locator('[data-test="wave-dialog"]')).toBeVisible()
    await page.locator('[data-test="wave-name"] input').fill('Builder wave')
    await page.locator('[data-test="wave-go-live"] input').fill('2027-05-01')

    // One country: the name and the short code follow it.
    await pickCountry(page, 0, 'SK')
    await expect(
      page.locator('[data-test="market-name-input"]').first().locator('input'),
    ).toHaveValue('Slovakia')
    await expect(
      page.locator('[data-test="market-code-input"]').first().locator('input'),
    ).toHaveValue('SK')

    // A second market; Slovakia is taken and says where.
    await page.locator('[data-test="market-add"]').click()
    await page.locator('[data-test="market-card-1"] [data-test="market-add-country"]').click()
    const taken = page.locator('[data-test="market-option-SK"]')
    await expect(taken).toBeVisible()
    await expect(taken).toContainText('Slovakia')
    await expect(taken).toHaveClass(/v-list-item--disabled/)
    await page.locator('[data-test="market-option-GB"]').click()
    await page.locator('[data-test="market-add-country"]').nth(1).click()
    await page.locator('[data-test="market-option-IE"]').click()
    await page.keyboard.press('Escape')
    const name = page.locator('[data-test="market-card-1"] [data-test="market-name-input"] input')
    await name.fill(HOSTILE)
    await page.locator('[data-test="wave-submit"]').click()
    await expect(page.locator('[data-test="wave-dialog"]')).toBeHidden()
    await expect(page.locator('[data-test="wave-field-markets"]')).toContainText('Slovakia')
    await page.goto(`/brands/${brandId}`)

    // The Brand waves table shows names, as text, with the countries behind the info button.
    const row = page.locator('[data-test="wave-row-markets"]').first()
    await expect(row).toContainText('Slovakia')
    await expect(row).toContainText(HOSTILE)
    await expect(page.locator('img[src="x"]')).toHaveCount(0)
    await row.locator('[data-test^="wave-markets-info-"]').click()
    await expect(page.locator('[data-test="wave-markets-countries"]')).toContainText('Ireland')
  })

  test('tabs: the name is the tab; hover, tap and Tab+Enter open the countries; the address carries the ULID', async ({
    page,
    request,
  }) => {
    const { agencyUlid, brandId } = await signedInBrand(page, request, 'mk-tabs')
    const wave = await api(page, 'POST', `/agencies/${agencyUlid}/brands/${brandId}/waves`, {
      name: 'Tabs wave',
      go_live_on: '2027-05-01',
      markets: [
        { name: 'Slovakia', code: 'SK', countries: ['SK'] },
        { name: 'UK & Ireland', code: 'UKI', countries: ['GB', 'IE'] },
      ],
      campaign_id: null,
    })
    const shown = await api(
      page,
      'GET',
      `/agencies/${agencyUlid}/brands/${brandId}/waves/${wave.data.id}`,
    )
    const uki = shown.data.attributes!.markets!.find((m) => m.code === 'UKI')!.id
    await page.setViewportSize({ width: 1600, height: 1000 })
    await page.goto(`/brands/${brandId}/waves/${wave.data.id}/strategy/personas`)

    const tab = page.locator('[data-test="market-tab-UKI"]')
    await expect(tab).toContainText('UK & Ireland')
    await tab.click()
    await expect(page).toHaveURL(new RegExp(`market=${uki}`))

    // Mouse hover: the tooltip lists the countries.
    await page.locator('[data-test="market-tab-SK"]').hover()
    await tab.hover()
    await expect(
      page.locator('.v-tooltip .v-overlay__content', { hasText: 'Ireland' }).last(),
    ).toBeVisible()
    await page.mouse.move(5, 5)

    // Keyboard: Tab to the info button after the tab, Enter opens the list, Escape closes it.
    const info = page.locator('[data-test="market-info-UKI"]')
    await info.focus()
    await page.keyboard.press('Enter')
    const list = page.locator('[data-test="market-countries"]')
    await expect(list).toContainText('United Kingdom')
    await expect(list.locator('[data-test="market-country-IE"]')).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(list).toBeHidden()

    // The wave page chip has the same list.
    await page.goto(`/brands/${brandId}/waves/${wave.data.id}`)
    await page.locator('[data-test="wave-market-UKI"] [data-test="market-info-UKI"]').click()
    await expect(page.locator('[data-test="market-countries"]')).toContainText('Ireland')
  })
})
