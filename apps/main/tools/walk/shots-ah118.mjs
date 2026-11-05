#!/usr/bin/env node
/**
 * AH-118 screenshots over the walk harness (real pages, mock API, flags ON):
 *   node tools/walk/shots-ah118.mjs --out <dir>
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
const OUT = path.resolve(i === -1 ? '/tmp/ah118-shots' : process.argv[i + 1])
const PORT = 5199
const A1 = '01HASSIGNMENT00000000001'
const A2 = '01HASSIGNMENT00000000002'
const JOB = '01HJOB000000000000000001'
const ago = (m) => new Date(Date.now() - m * 60000).toISOString()

const assignment = (id, name, status, fee, locked, reason, extra = {}) => ({
  id,
  type: 'campaign_assignments',
  attributes: {
    status,
    agreed_fee_minor_units: fee,
    agreed_fee_currency: 'EUR',
    fee_per: 'per video',
    offer_description: null,
    offer_attachment: null,
    countered_fee_minor_units: null,
    countered_fee_currency: null,
    invited_at: ago(600),
    responded_at: null,
    posting_due_at: null,
    verification_status: null,
    has_pending_contract: reason === 'contract_pending',
    offer_edit_locked: locked,
    offer_edit_lock_reason: reason,
    creator: { id: `cr-${id}`, display_name: name, avatar_url: null },
    ...extra,
  },
})

const jobDetail = {
  data: {
    id: JOB,
    type: 'creator_job',
    attributes: {
      name: 'Autumn UGC push',
      listing_fee: '€300 per video',
      listing_duration: '4 weeks',
      applicant_count: 3,
      listed_at: ago(3000),
      application_status: null,
      assignment_state: null,
      description: 'Three short-form videos per month.',
      listing_languages: ['en', 'pt'],
      listing_regions: ['PT', 'ES'],
      is_region_locked: true,
      listing_examples_url: null,
      assignment_ulid: null,
      attachments: [],
      brand: { name: 'Northwind Coffee', logo_url: null, website_url: null },
    },
  },
}

const creatorAssignment = {
  data: {
    id: A1,
    type: 'campaign_assignment',
    attributes: {
      status: 'invited',
      agreed_fee_minor_units: 25050,
      agreed_fee_currency: 'EUR',
      fee_per: 'per video',
      countered_fee_minor_units: null,
      countered_fee_currency: null,
      deliverables: null,
      attachments: [],
      posting_due_at: null,
      invited_at: ago(600),
      submitted_draft_at: null,
      approved_at: null,
      posted_at: null,
      campaign: {
        id: DETAIL_ID,
        name: 'Business professionals (US)',
        description: null,
        posting_window_starts_at: null,
        posting_window_ends_at: null,
        brand_name: 'Northwind',
      },
    },
    relationships: { drafts: [], posted_content: [], contract: null },
  },
}

function chatFeed() {
  const text = (id, at, body, own) => ({
    id,
    type: 'message',
    attributes: {
      kind: 'text',
      sender_role: own ? 'agency_user' : 'creator',
      body,
      attachments: [],
      system_event_key: null,
      system_event_data: null,
      is_own: own,
      sender: { name: own ? 'Jordan' : 'Maya Thompson' },
      created_at: at,
    },
  })
  return {
    data: [
      text('c1', ago(300), 'Hi Maya, the brief is in the campaign files.', true),
      {
        id: 'c2',
        type: 'message',
        attributes: {
          kind: 'system',
          sender_role: 'system',
          body: null,
          attachments: [],
          system_event_key: 'assignment.offer_edited',
          system_event_data: { previous_amount: 10000, new_amount: 25050, currency: 'EUR' },
          is_own: false,
          sender: null,
          created_at: ago(120),
        },
      },
      text('c3', ago(100), 'Thanks, that works for me.', false),
    ],
    meta: {
      thread: {
        id: 'thread-c',
        assignment_id: A1,
        last_message_at: ago(100),
        unread_count: 0,
        human_send_blocked: false,
      },
      has_more: false,
    },
  }
}

const ok = (body) => ({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })
const missed = []

function handle(url, method) {
  const p = url.pathname
  if (p === `/api/v1/agencies/${AGENCY}/campaigns/${DETAIL_ID}/assignments` && method === 'GET') {
    return {
      data: [
        assignment(A1, 'Maya Thompson', 'invited', 10000, false, null),
        assignment(A2, 'Daniel Okafor', 'accepted', 40000, true, 'contract_pending'),
      ],
      meta: { total: 2, page: 1, per_page: 25, last_page: 1 },
    }
  }
  if (
    p === `/api/v1/agencies/${AGENCY}/campaigns/${DETAIL_ID}/assignments/${A1}/messages` &&
    method === 'GET'
  )
    return chatFeed()
  if (p === `/api/v1/creators/me/assignments/${A1}/messages` && method === 'GET') return chatFeed()
  if (p === `/api/v1/creators/me/assignments/${A1}/messages/read`)
    return { meta: { marked: 0, unread_count: 0 } }
  if (p === `/api/v1/creators/me/jobs/${JOB}`) return jobDetail
  if (p === `/api/v1/creators/me/assignments/${A1}`) return creatorAssignment
  return fixtureFor(url, method)
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
const shot = (page, name, opts = {}) =>
  page.screenshot({ path: path.join(OUT, name), animations: 'disabled', ...opts })
const FLAGS = 'campaign_region_restriction,assignment_offer_edit'

try {
  // 1. Campaign form: switch + hint (regions chosen so the switch is enabled and ON).
  let page = await open(
    browser,
    'dark',
    { to: '/campaigns/new', names: 'campaigns.create' },
    'agency',
    1280,
    1100,
    FLAGS,
  )
  const sw = page.locator('[data-test="campaign-restrict-to-regions"]')
  await sw.scrollIntoViewIfNeeded()
  await shot(page, '1-form-switch-disabled-no-regions.png', { fullPage: true })
  const regions = page.locator('[data-test="campaign-listing-regions"]')
  await regions.click()
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('Enter')
  await page.keyboard.press('Escape')
  await page.waitForTimeout(300)
  await page.locator('[data-test="campaign-restrict-to-regions"] input').click({ force: true })
  await page.waitForTimeout(300)
  await shot(page, '1-form-switch-on.png', { fullPage: true })
  await page.context().close()

  // 2. Edit-offer dialog: editable (Maya) and locked (Daniel, contract_pending).
  page = await open(
    browser,
    'dark',
    { to: `/campaigns/${DETAIL_ID}`, names: 'campaigns.detail' },
    'agency',
    1280,
    900,
    FLAGS,
  )
  await page.locator('[data-test="tab-creators"]').click()
  await page.waitForTimeout(600)
  await page.locator(`[data-test="creators-edit-offer-${A1}"]`).click()
  await page.waitForTimeout(600)
  await page.locator('[data-test="edit-offer-amount"] input').fill('250.5')
  await page.waitForTimeout(300)
  await shot(page, '2-edit-offer-editable.png')
  await page.keyboard.press('Escape')
  await page.waitForTimeout(500)
  await page.locator(`[data-test="creators-edit-offer-${A2}"]`).click()
  await page.waitForTimeout(600)
  await shot(page, '2-edit-offer-locked.png')
  await page.keyboard.press('Escape')

  // 3. Chat history line.
  await page.locator('[data-test="tab-messages"]').click()
  await page.waitForTimeout(800)
  await page.locator('[data-test="thread-row-thread-c"]').click()
  await page.waitForTimeout(1000)
  await shot(page, '3-chat-history-line.png')
  await page.context().close()

  // 4. Creator job detail, locked, dark, web.
  page = await open(
    browser,
    'dark',
    { to: `/creator/jobs/${JOB}`, names: 'creator.job.detail' },
    'creator',
    1280,
    900,
  )
  await shot(page, '4-creator-job-locked-dark.png', { fullPage: true })
  await page.context().close()

  // 5. Creator assignment detail after an edit (EUR 250.50, was 100.00).
  page = await open(
    browser,
    'dark',
    { to: `/creator/assignments/${A1}`, names: 'creator.assignment.detail' },
    'creator',
    1280,
    900,
  )
  await shot(page, '5-creator-assignment-after-edit.png', { fullPage: true })
  await page.context().close()
} finally {
  await browser.close()
  await server.close()
}
console.log('unfixtured:', [...new Set(missed)].join('\n  '))
console.log('shots ->', OUT)
