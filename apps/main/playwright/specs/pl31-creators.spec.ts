import type { APIRequestContext, Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedPendingApplications,
  setFeatureFlag,
} from '../fixtures/test-helpers'
import { dt, testIds } from '../helpers/selectors'
import { marketIds, wireMarkets } from '../helpers/markets'

/**
 * PL-3.1 — the Creators step in a real browser, at a desktop width and a phone width (WORKING-PROCESS §4.2).
 *
 * Each test makes its own agency, brand, wave and campaign through the API, then checks what unit tests cannot: the picker
 * lists the campaign's applicants, ticking and adding puts cards in the grid, the picker can be opened, closed and quickly
 * reopened, a hostile creator name and client name are plain text, a wave with no campaign gets the create path, and the
 * page is not wider than the screen.
 */

test.describe.configure({ mode: 'serial' })

const WIDTHS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '390', width: 390, height: 844 },
] as const

const HOSTILE = '<img src=x onerror=alert(1)>'

type Json = { data: { id: string } }

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

async function setup(
  page: Page,
  request: APIRequestContext,
  size: { name: string; width: number; height: number },
  options: { campaign: boolean; applicants?: string[] },
) {
  const { email, password, agencyUlid } = await seedAgencyAdmin(request)
  await page.setViewportSize({ width: size.width, height: size.height })
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()

  const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
    name: `Creators Bolt ${size.name}`,
    slug: `creators-bolt-${size.name}`,
    description: 'A brand for the creators check.',
    industry: 'Food',
    website_url: 'https://example.com',
  })
  const wavePath = `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`
  const wave = await api(page, 'POST', wavePath, {
    name: 'Creators wave',
    go_live_on: '2027-03-01',
    markets: wireMarkets(['SK']),
    campaign_id: null,
  })
  const base = `${wavePath}/${wave.data.id}`
  const mk = await marketIds((path) => api(page, 'GET', path), base)
  await api(page, 'POST', `${base}/strategy/personas`, {
    market: mk['SK'],
    title: 'The Office Organiser',
    name: 'Eva',
  })
  let campaignName: string | null = null
  if (options.campaign) {
    campaignName = 'Creators campaign'
    const linked = (await api(page, 'POST', `${base}/campaign`, {
      name: campaignName,
    })) as unknown as {
      data: { relationships: { campaign: { data: { id: string } } | null } }
    }
    const campaignUlid = linked.data.relationships.campaign?.data.id
    if (campaignUlid && options.applicants && options.applicants.length > 0) {
      await seedPendingApplications(
        request,
        agencyUlid,
        options.applicants.map((displayName) => ({ displayName })),
        { campaignUlid },
      )
    }
  }
  const closeDrawer = async (): Promise<void> => {
    if (size.width >= 768) return
    const drawer = page.locator('[data-test="agency-sidebar"]')
    await expect(async () => {
      if ((await drawer.getAttribute('inert')) === null)
        await page.locator('.v-app-bar-nav-icon').click()
      await expect(drawer).toHaveAttribute('inert', '', { timeout: 1000 })
    }).toPass({ timeout: 8000 })
    await page.waitForTimeout(400)
  }
  const open = async (): Promise<void> => {
    await page.goto(
      `/brands/${brand.data.id}/waves/${wave.data.id}/strategy/creators?market=${mk['SK']}`,
    )
    await closeDrawer()
    await expect(page.locator('[data-test="creators-step"]')).toBeVisible()
  }
  return { base, open, campaignName, page }
}

async function sideways(page: Page): Promise<number> {
  return page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )
}

