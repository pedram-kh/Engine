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
 * PL-3.2a — the Scripts step in a real browser (WORKING-PROCESS §4.2): autosave (type and wait, type and leave at once, a stopped
 * API with the retry), two tabs on the same hook (the 409, the person's own text kept) and on different hooks (both kept), a
 * hostile string as plain text, delete script, and a phone width with no sideways scroll. Each test makes its own agency, brand,
 * wave, campaign, creator and script through the API; serial; each under 15 seconds.
 */

test.describe.configure({ mode: 'serial' })
test.setTimeout(15_000)

const DESKTOP = { width: 1600, height: 1000 }
const PHONE = { width: 390, height: 844 }
const HOSTILE = '<img src=x onerror=alert(1)> {{ 7*7 }} <script>window.__pwn=1</script>'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Json = any

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
      return text === '' ? {} : JSON.parse(text)
    },
    { method, path, body },
  )
}

async function setup(
  page: Page,
  request: APIRequestContext,
  size: { width: number; height: number },
) {
  const { email, password, agencyUlid } = await seedAgencyAdmin(request)
  await page.setViewportSize(size)
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()

  const tag = Math.random().toString(36).slice(2, 8)
  const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
    name: `Scripts Bolt ${tag}`,
    slug: `scripts-bolt-${tag}`,
    description: 'A brand for the scripts check.',
    industry: 'Food',
    website_url: 'https://example.com',
  })
  const wavePath = `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`
  const wave = await api(page, 'POST', wavePath, {
    name: 'Scripts wave',
    go_live_on: '2027-03-01',
    markets: wireMarkets(['SK']),
    campaign_id: null,
  })
  const base = `${wavePath}/${wave.data.id}`
  const mk = await marketIds((path) => api(page, 'GET', path), base)
  const persona = await api(page, 'POST', `${base}/strategy/personas`, {
    market: mk['SK'],
    title: 'The Office Organiser',
    name: 'Eva',
  })
  const linked = await api(page, 'POST', `${base}/campaign`, { name: 'Scripts campaign' })
  const campaignUlid = linked.data.relationships.campaign.data.id as string
  await seedPendingApplications(request, agencyUlid, [{ displayName: 'Lucia Horvathova' }], {
    campaignUlid,
  })
  const candidates = await api(
    page,
    'GET',
    `${base}/strategy/creators/candidates?market=${mk['SK']}&per_page=50`,
  )
  const creatorId = candidates.data[0].id as string
  const added = await api(page, 'POST', `${base}/strategy/creators`, {
    market: mk['SK'],
    creators: [creatorId],
    persona: persona.data.id,
  })
  const slot = (added.data.markets ?? []).flatMap((m: Json) => m.slots)[0].id as string
  const script = await api(page, 'POST', `${base}/strategy/scripts`, { slot })
  const url = `/brands/${brand.data.id}/waves/${wave.data.id}/strategy/scripts?market=${mk['SK']}`

  const closeDrawer = async (): Promise<void> => {
    if (size.width >= 768) return
    const drawer = page.locator('[data-test="agency-sidebar"]')
    await expect(async () => {
      if ((await drawer.getAttribute('inert')) === null)
        await page.locator('.v-app-bar-nav-icon').click()
      await expect(drawer).toHaveAttribute('inert', '', { timeout: 1000 })
    }).toPass({ timeout: 8000 })
  }
  const open = async (target: Page = page): Promise<void> => {
    await target.goto(url)
    if (target === page) await closeDrawer()
    await expect(target.locator('[data-test="script-editor"]')).toBeVisible()
  }
  const hookIds = script.data.hooks.map((h: Json) => h.id as string)
  return { base, open, url, hookIds, scriptId: script.data.id as string, page }
}

const audio = (page: Page, id: string) =>
  page.locator(`[data-test="script-hook-audio-${id}"] textarea:not([aria-hidden])`)

