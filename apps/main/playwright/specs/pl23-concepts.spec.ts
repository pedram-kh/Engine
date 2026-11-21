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
 * PL-2.3 — the concepts step in a real browser, at a desktop width and a phone width (WORKING-PROCESS §4.2).
 *
 * Each test sets up its own agency, brand, wave, persona and concepts through the API, then checks what unit tests
 * cannot: the page is not wider than the screen, a hostile client name, comment and link title show as plain text,
 * the A1 confirmation (Cancel changes nothing, OK sends the concept back to Awaiting client), and that an http: and a
 * javascript: link are refused while an https: link opens in a new tab with rel="noopener noreferrer".
 *
 * Image upload needs a real S3-compatible disk for the presigned PUT; it is walked by hand (see pl-2-3-review.md),
 * not here.
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
) {
  const { email, password, agencyUlid } = await seedAgencyAdmin(request)
  await page.setViewportSize({ width: size.width, height: size.height })
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()

  const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
    name: `Concepts Bolt ${size.name}`,
    slug: `concepts-bolt-${size.name}`,
    description: 'A brand for the concepts check.',
    industry: 'Food',
    website_url: 'https://example.com',
  })
  const wave = await api(page, 'POST', `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`, {
    name: 'Concepts wave',
    go_live_on: '2027-03-01',
    markets: ['SK'],
    campaign_id: null,
  })
  const base = `/agencies/${agencyUlid}/brands/${brand.data.id}/waves/${wave.data.id}/strategy`
  const persona = await api(page, 'POST', `${base}/personas`, {
    market: 'SK',
    title: 'The Office Organiser',
    name: 'Eva',
  })
  const closeDrawer = async (): Promise<void> => {
    if (size.width >= 768) return
    const drawer = page.locator('.v-navigation-drawer')
    await expect(async () => {
      if ((await drawer.getAttribute('inert')) === null)
        await page.locator('.v-app-bar-nav-icon').click()
      await expect(drawer).toHaveAttribute('inert', '', { timeout: 1000 })
    }).toPass({ timeout: 8000 })
    await page.waitForTimeout(400)
  }
  const concept = (title: string) =>
    api(page, 'POST', `${base}/concepts`, {
      market: 'SK',
      persona: persona.data.id,
      title,
    })
  const open = async (): Promise<void> => {
    await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}/strategy/concepts?market=SK`)
    await closeDrawer()
    await expect(page.locator('[data-test="concepts-grid"]')).toBeVisible()
  }
  return { base, concept, open, page }
}

async function sideways(page: Page): Promise<number> {
  return page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )
}

test.describe('Concepts step', () => {
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
    test(`at ${size.name}: decision with hostile text, A1 confirmation, no sideways scroll`, async ({
      page,
      request,
    }) => {
      const alerts: string[] = []
      page.on('dialog', async (dialog) => {
        alerts.push(dialog.message())
        await dialog.dismiss()
      })
      const s = await setup(page, request, size)
      const first = await s.concept('Team lunch')
      await s.concept('Second idea')
      await s.concept('Third idea')
      await api(page, 'POST', `${s.base}/concepts/${first.data.id}/decision`, {
        status: 'approved',
        client_name: 'Dana',
      })
      await s.open()
      await expect(page.locator('[data-test^="concept-card-"]')).toHaveCount(3)
      await expect(page.locator('[data-test="concepts-banner-text"]')).toContainText('1 of 3')
      expect(await sideways(page)).toBeLessThanOrEqual(1)

      // A1: Cancel changes nothing, OK clears the approval.
      const card = page.locator(`[data-test="concept-card-${first.data.id}"]`)
      await expect(card.locator('[data-test="concept-status"]')).toHaveText('Approved')
      await card.locator('[data-test="concept-menu"]').click()
      await page.locator('[data-test="concept-menu-edit"]').click()
      await page.locator('[data-test="concept-field-title"] input').fill('Team lunch v2')
      await page.locator('[data-test="concept-dialog-save"]').click()
      await expect(page.locator('[data-test="concept-reset-dialog"]')).toBeVisible()
      await page.locator('[data-test="confirm-cancel"]').click()
      await expect(card.locator('[data-test="concept-status"]')).toHaveText('Approved')
      await expect(card.locator('[data-test="concept-title"]')).toHaveText('Team lunch')
      await page.locator('[data-test="concept-dialog-save"]').click()
      await page.locator('[data-test="confirm-ok"]').click()
      await expect(card.locator('[data-test="concept-status"]')).toHaveText('Awaiting client')
      await expect(card.locator('[data-test="concept-title"]')).toHaveText('Team lunch v2')

      // Hostile client name and comment show as text.
      await page
        .locator(`[data-test="concept-card-${first.data.id}"] [data-test="concept-menu"]`)
        .click()
      await page.locator('[data-test="concept-menu-decide"]').click()
      await page.locator('[data-test="concept-decision-changes"]').click()
      await page.locator('[data-test="concept-decision-name"] input').fill(HOSTILE)
      await page
        .locator('[data-test="concept-decision-comment-field"] textarea:not([aria-hidden])')
        .fill(HOSTILE)
      await page.locator('[data-test="concept-decision-save"]').click()
      await expect(card.locator('[data-test="concept-comment"]')).toHaveText(HOSTILE)
      await expect(card.locator('[data-test="concept-footer-changes"]')).toContainText(HOSTILE)
      await expect(page.locator('img[src="x"]')).toHaveCount(0)
      expect(alerts).toEqual([])
      expect(await sideways(page)).toBeLessThanOrEqual(1)
    })

    test(`at ${size.name}: links - http and javascript refused, https opens safely, dialogs reopen`, async ({
      page,
      request,
    }) => {
      const s = await setup(page, request, size)
      const c = await s.concept('Links idea')
      await s.open()
      const card = page.locator(`[data-test="concept-card-${c.data.id}"]`)
      for (const delay of [0, 60, 150]) {
        await card.locator('[data-test="concept-link-add"]').click()
        await expect(page.locator('[data-test="concept-link-dialog"]')).toBeVisible()
        await page.locator('[data-test="concept-link-cancel"]').click()
        await page.waitForTimeout(delay)
      }
      for (const bad of ['http://example.com/page', 'javascript:alert(1)']) {
        await card.locator('[data-test="concept-link-add"]').click()
        await page.locator('[data-test="concept-link-title"] input').fill('Bad')
        await page.locator('[data-test="concept-link-url"] input').fill(bad)
        await page.locator('[data-test="concept-link-save"]').click()
        await expect(page.locator('[data-test="concept-link-dialog"]')).toContainText(
          'Only https links are allowed.',
        )
        await page.locator('[data-test="concept-link-cancel"]').click()
      }
      await expect(card.locator('[data-test="concept-link"]')).toHaveCount(0)

      await card.locator('[data-test="concept-link-add"]').click()
      await page.locator('[data-test="concept-link-title"] input').fill(HOSTILE)
      await page.locator('[data-test="concept-link-url"] input').fill('https://www.example.com/ok')
      await page.locator('[data-test="concept-link-save"]').click()
      const anchor = card.locator('[data-test="concept-link-anchor"]')
      await expect(anchor).toHaveText(HOSTILE)
      await expect(anchor).toHaveAttribute('target', '_blank')
      await expect(anchor).toHaveAttribute('rel', 'noopener noreferrer')
      await expect(page.locator('img[src="x"]')).toHaveCount(0)
      expect(await sideways(page)).toBeLessThanOrEqual(1)
    })
  }
})
