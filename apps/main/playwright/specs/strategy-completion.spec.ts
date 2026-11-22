import type { APIRequestContext, Page } from '@playwright/test'
import { expect, test } from '../fixtures/test'
import {
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  setFeatureFlag,
} from '../fixtures/test-helpers'
import { dt, testIds } from '../helpers/selectors'
import { marketIds, wireMarkets } from '../helpers/markets'

/**
 * PL-2.5 — connecting it up, in a real browser: the stepper's real states, Mark as complete / Reopen with the
 * warning confirm and the empty-step refusal, the landing, the notes panel and the matrix side sheet (a hostile string
 * typed into a note stays text), and the tracker asking before a row is set beyond the real step.
 * Each test makes its own agency, brand and wave; serial; desktop width.
 */

test.describe.configure({ mode: 'serial' })

const SIZE = { name: '1440', width: 1440, height: 900 }
const HOSTILE = '<img src=x onerror=alert(1)><script>window.__pwn=1</script>'

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

/** Sign in as a fresh agency admin and make a brand and a wave through the API; the drawer helper closes the menu on a phone. */
async function startWave(
  page: Page,
  request: APIRequestContext,
  size: { name: string; width: number; height: number },
  tag: string,
  markets: string[],
) {
  const { email, password, agencyUlid } = await seedAgencyAdmin(request)
  await page.setViewportSize({ width: size.width, height: size.height })
  await page.goto('/sign-in')
  await page.locator(dt(testIds.signInEmail)).locator('input').fill(email)
  await page.locator(dt(testIds.signInPassword)).locator('input').fill(password)
  await page.locator(dt(testIds.signInSubmit)).click()
  await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()

  const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
    name: `${tag} Bolt ${size.name}`,
    slug: `${tag.toLowerCase()}-bolt-${size.name}`,
    description: 'A brand for the strategy check.',
    industry: 'Food',
    website_url: 'https://example.com',
  })
  const wave = await api(page, 'POST', `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`, {
    name: `${tag} wave`,
    go_live_on: '2027-03-01',
    markets: wireMarkets(markets),
    campaign_id: null,
  })
  const base = `/agencies/${agencyUlid}/brands/${brand.data.id}/waves/${wave.data.id}/strategy`
  const closeDrawer = async (): Promise<void> => {
    if (size.width >= 768) return
    const drawer = page.locator('[data-test="agency-sidebar"]')
    // The drawer settles after a load or reload; closing it while it still moves can leave it open and the page 70 px wide.
    await expect(async () => {
      if ((await drawer.getAttribute('inert')) === null)
        await page.locator('.v-app-bar-nav-icon').click()
      await expect(drawer).toHaveAttribute('inert', '', { timeout: 1000 })
    }).toPass({ timeout: 8000 })
    await page.waitForTimeout(400)
  }
  const mk = await marketIds(
    (path) => api(page, 'GET', path),
    `/agencies/${agencyUlid}/brands/${brand.data.id}/waves/${wave.data.id}`,
  )
  return { brandId: brand.data.id, waveId: wave.data.id, base, closeDrawer, mk }
}

async function sideways(page: Page): Promise<number> {
  return page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )
}

