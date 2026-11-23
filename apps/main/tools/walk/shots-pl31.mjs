#!/usr/bin/env node
/**
 * PL-3.1 — screenshots of the creators step and the picker in a real browser against the walk stack (pl31_walk).
 *   node tools/walk/shots-pl31.mjs --out /tmp/pl31/shots [--base http://127.0.0.1:5173] [--wave <ulid>] [--brand <ulid>]
 * Signs in as the walk admin, opens Creators Wave 1, and captures the step, the picker, the decision dialog and the history
 * at 1440 and 390 wide. Dev tool only.
 */
import { mkdirSync } from 'node:fs'
import path from 'node:path'

import { chromium } from '@playwright/test'

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const OUT = arg('out', '/tmp/pl31/shots')
const BASE = arg('base', 'http://127.0.0.1:5173')
const EMAIL = arg('email', 'admin@pl31-walk.local')
const PASSWORD = 'password-12chars'
mkdirSync(OUT, { recursive: true })

const browser = await chromium.launch()
async function session(width, height) {
  const ctx = await browser.newContext({ viewport: { width, height } })
  const page = await ctx.newPage()
  await page.goto(`${BASE}/sign-in`)
  await page.locator('[data-test="sign-in-email"] input').fill(EMAIL)
  await page.locator('[data-test="sign-in-password"] input').fill(PASSWORD)
  await page.locator('[data-test="sign-in-submit"]').click()
  await page.locator('[data-test="agency-layout"]').waitFor()
  return { ctx, page }
}

const { page: probe, ctx: probeCtx } = await session(1440, 900)
const ids = await probe.evaluate(async () => {
  const get = async (p) =>
    (
      await fetch(`/api/v1${p}`, {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      })
    ).json()
  const me = await get('/me')
  const agency = me.data.relationships.agency_memberships.data[0].agency_id
  const brands = await get(`/agencies/${agency}/brands`)
  const brand = brands.data.find((b) => b.attributes.name === 'Bolt Food')
  const waves = await get(`/agencies/${agency}/brands/${brand.id}/waves`)
  const wave = waves.data.find((w) => w.attributes.name === 'Creators Wave 1')
  return { agency, brand: brand.id, wave: wave.id, markets: wave.attributes.markets }
})
await probeCtx.close()
ids.wave2 = '01M82XN9P6AP2CKH0P7EVMFWJQ'
console.log(JSON.stringify(ids))

for (const [name, width, height] of [
  ['1440', 1440, 1100],
  ['390', 390, 844],
]) {
  const { page, ctx } = await session(width, height)
  const base = `${BASE}/brands/${ids.brand}/waves`
  const url = `${base}/${ids.wave}/strategy/creators?market=${ids.markets[0].id}`
  await page.goto(url)
  await page.locator('[data-test="creators-grid"]').waitFor()
  if (width < 768) {
    await page.locator('.v-app-bar-nav-icon').click()
    await page.waitForTimeout(500)
  }
  await page.waitForTimeout(600)
  await page.screenshot({ path: path.join(OUT, `step-${name}.png`), fullPage: true })

  // Picker: open, select three, close, quickly reopen.
  await page.locator('[data-test="creators-add"]').click()
  await page.locator('[data-test^="picker-card-"]').first().waitFor()
  await page.waitForTimeout(500)
  await page.screenshot({ path: path.join(OUT, `picker-${name}.png`) })
  const cards = page.locator('[data-test^="picker-card-"]')
  for (const i of [0, 1, 3]) await cards.nth(i).click()
  await page.waitForTimeout(300)
  await page.screenshot({ path: path.join(OUT, `picker-selected-${name}.png`) })
  await page.locator('[data-test="creator-picker-cancel"]').click()
  await page.locator('[data-test="creators-add"]').click()
  await page.locator('[data-test="creator-picker-cancel"]').click()
  await page.locator('[data-test="creators-add"]').click()
  await page.locator('[data-test^="picker-card-"]').first().waitFor()
  await page.locator('[data-test="creator-picker-cancel"]').click()

  // Replace (single-select picker).
  await page.locator('[data-test^="creator-replace-"]').first().click()
  await page.locator('[data-test^="picker-card-"]').first().waitFor()
  await page.waitForTimeout(400)
  await page.screenshot({ path: path.join(OUT, `replace-${name}.png`) })
  await page.locator('[data-test="creator-picker-cancel"]').click()

  // Remove confirmation.
  await page.locator('[data-test^="creator-remove-"]').first().click()
  await page.waitForTimeout(400)
  await page.screenshot({ path: path.join(OUT, `remove-${name}.png`) })
  await page.getByRole('button', { name: 'Cancel' }).last().click()
  await page.waitForTimeout(300)

  // Decision dialog and history.
  await page.locator('[data-test^="creator-decide-"]').first().click()
  await page.waitForTimeout(400)
  await page.screenshot({ path: path.join(OUT, `decision-${name}.png`) })
  await page.keyboard.press('Escape')
  await page.waitForTimeout(300)

  // Nordics market (empty) and the wave with no campaign.
  await page.goto(`${base}/${ids.wave}/strategy/creators?market=${ids.markets[1].id}`)
  await page.waitForTimeout(1200)
  if (width < 768) await page.locator('.v-app-bar-nav-icon').click()
  await page.waitForTimeout(400)
  await page.screenshot({ path: path.join(OUT, `nordics-${name}.png`), fullPage: true })
  await page.goto(`${base}/${ids.wave2}/strategy/creators`)
  await page.waitForTimeout(1500)
  if (width < 768) await page.locator('.v-app-bar-nav-icon').click()
  await page.waitForTimeout(400)
  await page.screenshot({ path: path.join(OUT, `nocampaign-${name}.png`), fullPage: true })
  await ctx.close()
}
await browser.close()
