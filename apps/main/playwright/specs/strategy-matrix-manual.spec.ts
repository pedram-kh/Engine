import type { Page } from '@playwright/test'
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
 * PL-2.2 (ruling change of 2026-11-20, R1 and R2) in a real browser, at 1440 and 390, each with its own setup:
 * a cell opened on a pair with a manually added angle is prefilled (and the "why Save is off" hint is red while a
 * Priority cell has no line); "Use the matrix line" shows both texts, Cancel leaves everything as it was, Confirm turns
 * the angle into a synced one and the persona card shows it as synced.
 */

test.describe.configure({ mode: 'serial' })

const WIDTHS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '390', width: 390, height: 844 },
] as const

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

async function closeDrawer(page: Page, width: number): Promise<void> {
  if (width >= 768) return
  const drawer = page.locator('.v-navigation-drawer')
  await expect(async () => {
    if ((await drawer.getAttribute('inert')) === null) {
      await page.locator('.v-app-bar-nav-icon').click()
    }
    await expect(drawer).toHaveAttribute('inert', '', { timeout: 1000 })
  }).toPass({ timeout: 8000 })
  await page.waitForTimeout(400)
}

test.describe('Messaging matrix: manual angle and cell', () => {
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
    test(`at ${size.name}: prefill, red hint, and Use the matrix line (cancel, then confirm)`, async ({
      page,
      request,
    }) => {
      const { email, password, agencyUlid } = await seedAgencyAdmin(request)
      await page.setViewportSize({ width: size.width, height: size.height })
      await signIn(page, email, password)

      const brand = await api(page, 'POST', `/agencies/${agencyUlid}/brands`, {
        name: `Manual Bolt ${size.name}`,
        slug: `manual-bolt-${size.name}`,
        description: 'A brand for the manual-angle check.',
        industry: 'Food',
        website_url: 'https://example.com',
      })
      const wave = await api(
        page,
        'POST',
        `/agencies/${agencyUlid}/brands/${brand.data.id}/waves`,
        {
          name: 'Manual wave',
          go_live_on: '2027-03-01',
          markets: wireMarkets(['SK']),
          campaign_id: null,
        },
      )
      const base = `/agencies/${agencyUlid}/brands/${brand.data.id}/waves/${wave.data.id}/strategy`
      const mk = await marketIds(
        (path) => api(page, 'GET', path),
        `/agencies/${agencyUlid}/brands/${brand.data.id}/waves/${wave.data.id}`,
      )
      const usps: string[] = []
      for (const title of ['Fast', 'Cheap', 'Wide range']) {
        usps.push((await api(page, 'POST', `${base}/usps`, { title })).data.id)
      }
      const personas: string[] = []
      for (const [title, name] of [
        ['First persona', 'Zuzana'],
        ['Second persona', 'Peter'],
      ]) {
        personas.push(
          (
            await api(page, 'POST', `${base}/personas`, {
              market: mk['SK'],
              title,
              name,
              pronoun: 'she',
            })
          ).data.id,
        )
      }
      // Pair 1 (Fast): a manual angle and no cell. Pair 2 (Cheap): a manual angle and a Priority cell that says something else.
      await api(page, 'POST', `${base}/personas/${personas[0]}/angles`, {
        usp: usps[0],
        explanation: 'Written by hand',
      })
      await api(page, 'POST', `${base}/personas/${personas[0]}/angles`, {
        usp: usps[1],
        explanation: 'Also by hand',
      })
      await api(page, 'PUT', `${base}/matrix/cells`, {
        market: mk['SK'],
        cells: [
          { persona: personas[0], usp: usps[1], level: 'priority', message_line: 'From the cell' },
        ],
      })

      await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}/strategy/matrix`)
      await closeDrawer(page, size.width)
      const cells = page.locator('[data-test="matrix-cell"]')
      await expect(cells).toHaveCount(6)
      const open = async (index: number): Promise<void> => {
        await cells
          .nth(index)
          .evaluate((el) => el.scrollIntoView({ block: 'center', inline: 'center' }))
        await cells.nth(index).click()
        await expect(page.locator('[data-test="matrix-editor"]')).toBeVisible()
      }

      // R1: the empty line of pair 1 is prefilled, editable, and says where it came from.
      await open(0)
      const line = page.locator('[data-test="matrix-line-input"]')
      await expect(line).toHaveValue('Written by hand')
      await expect(page.locator('[data-test="matrix-line-prefilled"]')).toBeVisible()

      // The hint: Priority with the line cleared is red (the theme's error colour) on the text, the icon and the field.
      await page.locator('[data-test="matrix-level-priority"]').click()
      await line.fill('')
      const hint = page.locator('[data-test="matrix-line-hint"]')
      await expect(hint).toBeVisible()
      await expect(page.locator('[data-test="matrix-save"]')).toBeDisabled()
      const colours = await page.evaluate(() => {
        const probe = document.createElement('span')
        probe.style.color = 'rgb(var(--v-theme-error))'
        // inside the editor, so the probe sees the same theme the hint does
        document.querySelector('[data-test="matrix-editor"]')?.append(probe)
        const error = getComputedStyle(probe).color
        probe.remove()
        const hintEl = document.querySelector('[data-test="matrix-line-hint"]')
        const field = document.querySelector('[data-test="matrix-line-input"]')
        return {
          error,
          text: hintEl ? getComputedStyle(hintEl).color : null,
          icon: hintEl?.querySelector('.v-icon')
            ? getComputedStyle(hintEl.querySelector('.v-icon')!).color
            : null,
          border: field ? getComputedStyle(field).borderTopColor : null,
          invalid: field?.getAttribute('aria-invalid'),
        }
      })
      expect(colours.error).not.toBe('')
      expect(colours.text).toBe(colours.error)
      expect(colours.icon).toBe(colours.error)
      expect(colours.border).toBe(colours.error)
      expect(colours.invalid).toBe('true')
      // Cancel: nothing is saved, the angle is as it was.
      await page.locator('[data-test="matrix-cancel"]').click()
      await expect(page.locator('[data-test="matrix-editor"]')).toHaveCount(0)
      await expect(cells.nth(0)).toContainText(/not set/i)

      // R2: pair 2 shows the conflict and the action; the dialog shows both texts; Cancel changes nothing.
      const note = page.locator('[data-test="matrix-conflict-note"]')
      await expect(note).toHaveCount(1)
      const action = page.locator('[data-test="matrix-use-line"]')
      await action.evaluate((el) => el.scrollIntoView({ block: 'center', inline: 'center' }))
      await action.click()
      await expect(page.locator('[data-test="use-line-manual"]')).toHaveText('Also by hand')
      await expect(page.locator('[data-test="use-line-matrix"]')).toHaveText('From the cell')
      await page.locator('[data-test="confirm-cancel"]').click()
      await expect(page.locator('[data-test="use-line-texts"]')).toHaveCount(0)
      await expect(note).toHaveCount(1)
      const before = (await api(page, 'GET', `${base}/personas?market=${mk['SK']}`)) as unknown as {
        data: {
          attributes: { angles: { attributes: { origin: string; explanation: string } }[] }
        }[]
      }
      const angles = before.data[0]!.attributes.angles.map((a) => a.attributes)
      expect(angles.map((a) => `${a.origin}:${a.explanation}`).sort()).toEqual([
        'manual:Also by hand',
        'manual:Written by hand',
      ])

      // Confirm: the angle becomes the synced one with the cell's text; the note goes away.
      await action.evaluate((el) => el.scrollIntoView({ block: 'center', inline: 'center' }))
      await action.click()
      await page.locator('[data-test="confirm-ok"]').click()
      await expect(page.locator('[data-test="use-line-texts"]')).toHaveCount(0)
      await expect(note).toHaveCount(0)

      // The persona card shows it as synced, with the cell's words; the other angle is still manual.
      await page.goto(`/brands/${brand.data.id}/waves/${wave.data.id}/strategy/personas`)
      await closeDrawer(page, size.width)
      const card = page.locator('[data-test="persona-card"]').first()
      await expect(card).toBeVisible()
      await expect(card.locator('[data-test="angle-origin"]')).toHaveText([
        'Manually added',
        'Synced from matrix',
      ])
      await expect(card.locator('[data-test="angle-explanation"]')).toHaveText([
        'Written by hand',
        'From the cell',
      ])
      await expect(card.locator('[data-test="angle-differs"]')).toHaveCount(0)
    })
  }
})