test.describe('Strategy completion, notes and landing', () => {
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

  test('USPs: mark with a warning, the stepper turns done, the landing moves on, reopen puts it back', async ({
    page,
    request,
  }) => {
    const alerts: string[] = []
    page.on('dialog', async (d) => {
      alerts.push(d.message())
      await d.dismiss()
    })
    const { brandId, waveId, base } = await startWave(page, request, SIZE, 'Done', ['SK', 'RO'])
    await api(page, 'POST', `${base}/usps`, { title: 'Fast' })
    await api(page, 'POST', `${base}/usps`, { title: 'Cheap' })

    await page.goto(`/brands/${brandId}/waves/${waveId}/strategy/usps`)
    const step = page.locator('[data-test="strategy-step-usps"]')
    await expect(step).toHaveAttribute('data-state', 'in_progress')
    await expect(page.locator('[data-test="completion-state"]')).toHaveText('In progress')

    // The warning confirm opens above the page, survives a quick close and reopen, and does not mark until confirmed.
    for (const delay of [0, 60, 150]) {
      await page.locator('[data-test="completion-mark"]').click()
      await expect(page.locator('[data-test="completion-dialog"]')).toBeVisible()
      await page.locator('[data-test="confirm-cancel"]').click()
      await page.waitForTimeout(delay)
    }
    await expect(step).toHaveAttribute('data-state', 'in_progress')
    await page.locator('[data-test="completion-mark"]').click()
    await expect(page.locator('[data-test="completion-warnings"]')).toContainText('2 USPs so far')
    await page.locator('[data-test="confirm-ok"]').click()
    await expect(step).toHaveAttribute('data-state', 'done')
    await expect(page.locator('[data-test="strategy-step-usps-status"]')).toHaveText('Complete')
    await expect(page.locator('[data-test="completion-reopen"]')).toBeVisible()
    expect(await sideways(page)).toBeLessThanOrEqual(1)

    // "Strategy & Scripts" from the wave page now opens the first step not done.
    await page.goto(`/brands/${brandId}/waves/${waveId}`)
    await page.locator('[data-test="wave-strategy-btn"]').click()
    await expect(page).toHaveURL(/\/strategy\/personas/)
    // A direct address still opens that step.
    await page.goto(`/brands/${brandId}/waves/${waveId}/strategy/usps`)
    await expect(page.locator('[data-test="strategy-page"]')).toBeVisible()

    await page.locator('[data-test="completion-reopen"]').click()
    await expect(step).toHaveAttribute('data-state', 'in_progress')
    expect(alerts).toEqual([])
  })

  test('Personas: an empty market is refused with no button to force it; the notes panel keeps a hostile note as text', async ({
    page,
    request,
  }) => {
    const alerts: string[] = []
    page.on('dialog', async (d) => {
      alerts.push(d.message())
      await d.dismiss()
    })
    const { brandId, waveId } = await startWave(page, request, SIZE, 'Empty', ['SK'])
    await page.goto(`/brands/${brandId}/waves/${waveId}/strategy/personas`)

    for (const delay of [0, 60, 150]) {
      await page.locator('[data-test="completion-mark"]').click()
      await expect(page.locator('[data-test="completion-dialog"]')).toContainText(
        'Add at least one persona for Slovakia',
      )
      await expect(page.locator('[data-test="confirm-ok"]')).toHaveCount(0)
      await page.locator('[data-test="confirm-cancel"]').click()
      await page.waitForTimeout(delay)
    }
    await expect(page.locator('[data-test="strategy-step-personas"]')).toHaveAttribute(
      'data-state',
      'not_started',
    )

    const panel = page.locator('[data-test="notes-panel"]')
    await expect(panel).toBeVisible()
    expect((await panel.boundingBox())?.width).toBe(300)
    await page.locator('[data-test="note-input"] textarea:not([aria-hidden])').fill(HOSTILE)
    await page.locator('[data-test="note-add"]').click()
    await expect(page.locator('[data-test="note-body"]')).toHaveText(HOSTILE)
    await expect(page.locator('img[src="x"]')).toHaveCount(0)
    expect(
      await page.evaluate(() => (window as unknown as { __pwn?: number }).__pwn),
    ).toBeUndefined()
    // The delete confirm opens above the page and survives a quick close and reopen; then delete for real.
    for (const delay of [0, 60, 150]) {
      await page.locator('[data-test="note-delete"]').click()
      await expect(page.locator('[data-test="note-delete-dialog"]')).toBeVisible()
      await page.locator('[data-test="confirm-cancel"]').click()
      await page.waitForTimeout(delay)
    }
    await page.locator('[data-test="note-delete"]').click()
    await page.locator('[data-test="confirm-ok"]').click()
    await expect(page.locator('[data-test="note-body"]')).toHaveCount(0)
    expect(alerts).toEqual([])
  })

  test('Matrix: the side sheet opens from "Notes (n)", survives a quick close and reopen, and holds a note per market', async ({
    page,
    request,
  }) => {
    const { brandId, waveId } = await startWave(page, request, SIZE, 'Sheet', ['SK', 'RO'])
    await page.goto(`/brands/${brandId}/waves/${waveId}/strategy/matrix`)
    const open = page.locator('[data-test="notes-open"]')
    await expect(open).toContainText('Notes (0)')
    for (const delay of [0, 60, 150]) {
      await open.click()
      await expect(page.locator('[data-test="notes-sheet-title"]')).toBeVisible()
      await page.keyboard.press('Escape')
      await page.waitForTimeout(delay)
    }
    await open.click()
    await page.locator('[data-test="note-input"] textarea:not([aria-hidden])').fill('Slovak note')
    await page.locator('[data-test="note-add"]').click()
    await expect(page.locator('[data-test="note-body"]')).toHaveText('Slovak note')
    await page.keyboard.press('Escape')
    await expect(open).toContainText('Notes (1)')
    // The other market has its own thread.
    await page.locator('[data-test="market-tab-RO"]').click()
    await expect(open).toContainText('Notes (0)')
  })

  test('Tracker: a row set beyond the real step asks first; confirming shows the marker, Cancel changes nothing', async ({
    page,
    request,
  }) => {
    const { brandId, waveId } = await startWave(page, request, SIZE, 'Hand', ['SK'])
    await page.goto(`/brands/${brandId}/waves/${waveId}`)
    const row = page.locator('[data-test^="step-row-"]').filter({ hasText: 'Personas' }).first()
    await row.locator('[data-test^="step-check-"]').click()
    await expect(page.locator('[data-test="incomplete-dialog"]')).toContainText(
      'The strategy step says',
    )
    await page.locator('[data-test="confirm-cancel"]').click()
    await expect(page.locator('[data-test="incomplete-dialog"]')).toHaveCount(0)
    for (const delay of [0, 60, 150]) {
      await row.locator('[data-test^="step-check-"]').click()
      await expect(page.locator('[data-test="incomplete-dialog"]')).toBeVisible()
      await page.locator('[data-test="confirm-cancel"]').click()
      await page.waitForTimeout(delay)
    }
    await expect(page.locator('[data-test^="step-hand-"]')).toHaveCount(0)

    await row.locator('[data-test^="step-check-"]').click()
    await page.locator('[data-test="confirm-ok"]').click()
    await expect(page.locator('[data-test^="step-hand-"]')).toContainText('Set by hand')
  })
})
