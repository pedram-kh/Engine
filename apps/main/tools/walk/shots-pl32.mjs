#!/usr/bin/env node
/**
 * PL-3.2a — screenshots of the Scripts step in a real browser against the walk stack (pl32_walk), at 1600 and 390 wide.
 *   node tools/walk/shots-pl32.mjs --out /tmp/pl32/shots [--base http://127.0.0.1:5173]
 * Dev tool only.
 */
import { mkdirSync } from 'node:fs'
import path from 'node:path'

import { chromium } from '@playwright/test'

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const OUT = arg('out', '/tmp/pl32/shots')
const BASE = arg('base', 'http://127.0.0.1:5173')
const PASSWORD = 'password-12chars'
mkdirSync(OUT, { recursive: true })

const browser = await chromium.launch()
export async function session(width, height, email = 'admin@pl32-walk.local') {
  const ctx = await browser.newContext({ viewport: { width, height } })
  const page = await ctx.newPage()
  await page.goto(`${BASE}/sign-in`)
  await page.locator('[data-test="sign-in-email"] input').fill(email)
  await page.locator('[data-test="sign-in-password"] input').fill(PASSWORD)
  await page.locator('[data-test="sign-in-submit"]').click()
  await page.locator('[data-test="agency-layout"]').waitFor()
  return { ctx, page }
}
async function ids(page) {
  return page.evaluate(async () => {
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
    const wave = waves.data.find((w) => w.attributes.name === 'Scripts Wave 1')
    return { agency, brand: brand.id, wave: wave.id }
  })
}

for (const [name, width, height] of [
  ['1600', 1600, 1000],
  ['390', 390, 844],
]) {
  const { page, ctx } = await session(width, height)
  const i = await ids(page)
  await page.goto(`${BASE}/brands/${i.brand}/waves/${i.wave}/strategy/scripts`)
  await page.locator('[data-test="scripts-step"]').waitFor()
  // The agency sidebar starts open at every width (AgencyLayout `drawer = ref(true)`); close it on a phone like a person would.
  await page.waitForTimeout(600)
  if (width < 1100 && (await page.locator('.agency-sidebar.v-navigation-drawer--active').count())) {
    await page.locator('.v-app-bar-nav-icon').click()
    await page.waitForTimeout(500)
  }
  await page.waitForTimeout(800)
  await page.screenshot({ path: path.join(OUT, `scripts-${name}.png`), fullPage: true })
  console.log('saved', `scripts-${name}.png`, JSON.stringify(i))
  await ctx.close()
}
await browser.close()
