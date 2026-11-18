import type { Locator } from '@playwright/test'
import { expect, test } from '../fixtures/test'
import { seedAgencyAdmin, setFeatureFlag } from '../fixtures/test-helpers'
import { SEL } from '../helpers/selectors'

/**
 * PL-1.2 walk defect D1. The New wave dialog is position:fixed. VMenu is position:absolute, so once
 * Stage 2 made the dialog tall enough to cover the country list, the list opened behind the dialog and
 * no market could be picked. This clicks a country inside the open dialog and requires the chip.
 */

test.describe.configure({ mode: 'serial' })

test.describe('New wave dialog menus', () => {
  test.beforeEach(async ({ request }) => {
    await setFeatureFlag(request, 'waves_enabled', true)
  })

  test.afterEach(async ({ request }) => {
    await setFeatureFlag(request, 'waves_enabled', false)
  })

  test('a market picked inside the dialog becomes a chip, and the list stays inside the window', async ({
    page,
    request,
  }) => {
    const { email, password, agencyUlid } = await seedAgencyAdmin(request)

    await page.goto('/sign-in')
    await page.locator(`[data-test="${SEL.signInEmail}"] input`).fill(email)
    await page.locator(`[data-test="${SEL.signInPassword}"] input`).fill(password)
    await page.locator(`[data-test="${SEL.signInSubmit}"]`).click()
    await expect(page.locator(`[data-test="${SEL.agencyLayout}"]`)).toBeVisible()

    const brandId = await page.evaluate(async (agencyId: string) => {
      const token = decodeURIComponent(
        document.cookie
          .split('; ')
          .find((row) => row.startsWith('XSRF-TOKEN='))
          ?.slice('XSRF-TOKEN='.length) ?? '',
      )
      const response = await fetch(`/api/v1/agencies/${agencyId}/brands`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': token,
        },
        body: JSON.stringify({
          name: 'Menu Bolt',
          slug: 'menu-bolt',
          description: 'A brand for the menu check.',
          industry: 'Food',
          website_url: 'https://example.com',
        }),
      })
      if (!response.ok) throw new Error(`brand create failed: ${response.status}`)
      const body = (await response.json()) as { data: { id: string } }
      return body.data.id
    }, agencyUlid)

    await page.setViewportSize({ width: 1100, height: 640 })
    await page.goto(`/brands/${brandId}`)
    await page.locator('[data-test="wave-new-btn"]').click()
    await expect(page.locator('[data-test="wave-dialog"]')).toBeVisible()

    await page.locator('[data-test="market-add"]').click()
    const ireland = page.locator('[data-test="market-option-IE"]')
    await expect(ireland).toBeVisible()
    await expect.poll(async () => topmost(ireland)).toBe(true)

    const menu = page.locator('.market-picker__menu')
    const viewport = page.viewportSize()
    const menuBox = await menu.boundingBox()
    expect(menuBox).not.toBeNull()
    expect(menuBox!.y).toBeGreaterThanOrEqual(0)
    expect(menuBox!.y + menuBox!.height).toBeLessThanOrEqual((viewport?.height ?? 640) + 1)

    await ireland.click()
    await expect(page.locator('[data-test="market-chip-IE"]')).toBeVisible()

    // The date control is the browser's own picker (input type=date), not a Vuetify menu.
    // What can be asserted is that the field itself is the topmost element inside the dialog.
    const date = page.locator('[data-test="wave-go-live"] input')
    await expect(date).toBeVisible()
    await expect.poll(async () => topmost(date)).toBe(true)

    // Create mode, "An existing campaign for a wave": the campaign menu must also be the topmost element.
    await page.locator('[data-test="wave-choice-existing"] input').click()
    await page.locator('[data-test="wave-campaign"]').click()
    const campaignMenu = page.locator('.wave-dialog-menu')
    await expect(campaignMenu).toBeVisible()
    await expect.poll(async () => topmost(campaignMenu)).toBe(true)
    await page.keyboard.press('Escape')

    // Edit mode uses the same dialog and the same campaign select.
    const waveId = await page.evaluate(
      async ({ agencyId, brand }: { agencyId: string; brand: string }) => {
        const token = decodeURIComponent(
          document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length) ?? '',
        )
        const response = await fetch(`/api/v1/agencies/${agencyId}/brands/${brand}/waves`, {
          method: 'POST',
          credentials: 'include',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
          },
          body: JSON.stringify({
            name: 'Menu wave',
            go_live_on: '2026-12-01',
            markets: ['IE'],
            campaign_id: null,
          }),
        })
        if (!response.ok) throw new Error(`wave create failed: ${response.status}`)
        const body = (await response.json()) as { data: { id: string } }
        return body.data.id
      },
      { agencyId: agencyUlid, brand: brandId },
    )

    await page.goto(`/brands/${brandId}/waves/${waveId}`)
    await page.locator('[data-test="wave-edit-btn"]').click()
    await expect(page.locator('[data-test="wave-dialog"]')).toBeVisible()
    await page.locator('[data-test="market-add"]').click()
    const france = page.locator('[data-test="market-option-FR"]')
    await expect(france).toBeVisible()
    await expect.poll(async () => topmost(france)).toBe(true)
    await france.click()
    await expect(page.locator('[data-test="market-chip-FR"]')).toBeVisible()

    await page.locator('[data-test="wave-campaign"]').click()
    const editMenu = page.locator('.wave-dialog-menu')
    await expect(editMenu).toBeVisible()
    await expect.poll(async () => topmost(editMenu)).toBe(true)
  })
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