test.describe('Scripts step', () => {
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

  test('type and wait: Saving, then Saved, and the text is still there after a reload; a hostile string stays text', async ({
    page,
    request,
  }) => {
    const alerts: string[] = []
    page.on('dialog', async (d) => {
      alerts.push(d.message())
      await d.dismiss()
    })
    const s = await setup(page, request, DESKTOP)
    await s.open()
    await expect(page.locator('[data-test="script-status-pill"]')).toHaveText('Draft')
    const first = s.hookIds[0]
    await audio(page, first).fill(HOSTILE)
    await expect(page.locator('[data-test="script-save-chip"]')).toHaveAttribute(
      'data-state',
      'saving',
    )
    await expect(page.locator(`[data-test="script-hook-status-${first}"]`)).toContainText('Saved', {
      timeout: 8000,
    })
    await expect(page.locator('[data-test="script-save-chip"]')).toHaveAttribute(
      'data-state',
      'saved',
    )
    await page.reload()
    await expect(audio(page, first)).toHaveValue(HOSTILE)
    await expect(page.locator('img[src="x"]')).toHaveCount(0)
    expect(
      await page.evaluate(() => (window as unknown as { __pwn?: number }).__pwn),
    ).toBeUndefined()
    expect(alerts).toEqual([])
  })

  test('type and leave at once: the leave guard saves first and the text is there on return', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, DESKTOP)
    await s.open()
    const first = s.hookIds[0]
    await audio(page, first).fill('written just before leaving')
    await page.locator('[data-test="strategy-step-creators"]').click()
    await expect(page.locator('[data-test="creators-step"]')).toBeVisible()
    await s.open()
    await expect(audio(page, first)).toHaveValue('written just before leaving')
  })

  test('the API stopped: Could not save, the text stays, the chip is stuck; Retry after it is back saves', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, DESKTOP)
    await s.open()
    const first = s.hookIds[0]
    await page.route('**/strategy/scripts/**', (route) => route.abort())
    await audio(page, first).fill('typed while the API is down')
    await expect(page.locator(`[data-test="script-hook-status-${first}"]`)).toContainText(
      'Could not save',
      { timeout: 8000 },
    )
    await expect(page.locator('[data-test="script-save-chip"]')).toHaveAttribute(
      'data-state',
      'stuck',
    )
    await expect(audio(page, first)).toHaveValue('typed while the API is down')
    await page.unroute('**/strategy/scripts/**')
    await page.locator(`[data-test="script-hook-status-${first}-retry"]`).click()
    await expect(page.locator(`[data-test="script-hook-status-${first}"]`)).toContainText('Saved')
    await page.reload()
    await expect(audio(page, first)).toHaveValue('typed while the API is down')
  })

  test('two tabs on the same hook: the second save gets the 409 with their version shown and my text kept; Keep mine wins', async ({
    page,
    request,
    context,
  }) => {
    const s = await setup(page, request, DESKTOP)
    await s.open()
    const other = await context.newPage()
    await other.setViewportSize(DESKTOP)
    await s.open(other)
    const first = s.hookIds[0]

    await audio(page, first).fill('tab one wrote this')
    await expect(page.locator(`[data-test="script-hook-status-${first}"]`)).toContainText('Saved', {
      timeout: 8000,
    })
    await audio(other, first).fill('tab two wrote this')
    const conflict = other.locator(`[data-test="script-hook-conflict-${first}"]`)
    await expect(conflict).toBeVisible({ timeout: 8000 })
    await expect(conflict).toContainText('tab one wrote this')
    await expect(audio(other, first)).toHaveValue('tab two wrote this')
    await expect(other.locator('[data-test="script-save-chip"]')).toHaveAttribute(
      'data-state',
      'stuck',
    )

    await other.locator(`[data-test="script-hook-conflict-${first}-keep"]`).click()
    await expect(conflict).toBeHidden()
    await expect(other.locator(`[data-test="script-hook-status-${first}"]`)).toContainText('Saved')
    await page.reload()
    await expect(audio(page, first)).toHaveValue('tab two wrote this')
  })

  test('two tabs on different hooks: both are kept', async ({ page, request, context }) => {
    const s = await setup(page, request, DESKTOP)
    await s.open()
    const other = await context.newPage()
    await other.setViewportSize(DESKTOP)
    await s.open(other)
    const [a, b] = s.hookIds

    await audio(page, a).fill('hook A from tab one')
    await audio(other, b).fill('hook B from tab two')
    await expect(page.locator(`[data-test="script-hook-status-${a}"]`)).toContainText('Saved', {
      timeout: 8000,
    })
    await expect(other.locator(`[data-test="script-hook-status-${b}"]`)).toContainText('Saved', {
      timeout: 8000,
    })
    await expect(other.locator('[data-test^="script-hook-conflict-"]')).toHaveCount(0)
    await page.reload()
    await expect(audio(page, a)).toHaveValue('hook A from tab one')
    await expect(audio(page, b)).toHaveValue('hook B from tab two')
  })

  test('delete script asks and counts the hooks; Cancel keeps it; Confirm removes it and the page shows the empty state', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, DESKTOP)
    await s.open()
    for (const delay of [0, 60]) {
      await page.locator('[data-test="script-delete"]').click()
      await expect(page.locator('[data-test="script-delete-dialog"]')).toBeVisible()
      await page.locator('[data-test="script-delete-dialog"] [data-test="confirm-cancel"]').click()
      await page.waitForTimeout(delay)
    }
    await expect(page.locator('[data-test="script-editor"]')).toBeVisible()
    await page.locator('[data-test="script-delete"]').click()
    await expect(page.locator('[data-test="script-delete-dialog"]')).toContainText('3')
    await page.locator('[data-test="script-delete-dialog"] [data-test="confirm-ok"]').click()
    await expect(page.locator('[data-test="scripts-none"]')).toBeVisible()
  })

  test('at a phone width: the rail is a row above the editor, Notes opens a sheet, no sideways scroll', async ({
    page,
    request,
  }) => {
    const s = await setup(page, request, PHONE)
    await s.open()
    await expect(page.locator('[data-test="scripts-rail"]')).toBeVisible()
    await expect(page.locator('[data-test="scripts-notes-open"]')).toBeVisible()
    await page.locator('[data-test="scripts-notes-open"]').click()
    await expect(page.locator('[data-test="notes-sheet"]')).toHaveClass(
      /v-navigation-drawer--active/,
    )
    await page.keyboard.press('Escape')
    await expect(page.locator('[data-test="scripts-notes-open"]')).toBeFocused()
    const sideways = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(sideways).toBeLessThanOrEqual(1)
  })
})
