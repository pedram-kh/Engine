#!/usr/bin/env node
/**
 * AH-120 screenshots over the walk harness (real pages, mock API, flags ON):
 *   node tools/walk/shots-ah120.mjs --out <dir>
 * Dev tool only.
 */
import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

import { AGENCY, DETAIL_ID, fixtureFor } from './l3-fixtures.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')
const i = process.argv.indexOf('--out')
const OUT = path.resolve(i === -1 ? '/tmp/ah120-shots' : process.argv[i + 1])
const PORT = 5199
const JOB = '01HJOB000000000000000001'
const ago = (m) => new Date(Date.now() - m * 60000).toISOString()

const MAYA = '01HCREATORMAYA00000000001'
const DANIEL = '01HCREATORDANIEL000000001'
const BLOCKER = { id: '01HCAMPAIGNZAVA0000000001', name: 'Zava Summer' }

const jobDetail = {
  data: {
    id: JOB,
    type: 'creator_job',
    attributes: {
      name: 'Yahoo Spring creators',
      listing_fee: '€300 per video',
      listing_duration: '4 weeks',
      applicant_count: 3,
      listed_at: ago(3000),
      application_status: null,
      assignment_state: null,
      description: 'Three short-form videos per month.',
      listing_languages: ['en', 'pt'],
      listing_regions: ['PT', 'ES'],
      is_region_locked: false,
      region_lock_reason: null,
      is_brand_locked: true,
      brand_lock_reason: 'brand_exclusive',
      listing_examples_url: null,
      assignment_ulid: null,
      attachments: [],
      brand: { name: 'Yahoo', logo_url: null, website_url: null },
    },
  },
}

const rosterRow = (id, name) => ({
  id: `rel-${id}`,
  type: 'agency_creator_relations',
  attributes: {
    relationship_status: 'roster',
    is_blacklisted: false,
    blacklist_type: null,
    internal_rating: null,
    total_campaigns_completed: 0,
    total_paid_minor_units: 0,
    last_engaged_at: null,
    creator_id: id,
    display_name: name,
    application_status: 'approved',
    country_code: 'US',
    primary_language: 'en',
    categories: ['tech'],
    avatar_url: null,
  },
})

const ok = (body) => ({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })
const missed = []

function handle(url, method) {
  const p = url.pathname
  const base = `/api/v1/agencies/${AGENCY}`
  if (p === `/api/v1/creators/me/jobs/${JOB}`) return jobDetail
  // The POST read goes through the CSRF handshake first; the harness has no API to answer it.
  if (p === '/sanctum/csrf-cookie') return {}
  if (method === 'GET' && p === `${base}/brands` && url.searchParams.get('for') === 'select') {
    return {
      data: ['Jack & Jill', 'Yahoo', 'Zava', 'Old Co'].map((name, i) => ({
        id: `b-${i}`,
        type: 'brands',
        attributes: { name },
      })),
    }
  }
  const res = fixtureFor(url, method)
  if (method === 'GET' && p === `${base}/campaigns/${DETAIL_ID}` && res) {
    // Two blocked brands (one archived) and an end date, as the show endpoint returns them.
    res.data.attributes.exclusive_brand_ids = ['b-yahoo', 'b-old']
    res.data.attributes.exclusive_brands = [
      { id: 'b-yahoo', name: 'Yahoo', is_archived: false },
      { id: 'b-old', name: 'Old Co', is_archived: true },
    ]
    res.data.attributes.exclusivity_ends_on = '2026-12-31'
  }
  if (method === 'GET' && p === `${base}/campaigns/${DETAIL_ID}/applications` && res) {
    const first = res.data.find((r) => r.attributes.status === 'pending')
    if (first && url.searchParams.get('per_page') !== '1') {
      first.attributes.has_exclusivity_conflict = true
      first.attributes.exclusivity_conflict = BLOCKER
    }
  }
  if (method === 'GET' && p === `${base}/creators`) {
    return {
      data: [rosterRow(MAYA, 'Maya Thompson'), rosterRow(DANIEL, 'Daniel Okafor')],
      meta: { total: 2, page: 1, per_page: 100, last_page: 1 },
    }
  }
  if (method === 'POST' && p === `${base}/campaigns/${DETAIL_ID}/exclusivity-conflicts`) {
    return { data: { [MAYA]: { blocking_campaign: BLOCKER } } }
  }
  return res
}

async function open(browser, theme, real, user, width, height, features) {
  const context = await browser.newContext({
    viewport: { width, height },
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    colorScheme: theme,
    locale: 'en-US',
    timezoneId: 'Europe/Madrid',
  })
  await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), theme)
  await context.route(
    (u) => /^\/(api|sanctum)\//.test(u.pathname),
    (route) => {
      const u = new URL(route.request().url())
      const body = handle(u, route.request().method())
      if (!body) missed.push(`${route.request().method()} ${u.pathname}`)
      return route.fulfill(
        body ? ok(body) : { status: 503, contentType: 'application/json', body: '{}' },
      )
    },
  )
  const page = await context.newPage()
  const qs = new URLSearchParams({ to: real.to, user, theme, real: real.names })
  if (features) qs.set('features', features)
  await page.goto(`${base}/tools/walk/index.html?${qs}`, { waitUntil: 'domcontentloaded' })
  await page.waitForFunction(() => window.__walk && window.__walk.ready === true, null, {
    timeout: 60000,
  })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForTimeout(1200)
  return page
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
// At 390 the agency shell opens its drawer over the content; close it the way a user would.
async function closeDrawer(page, width) {
  if (width > 600) return
  const toggle = page.locator('.v-app-bar-nav-icon, [data-test="agency-nav-toggle"]').first()
  if (await toggle.count()) await toggle.click()
  await page.waitForTimeout(500)
}
const shot = (page, name, opts = {}) =>
  page.screenshot({ path: path.join(OUT, name), animations: 'disabled', ...opts })
