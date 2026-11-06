#!/usr/bin/env node
/**
 * AH-119 screenshots over the walk harness (real pages, mock API, `messages_v2_inbox` ON):
 *   node tools/walk/shots-ah119.mjs --out <dir> [--theme dark|light]
 * The mock inbox honours brand[], campaign[], unread and owner, so the popover counts and the Mine
 * chip show what the API would return. Dev tool only.
 */
import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import { chromium } from '@playwright/test'
import { createServer } from 'vite'

import { AGENCY, fixtureFor } from './l3-fixtures.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const APP_ROOT = path.resolve(HERE, '../..')
const arg = (n, d) => {
  const i = process.argv.indexOf(`--${n}`)
  return i === -1 ? d : process.argv[i + 1]
}
const OUT = path.resolve(arg('out', '/tmp/ah119-shots'))
const THEME = arg('theme', 'dark')
const PORT = 5197
const ago = (m) => new Date(Date.now() - m * 60000).toISOString()

const BRANDS = [
  ['01HBRANDJACKJILL0000000001', 'Jack & Jill AI', 'violet'],
  ['01HBRANDBOLTFOOD0000000002', 'Bolt Food', 'green'],
  ['01HBRANDCITYSTASH000000003', 'CityStasher', 'blue'],
  ['01HBRANDPRIME00000000000004', 'Prime Prometics', 'amber'],
  ['01HBRANDSKYSCAN0000000005', 'Skyscanner', 'teal'],
  ['01HBRANDCLEO0000000000006', 'Cleo', 'pink'],
]
const brand = (i) => ({ id: BRANDS[i][0], name: BRANDS[i][1], color: BRANDS[i][2] })
const CAMPAIGNS = [
  ['01HCAMPWAVE1000000000001', 'Wave 1', 0],
  ['01HCAMPSPRING00000000002', 'Spring push', 1],
  ['01HCAMPLAUNCH0000000003', 'Launch', 3],
  ['01HCAMPLONDON0000000004', 'London pop-up', 2],
]
const camp = (i) => ({ id: CAMPAIGNS[i][0], name: CAMPAIGNS[i][1] })
const JORDAN = { id: '01HUSERJORDAN0000000000001', name: 'Jordan' }
const RILEY = { id: '01HUSERRILEY00000000000002', name: 'Riley' }

const A = {
  maya: ['01HCREATORMAYA00000000001', 'Maya Thompson'],
  daniel: ['01HCREATORDANIEL000000002', 'Daniel Okafor'],
  priya: ['01HCREATORPRIYA0000000003', 'Priya Shah'],
  lukas: ['01HCREATORLUKAS0000000004', 'Lukas Meyer'],
  amara: ['01HCREATORAMARA0000000005', 'Amara Nwosu'],
  noah: ['01HCREATORNOAH00000000006', 'Noah Fischer'],
  sofia: ['01HCREATORSOFIA0000000007', 'Sofia Rossi'],
}
const ASG = (n) => `01HASSIGNMENT0000000000${n}`

function row(type, who, o) {
  return {
    id: `thread-${who[0]}-${type === 'assignment_thread' ? o.asg : 'd'}`,
    type,
    attributes: {
      creator: { id: who[0], display_name: who[1], avatar_url: null },
      brand: o.brand ?? null,
      campaign: o.campaign ?? null,
      assignment_id: o.asg ?? null,
      last_message_at: o.at,
      preview: o.preview,
      preview_kind: o.kind ?? 'text',
      unread_count: o.unread,
      owner: o.owner,
    },
  }
}

const ROWS = [
  row('relationship_thread', A.maya, {
    at: ago(8),
    preview: 'Perfect, I will film the hook variations tomorrow morning',
    unread: 2,
    owner: JORDAN,
  }),
  row('assignment_thread', A.daniel, {
    asg: ASG(1),
    brand: brand(0),
    campaign: camp(0),
    at: ago(25),
    preview: 'Sent over the raw footage, let me know if you need any retakes',
    unread: 2,
    owner: JORDAN,
  }),
  row('assignment_thread', A.priya, {
    asg: ASG(2),
    brand: brand(1),
    campaign: camp(1),
    at: ago(90),
    preview: 'Contract signed and returned',
    unread: 1,
    owner: RILEY,
  }),
  row('assignment_thread', A.lukas, {
    asg: ASG(3),
    brand: brand(3),
    campaign: camp(2),
    at: ago(200),
    preview: 'brief-v2.pdf',
    kind: 'attachment',
    unread: 3,
    owner: JORDAN,
  }),
  row('relationship_thread', A.sofia, {
    at: ago(60 * 20),
    preview: 'Thanks for the update, speak soon',
    unread: 0,
    owner: RILEY,
  }),
  row('assignment_thread', A.noah, {
    asg: ASG(4),
    brand: brand(2),
    campaign: camp(3),
    at: ago(60 * 30),
    preview: 'Happy to join the next campaign',
    unread: 0,
    owner: JORDAN,
  }),
  row('relationship_thread', A.amara, {
    at: ago(60 * 24 * 9),
    preview: 'Looking forward to it',
    unread: 0,
    owner: null,
  }),
]