test.describe('Creators step', () => {
  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
    await setFeatureFlag(request, 'waves_enabled', true)
    await setFeatureFlag(request, 'strategy_enabled', true)
    await setFeatureFlag(request, 'creators_scripts_enabled', true)
  })

  test.afterEach(async ({ request }) => {
    await setFeatureFlag(request, 'creators_scripts_enabled', false)
    await setFeatureFlag(request, 'strategy_enabled', false)
    await setFeatureFlag(request, 'waves_enabled', false)
    await restoreThrottle(request, 'auth-ip')
  })

  for (const size of WIDTHS) {
    test(`at ${size.name}: pick two applicants, add them, reopen the picker, hostile name as text, no sideways scroll`, async ({
      page,
      request,
    }) => {
      const alerts: string[] = []
      page.on('dialog', async (dialog) => {
        alerts.push(dialog.message())
        await dialog.dismiss()
      })
      const s = await setup(page, request, size, {
        campaign: true,
        applicants: ['Lucia Horvathova', HOSTILE],
      })
      await s.open()
      await expect(page.locator('[data-test="creators-empty"]')).toBeVisible()

      // Open, close, quickly reopen: never a leftover.
      for (const delay of [0, 60, 150]) {
        await page.locator('[data-test="creators-empty-cta"]').click()
        await expect(page.locator('[data-test="creator-picker"]')).toBeVisible()
        await page.locator('[data-test="creator-picker-cancel"]').click()
        await page.waitForTimeout(delay)
      }

      await page.locator('[data-test="creators-empty-cta"]').click()
      // The seeded applicants live in no particular country: the market's chip hides them until it is removed.
      await expect(page.locator('[data-test="creator-picker-empty"]')).toContainText(
        'No creators match',
      )
      await page.locator('[data-test="creator-picker-country-remove-SK"]').click()
      const cards = page.locator('[data-test^="picker-card-"]')
      await expect(cards).toHaveCount(2)
      await cards.nth(0).click()
      await cards.nth(1).click()
      await expect(page.locator('[data-test="creator-picker-selected"]')).toContainText('2')
      await page.locator('[data-test="creator-picker-submit"]').click()
      await expect(page.locator('[data-test="creator-picker"]')).toBeHidden()

      await expect(page.locator('[data-test^="creator-card-"]')).toHaveCount(2)
      await expect(page.locator('[data-test="creators-summary-count"]')).toContainText('2')
      const names = page.locator('[data-test^="creator-name-"]')
      await expect(names.filter({ hasText: HOSTILE })).toHaveCount(1)
      await expect(page.locator('img[src="x"]')).toHaveCount(0)

      // Both are in the wave now: the picker says so and offers nothing to add.
      await page.locator('[data-test="creators-add"]').click()
      await page.locator('[data-test="creator-picker-country-remove-SK"]').click()
      await expect(page.locator('[data-test^="picker-in-wave-"]')).toHaveCount(2)
      await page.locator('[data-test="creator-picker-cancel"]').click()
      expect(alerts).toEqual([])
      expect(await sideways(page)).toBeLessThanOrEqual(1)
    })
  }

  test('client decision with a hostile name is plain text; Remove asks first', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, WIDTHS[0], {
      campaign: true,
      applicants: ['Eva Kovacova'],
    })
    await s.open()
    await page.locator('[data-test="creators-empty-cta"]').click()
    await page.locator('[data-test="creator-picker-country-remove-SK"]').click()
    await page.locator('[data-test^="picker-card-"]').first().click()
    await page.locator('[data-test="creator-picker-submit"]').click()
    await expect(page.locator('[data-test^="creator-card-"]')).toHaveCount(1)

    await page.locator('[data-test^="creator-decide-"]').click()
    await page.locator('[data-test="creator-decision-name"] input').fill(HOSTILE)
    await page.locator('[data-test="creator-decision-save"]').click()
    await expect(page.locator('[data-test^="creator-card-"]')).toContainText(HOSTILE)
    await expect(page.locator('img[src="x"]')).toHaveCount(0)

    await page.locator('[data-test^="creator-remove-"]').first().click()
    await expect(page.locator('[data-test="creator-remove-dialog"]')).toBeVisible()
    await page.locator('[data-test="confirm-cancel"]').click()
    await expect(page.locator('[data-test^="creator-card-"]')).toHaveCount(1)
    await page.locator('[data-test^="creator-remove-"]').first().click()
    await page.locator('[data-test="confirm-ok"]').click()
    await expect(page.locator('[data-test^="creator-card-"]')).toHaveCount(0)
  })

  test('a wave with no campaign: the step explains and Create makes and links one', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, WIDTHS[0], { campaign: false })
    await s.open()
    await expect(page.locator('[data-test="creators-no-campaign"]')).toBeVisible()
    await expect(page.locator('[data-test="creators-grid"]')).toHaveCount(0)
    await page.locator('[data-test="creators-create-name"] input').fill('Created from the step')
    await page.locator('[data-test="creators-create-submit"]').click()
    await expect(page.locator('[data-test="creators-empty"]')).toBeVisible()
    await expect(page.locator('[data-test="creators-empty"]')).toContainText(
      'Created from the step',
    )
  })
})