const FLAGS = 'campaign_brand_exclusivity'
const SIZES = [
  ['1280', 1280, 1100],
  ['390', 390, 900],
]

try {
  for (const [label, width, height] of SIZES) {
    // 1. Campaign form: brand multi-select (two brands chosen) and the end date.
    let page = await open(
      browser,
      'dark',
      { to: '/campaigns/new', names: 'campaigns.create' },
      'agency',
      width,
      height,
      FLAGS,
    )
    await closeDrawer(page, width)
    // The campaign's own brand first (it is then excluded from the blocked list), then two others.
    await page.locator('[data-test="campaign-brand"]').click()
    await page.locator('.v-overlay-container .v-list-item', { hasText: 'Jack & Jill' }).click()
    await page.waitForTimeout(500)
    const brands = page.locator('[data-test="campaign-exclusive-brands"]')
    await brands.scrollIntoViewIfNeeded()
    await brands.locator('.v-field').click()
    await page.locator('.v-overlay-container .v-list-item', { hasText: 'Yahoo' }).click()
    await page.waitForTimeout(500)
    await page.locator('.v-overlay-container .v-list-item', { hasText: 'Zava' }).click()
    await page.keyboard.press('Escape')
    await page.locator('[data-test="campaign-exclusivity-ends-on"] input').fill('2026-12-31')
    await page.waitForTimeout(400)
    await page.locator('[data-test="campaign-exclusivity-ends-on"]').scrollIntoViewIfNeeded()
    await page
      .locator('[data-test="campaign-exclusive-brands"]')
      .evaluate((el) => el.scrollIntoView({ block: 'center' }))
    await page.waitForTimeout(300)
    await shot(page, `1-form-brand-multiselect-${label}.png`)
    await page.context().close()

    // 2. Overview row.
    page = await open(
      browser,
      'dark',
      { to: `/campaigns/${DETAIL_ID}`, names: 'campaigns.detail' },
      'agency',
      width,
      height,
      FLAGS,
    )
    await closeDrawer(page, width)
    await page.locator('[data-test="overview-exclusive-brands-field"]').scrollIntoViewIfNeeded()
    await shot(page, `2-overview-row-${label}.png`)
    await page.context().close()

    // 3. Application conflict badge (tooltip open).
    page = await open(
      browser,
      'dark',
      { to: `/campaigns/${DETAIL_ID}?tab=applications`, names: 'campaigns.detail' },
      'agency',
      width,
      height,
      FLAGS,
    )
    await closeDrawer(page, width)
    await page.waitForSelector('[data-test^="applications-conflict-"]')
    await page.locator('[data-test^="applications-conflict-"]').first().hover()
    await page.waitForTimeout(700)
    await shot(page, `3-application-conflict-badge-${label}.png`)
    await page.context().close()

    // 4. Invite dialog warning (Maya selected).
    page = await open(
      browser,
      'dark',
      { to: `/campaigns/${DETAIL_ID}`, names: 'campaigns.detail' },
      'agency',
      width,
      height + 200,
      FLAGS,
    )
    await closeDrawer(page, width)
    await page.locator('[data-test="campaign-detail-invite"]').first().click()
    await page.waitForSelector(`[data-test="invite-creators-checkbox-${MAYA}"]`)
    await page.locator(`[data-test="invite-creators-checkbox-${MAYA}"]`).click()
    await page.waitForTimeout(800)
    await page.waitForSelector('[data-test="invite-creators-exclusivity"]')
    await page.waitForTimeout(500)
    await shot(page, `4-invite-dialog-warning-${label}.png`)
    // The Invite button is disabled in that shot only because the fee is empty: with a fee
    // typed, the warning still shows and the button is enabled.
    const submit = page.locator('[data-test="invite-creators-submit"]')
    const before = await submit.isDisabled()
    await page.locator('[data-test="invite-creators-fee"] input').first().fill('250')
    await page.waitForTimeout(400)
    console.log(
      `invite submit @${label}: disabled with empty fee=${before}; with fee=${await submit.isDisabled()}; warning still shown=${await page.locator('[data-test="invite-creators-exclusivity"]').isVisible()}`,
    )
    await shot(page, `4b-invite-dialog-warning-fee-filled-${label}.png`)
    await page.context().close()

    // 5. Creator job detail, "Not available to you".
    page = await open(
      browser,
      'dark',
      { to: `/creator/jobs/${JOB}`, names: 'creator.job.detail' },
      'creator',
      width,
      height,
    )
    await shot(page, `5-creator-job-not-available-${label}.png`, { fullPage: true })
    await page.context().close()
  }
} finally {
  await browser.close()
  await server.close()
}
console.log('unfixtured:', [...new Set(missed)].join('\n  '))
console.log('shots ->', OUT)