function inbox(url) {
  const sp = url.searchParams
  const brands = sp.getAll('brand[]')
  const campaigns = sp.getAll('campaign[]')
  const unread = sp.get('unread') === '1'
  const mine = sp.get('owner') === 'me'
  const base = ROWS.filter((r) => !mine || r.attributes.owner?.id === JORDAN.id)
  const data = base.filter((r) => {
    const a = r.attributes
    if (brands.length && !(a.brand && brands.includes(a.brand.id))) return false
    if (campaigns.length && !(a.campaign && campaigns.includes(a.campaign.id))) return false
    if (unread && a.unread_count === 0) return false
    return true
  })
  const count = (pred) =>
    base.filter((r) => r.attributes.unread_count > 0 && pred(r.attributes)).length
  return {
    data,
    meta: {
      unread_threads_total: ROWS.filter((r) => r.attributes.unread_count > 0).length,
      facets: {
        brands: BRANDS.map((b, i) => ({
          ...brand(i),
          unread_threads: count((a) => a.brand?.id === b[0]),
        })),
        campaigns: CAMPAIGNS.map((c, i) => ({
          ...camp(i),
          brand_id: BRANDS[c[2]][0],
          unread_threads: count((a) => a.campaign?.id === c[0]),
        })),
      },
      next_cursor: null,
    },
  }
}

const IMAGE =
  'data:image/svg+xml;utf8,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="360" height="220"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8b6cff"/><stop offset="1" stop-color="#3ecf9e"/></linearGradient></defs><rect width="360" height="220" fill="url(#g)"/><text x="24" y="120" font-family="Inter,sans-serif" font-size="28" fill="#fff">Wave 1 hook frame</text></svg>',
  )

const msg = (id, at, body, own, extra = {}) => ({
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
    sender: { name: own ? 'Jordan' : 'Daniel Okafor' },
    created_at: at,
    ...extra,
  },
})

