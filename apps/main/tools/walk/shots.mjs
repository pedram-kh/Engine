#!/usr/bin/env node
/**
 * AH-114 screenshot set for human review: the agency shell and representative
 * pages, dark and light, 1600 wide. Real page components fed mock API fixtures
 * where the harness supports it, the probe page (a stand-in, labelled) otherwise.
 *
 *   node tools/walk/shots.mjs --out /tmp/ah114-shots [--theme dark|light]
 *
 * Files (per theme): list (REAL campaigns.list + fixtures), form (REAL
 * campaigns.create + fixtures), menu (user menu open on the list), dialog (probe
 * page stand-in), collapsed (list with the drawer collapsed), probe (the probe page).
 * A manifest.json records which is real and which is a stand-in.
 *
 * Dev tool only. Every /api and /sanctum request not covered by a fixture answers 503.
 */

import { mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`)
  return i === -1 ? fallback : process.argv[i + 1]
}
const OUT = path.resolve(arg('out', '/tmp/ah114-shots'))
const ONLY_THEME = arg('theme', null)
const PORT = Number(arg('port', '5197'))
const AGENCY = '01HWALKAGENCY000000000000'

const BRANDS = [
  ['01HBRANDNORTHWIND0000000001', 'Northwind'],
  ['01HBRANDASTER00000000000002', 'Aster Home'],
  ['01HBRANDLUMEN00000000000003', 'Lumen Audio'],
]

function campaign(i, name, brandIdx, status, objective, creators, listed) {
  const [brandId, brandName] = BRANDS[brandIdx]
  return {
    id: `01HCAMPAIGN0000000000000${i}`,
    type: 'campaigns',
    attributes: {
      name,
      description: null,
      objective,
      status,
      budget_minor_units: 420000 + i * 150000,
      budget_currency: 'EUR',
      starts_at: `2026-0${3 + (i % 5)}-0${1 + i}T00:00:00Z`,
      ends_at: `2026-0${4 + (i % 5)}-2${i}T00:00:00Z`,
      posting_window_starts_at: null,
      posting_window_ends_at: null,
      brief: null,
      target_creator_count: 8,
      requires_per_campaign_contract: false,
      creator_posts_content: true,
      is_marketplace_visible: false,
      listed_on_jobs_board: listed,
      listing_duration: null,
      listing_fee: null,
      listing_languages: null,
      listing_regions: null,
      listing_examples_url: null,
      published_at: null,
      completed_at: null,
      assignment_count: creators,
      created_at: '2026-01-10T09:00:00Z',
      updated_at: '2026-02-10T09:00:00Z',
    },
    relationships: {
      brand: { data: { id: brandId, type: 'brands', name: brandName } },
      agency: { data: { id: AGENCY, type: 'agencies' } },
    },
  }
}

const CAMPAIGNS = [
  campaign(1, 'Spring launch', 0, 'active', 'launch', 12, true),
  campaign(2, 'Summer reset', 1, 'draft', 'awareness', 0, false),
  campaign(3, 'Autumn capsule', 2, 'active', 'engagement', 7, true),
  campaign(4, 'Holiday gifting', 0, 'paused', 'conversion', 5, false),
  campaign(5, 'Back to school', 1, 'completed', 'ugc', 18, false),
  campaign(6, 'Studio sessions', 2, 'cancelled', 'awareness', 2, false),
]

function fixtureFor(url, method) {
  const p = url.pathname
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/campaigns`) {
    return {
      data: CAMPAIGNS,
      meta: { total: CAMPAIGNS.length, page: 1, per_page: 25, last_page: 1 },
    }
  }
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/brands`) {
    return {
      data: BRANDS.map(([id, name]) => ({ id, type: 'brands', attributes: { name } })),
    }
  }
  return null
}

async function newPage(browser, theme, base, real) {
  const context = await browser.newContext({
    viewport: { width: 1600, height: 900 },
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    colorScheme: 'dark',
    locale: 'en-US',
    timezoneId: 'Europe/Madrid',
  })
  await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), theme)
  await context.route(
    (url) => /^\/(api|sanctum)\//.test(url.pathname),
    (route) => {
      const body = fixtureFor(new URL(route.request().url()), route.request().method())
      return body
        ? route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(body),
          })
        : route.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
    },
  )
  const page = await context.newPage()
  const qs = new URLSearchParams({ to: real.to, user: 'agency', theme, real: real.names })
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(900)
  return { context, page }
}

const server = await createServer({
  root: APP_ROOT,
  logLevel: 'error',
  server: { host: '127.0.0.1', port: PORT, strictPort: true, hmr: false },
})
await server.listen()
const base = `http://127.0.0.1:${PORT}`
const browser = await chromium.launch()
mkdirSync(OUT, { recursive: true })
const manifest = []
const REAL = { to: '/campaigns', names: 'campaigns.list,campaigns.create' }

async function shot(page, theme, name, kind, note) {
  const file = `${theme}-${name}.png`
  await page.screenshot({ path: path.join(OUT, file), animations: 'disabled', caret: 'hide' })
  manifest.push({ file, theme, name, kind, note })
}

try {
  // Warm the dev dependency optimiser.
  {
    const w = await newPage(browser, 'dark', base, REAL)
    await w.context.close()
    await new Promise((r) => setTimeout(r, 3000))
  }
  for (const theme of ONLY_THEME ? [ONLY_THEME] : ['dark', 'light']) {
    // list (real) + menu + collapsed
    let w = await newPage(browser, theme, base, REAL)
    await shot(
      w.page,
      theme,
      'list',
      'real page, mock API',
      'campaigns.list with six fixture campaigns',
    )
    await w.page.click('[data-test="user-menu-btn"]')
    await w.page.waitForSelector('.v-overlay-container .v-overlay--active', { timeout: 5000 })
    await w.page.waitForTimeout(400)
    await shot(w.page, theme, 'menu', 'real shell, real menu', 'user menu open on the list')
    await w.page.keyboard.press('Escape')
    await w.page.waitForTimeout(400)
    await w.page.click('.v-app-bar .v-app-bar-nav-icon')
    await w.page.waitForTimeout(500)
    await shot(w.page, theme, 'collapsed', 'real page, mock API', 'drawer collapsed')
    await w.context.close()

    // form (real)
    w = await newPage(browser, theme, base, { to: '/campaigns/new', names: REAL.names })
    await shot(w.page, theme, 'form', 'real page, mock API', 'campaigns.create')
    await w.context.close()

    // dialog + probe (stand-in)
    w = await newPage(browser, theme, base, { to: '/brands', names: '' })
    await shot(w.page, theme, 'probe', 'stand-in', 'probe page: every Vuetify primitive, no data')
    await w.page.click('[data-test="probe-dialog-btn"]')
    await w.page.waitForSelector('.v-overlay--active [data-test="probe-dialog-card"]', {
      timeout: 5000,
    })
    await w.page.waitForTimeout(400)
    await shot(
      w.page,
      theme,
      'dialog',
      'stand-in',
      'probe-page dialog (no agency page opens one without data)',
    )
    await w.context.close()
  }
} finally {
  await browser.close()
  await server.close()
}
writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n')
console.log(`wrote ${manifest.length} screenshots to ${OUT}`)
process.exit(0)
