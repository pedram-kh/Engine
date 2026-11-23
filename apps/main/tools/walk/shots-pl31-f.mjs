#!/usr/bin/env node
/**
 * PL-3.1 F1 to F3 — the picker with the current status pills, the "n creators in market" line, the ceiling from 25, the
 * maximum at 30, and the "n selected" / "Add n creators" wording, in a real browser at 1440 and 390 wide.
 *   node tools/walk/shots-pl31-f.mjs --brand <ulid> --wave1 <ulid> --cap <ulid> [--out /tmp/pl31/shots-f]
 * Needs the walk stack (pl31_walk) with the cap wave seeded. Dev tool only.
 */
import { mkdirSync } from 'node:fs'
import path from 'node:path'

import { chromium } from '@playwright/test'

const arg = (n, f) => {
  const i = process.argv.indexOf(`--${n}`)
  return i === -1 ? f : process.argv[i + 1]
}
const OUT = arg('out', '/tmp/pl31/shots-f')
const BASE = arg('base', 'http://127.0.0.1:5173')
const brand = arg('brand')
const wave1 = arg('wave1')
const cap = arg('cap')
mkdirSync(OUT, { recursive: true })
const browser = await chromium.launch()

for (const [name, width, height] of [
  ['1440', 1440, 1100],
  ['390', 390, 844],
]) {
  const ctx = await browser.newContext({ viewport: { width, height } })
  const page = await ctx.newPage()
  await page.goto(`${BASE}/sign-in`)
  await page.locator('[data-test="sign-in-email"] input').fill('admin@pl31-walk.local')
  await page.locator('[data-test="sign-in-password"] input').fill('password-12chars')
  await page.locator('[data-test="sign-in-submit"]').click()
  await page.locator('[data-test="agency-layout"]').waitFor()
  const collapse = async () => {
    if (width < 768) {
      await page.locator('.v-app-bar-nav-icon').click()
      await page.waitForTimeout(400)
    }
  }
  const shot = (n, o = {}) => page.screenshot({ path: path.join(OUT, `${n}-${name}.png`), ...o })
  const text = async (sel) => ((await page.locator(sel).first().textContent()) ?? '').trim()

  // Wave 1, Slovakia: status pills.
  await page.goto(`${BASE}/brands/${brand}/waves/${wave1}/strategy/creators`)
  await page.locator('[data-test="creators-grid"]').waitFor()
  await collapse()
  await page.locator('[data-test="creators-add"]').click()
  await page.locator('[data-test^="picker-card-"]').first().waitFor()
  await page.waitForTimeout(500)
  await shot('f1-picker')
  const cards = page.locator('[data-test^="picker-card-"][data-state="free"]')
  await cards.nth(0).click()
  await page.waitForTimeout(250)
  await shot('f3-selected-1')
  await cards.nth(0).click()
  await page.waitForTimeout(250)
  await shot('f3-selected-2')
  console.log(name, 'wave1 cap line:', await text('[data-test="creator-picker-cap"]'))
  console.log(
    name,
    'selected:',
    await text('[data-test="creator-picker-selected"]'),
    '|',
    await text('[data-test="creator-picker-submit"]'),
  )
  await page.locator('[data-test="creator-picker-cancel"]').click()

  // Cap wave, Slovakia (25): the ceiling shows.
  await page.goto(`${BASE}/brands/${brand}/waves/${cap}/strategy/creators`)
  await page.locator('[data-test="creators-grid"]').waitFor()
  await collapse()
  await page.waitForTimeout(500)
  await shot('f2-cap25-page', { fullPage: true })
  await page.locator('[data-test="creators-add"]').click()
  await page.locator('[data-test^="picker-card-"]').first().waitFor()
  await page.waitForTimeout(500)
  await shot('f2-cap25-picker')
  console.log(name, 'cap 25 line:', await text('[data-test="creator-picker-cap"]'))
  await page.locator('[data-test="creator-picker-cancel"]').click()

  // Cap wave, Nordics (30): the maximum, Add disabled.
  await page.getByRole('tab', { name: /Nordics/ }).click()
  await page.waitForTimeout(800)
  await shot('f2-cap30-page', { fullPage: true })
  console.log(
    name,
    'cap 30 add disabled:',
    await page.locator('[data-test="creators-add"]').isDisabled(),
    '| notice:',
    await text('[data-test="creators-at-cap"]'),
  )
  await ctx.close()
}
await browser.close()