function assignmentFeed() {
  return {
    data: [
      msg('s1', ago(600), null, false, {
        kind: 'system',
        sender_role: 'system',
        sender: null,
        system_event_key: 'assignment.offer_edited',
        system_event_data: { previous_amount: 10000, new_amount: 25050, currency: 'EUR' },
      }),
      msg(
        's2',
        ago(300),
        'Hi Daniel, the brief is at https://example.com/wave-1-brief. Frame 3 is the hook.',
        true,
      ),
      msg('s3', ago(120), 'Got it. Here is the frame I plan to open on.', false, {
        attachments: [
          {
            s3_path: 'x/hook.png',
            mime_type: 'image/png',
            name: 'hook-frame.png',
            size_bytes: 184000,
            view_url: IMAGE,
          },
        ],
      }),
      msg('s4', ago(40), 'Sent over the raw footage, let me know if you need any retakes', false),
    ],
    meta: {
      thread: {
        id: 'thread-a',
        assignment_id: ASG(1),
        last_message_at: ago(40),
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
  const base = `/api/v1/agencies/${AGENCY}`
  if (method === 'GET' && p === `${base}/message-inbox`) return inbox(url)
  if (method === 'GET' && p === `${base}/members`) {
    return {
      data: [JORDAN, RILEY, { id: '01HUSERAVA00000000000003', name: 'Ava' }].map((u) => ({
        id: u.id,
        type: 'agency_memberships',
        attributes: {
          user_id: u.id,
          name: u.name,
          email: `${u.name}@x.test`,
          role: 'agency_staff',
          status: 'active',
          created_at: ago(9999),
          last_active_at: null,
        },
      })),
      meta: { total: 3, page: 1, per_page: 100, last_page: 1 },
    }
  }
  if (
    new RegExp(`^${base}/campaigns/[^/]+/assignments/[^/]+/messages$`).test(p) &&
    method === 'GET'
  ) {
    return assignmentFeed()
  }
  if (/\/assignments\/[^/]+\/messages\/read$/.test(p))
    return { meta: { marked: 0, unread_count: 0 } }
  return fixtureFor(url, method)
}

async function open(browser, route, names, width, height) {
  const context = await browser.newContext({
    viewport: { width, height },
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    colorScheme: THEME,
    locale: 'en-US',
    timezoneId: 'Europe/Madrid',
  })
  await context.addInitScript((t) => window.localStorage.setItem('catalyst.main.theme', t), THEME)
  await context.route(
    (u) => /^\/(api|sanctum)\//.test(u.pathname),
    (r) => {
      const u = new URL(r.request().url())
      const body = handle(u, r.request().method())
      if (!body) missed.push(`${r.request().method()} ${u.pathname}`)
      return r.fulfill(
        body ? ok(body) : { status: 503, contentType: 'application/json', body: '{}' },
      )
    },
  )
  const page = await context.newPage()
  const qs = new URLSearchParams({
    to: route,
    user: 'agency',
    theme: THEME,
    real: names,
    features: 'messages_v2_inbox',
  })
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
  page.screenshot({ path: path.join(OUT, `${THEME}-${name}`), animations: 'disabled', ...opts })
const NAMES = 'messages.inbox,messages.thread,messages.assignment-thread'
const failures = []
const expect = (cond, what) => {
  if (!cond) failures.push(what)
}

try {
  // 1. The unified inbox: Direct and assignment rows, pill, tags, brand dots.
  let page = await open(browser, '/messages', NAMES, 1600, 900)
  await page.waitForSelector('[data-test="unified-inbox"]')
  expect((await page.locator('[data-test^="inbox-row-"]').count()) === 7, 'seven rows')
  expect(
    (await page.locator('[data-test="agency-inbox-unread-pill"]').innerText()).includes('4'),
    'pill = 4 threads with unread',
  )
  expect(
    (await page
      .locator('[data-test="inbox-tag-relationship_thread:thread-01HCREATORMAYA00000000001-d"]')
      .innerText()) === 'Direct',
    'Direct tag',
  )
  await shot(page, '1-unified-inbox.png')

  // 2. The brand popover with counts.
  await page.locator('[data-test="inbox-filter-brand"]').click()
  await page.waitForSelector('[data-test="inbox-filter-brand-popover"]')
  await page.waitForTimeout(400)
  expect(
    (await page
      .locator('[data-test="inbox-filter-brand-count-01HBRANDPRIME00000000000004"]')
      .innerText()) === '1',
    'Prime count = 1 thread',
  )
  await page.locator('[data-test="inbox-filter-brand-option-01HBRANDJACKJILL0000000001"]').click()
  await page.waitForTimeout(500)
  await shot(page, '2-brand-popover-counts.png')
  await page.keyboard.press('Escape')
  await page.waitForTimeout(300)

  // 3. The Mine chip on (with the brand selection cleared first).
  await page.locator('[data-test="agency-inbox-clear-filters"]').click()
  await page.waitForTimeout(400)
  await page.locator('[data-test="agency-inbox-mine"]').click()
  await page.waitForTimeout(600)
  expect(
    (await page.locator('[data-test^="inbox-row-"]').count()) === 4,
    'Mine shows Jordan 4 rows',
  )
  await shot(page, '3-mine-chip.png')
  await page.context().close()

  // 4. An assignment thread inside the fork: system line, link, inline image, tag, owner menu.
  page = await open(
    browser,
    `/messages/c/${CAMPAIGNS[0][0]}/${ASG(1)}?name=Daniel%20Okafor`,
    NAMES,
    1600,
    900,
  )
  await page.waitForSelector('[data-test="relationship-thread"]')
  await page.waitForTimeout(800)
  expect(
    (await page.locator('[data-test="thread-system-s1"]').count()) === 1,
    'system line rendered',
  )
  expect((await page.locator('[data-test="message-link"]').count()) === 1, 'link rendered')
  expect((await page.locator('[data-test="message-image"]').count()) === 1, 'inline image rendered')
  expect((await page.locator('[data-test="thread-campaign-tag"]').count()) === 1, 'campaign tag')
  await shot(page, '4-assignment-thread.png')
  await page.locator('[data-test="thread-more"]').click()
  await page.waitForTimeout(400)
  await shot(page, '5-thread-menu.png')
  await page.context().close()
} finally {
  await browser.close()
  await server.close()
}
console.log('unfixtured:', [...new Set(missed)].join('\n  ') || '(none)')
console.log(failures.length ? `CHECKS FAILED: ${failures.join('; ')}` : 'checks: all passed')
console.log('shots ->', OUT)
process.exitCode = failures.length ? 1 : 0
