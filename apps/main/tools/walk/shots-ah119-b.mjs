#!/usr/bin/env node
/**
 * AH-119 Stage B screenshots over the walk harness (real pages, mock API, `messages_v2_inbox` and
 * `messages_v2_edit_search` ON):
 *   node tools/walk/shots-ah119-b.mjs --out <dir> [--theme dark|light]
 * Walks the edit flow (menu, edit field, "edited" tag), the deleted placeholder and the inbox
 * search results, and asserts the state it shows. The mock keeps edits and deletes in memory so
 * the thread re-renders what the API would answer. Dev tool only.
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
const OUT = path.resolve(arg('out', '/tmp/ah119-b-shots'))
const THEME = arg('theme', 'dark')
const PORT = 5198
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

const FEED = [
  msg('s1', ago(300), 'Hi Daniel, the brief is at https://example.com/wave-1-brief.', true),
  msg('s2', ago(120), 'Got it. I will open on frame 3.', false),
  msg('s3', ago(8), 'I will send the final cut by Friday evening.', true),
  msg('s4', ago(6), 'Sorry, wrong day: the final cut comes on Thursday evening.', true, {
    edited_at: ago(4),
    is_deleted: false,
  }),
  msg('s5', ago(3), null, true, { is_deleted: true }),
]

function assignmentFeed() {
  return {
    data: FEED,
    meta: {
      thread: {
        id: 'thread-a',
        assignment_id: ASG(1),
        last_message_at: ago(3),
        unread_count: 0,
        human_send_blocked: false,
      },
      has_more: false,
    },
  }
}

const hit = (ulid, snippet, type, who, o) => ({
  id: `thread-${who[0]}-${type === 'assignment_thread' ? o.asg : 'd'}`,
  type,
  attributes: {
    message_ulid: ulid,
    snippet,
    sent_at: ago(o.ago),
    sender_role: o.own ? 'agency_user' : 'creator',
    sender_is_erased: false,
    is_own: o.own ?? false,
    creator: { id: who[0], display_name: who[1], avatar_url: null },
    brand: o.brand ?? null,
    campaign: o.campaign ?? null,
    assignment_id: o.asg ?? null,
    last_message_at: ago(o.ago),
    preview: snippet,
    preview_kind: 'text',
    unread_count: 0,
    owner: JORDAN,
  },
})

function search(url) {
  const q = (url.searchParams.get('q') ?? '').toLowerCase()
  const all = [
    hit(
      '01HMSGHIT0000000000000001',
      '…the final cut brief is attached, please check frame 3…',
      'assignment_thread',
      A.daniel,
      {
        asg: ASG(1),
        brand: brand(0),
        campaign: camp(0),
        ago: 40,
      },
    ),
    hit(
      '01HMSGHIT0000000000000002',
      '…I will film the hook after reading the brief again…',
      'relationship_thread',
      A.maya,
      { ago: 60 * 6 },
    ),
    hit(
      '01HMSGHIT0000000000000003',
      '…sent you the brief v2 with the new rates…',
      'assignment_thread',
      A.priya,
      {
        asg: ASG(2),
        brand: brand(1),
        campaign: camp(1),
        ago: 60 * 30,
        own: true,
      },
    ),
  ]
  const data = q.includes('brief') ? all : []
  return { data, meta: { next_cursor: null, per_page: 30 } }
}

const ok = (body) => ({ status: 200, contentType: 'application/json', body: JSON.stringify(body) })
const missed = []
const bodyOf = { current: null }

function handle(url, method) {
  const p = url.pathname
  const base = `/api/v1/agencies/${AGENCY}`
  if (method === 'GET' && p === `${base}/message-inbox`) return inbox(url)
  if (p === '/sanctum/csrf-cookie') return {}
  const one = new RegExp(`^${base}/campaigns/[^/]+/assignments/[^/]+/messages/([^/]+)$`).exec(p)
  if (one && (method === 'PATCH' || method === 'DELETE')) {
    const target = FEED.find((m) => m.id === one[1])
    if (!target) return null
    if (method === 'PATCH') {
      target.attributes.body = JSON.parse(bodyOf.current ?? '{}').body
      target.attributes.edited_at = new Date().toISOString()
    } else {
      target.attributes.body = null
      target.attributes.attachments = []
      target.attributes.is_deleted = true
    }
    return { data: target }
  }
  if (method === 'GET' && p === `${base}/message-search`) return search(url)
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
      bodyOf.current = r.request().postData()
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
    features: 'messages_v2_inbox,messages_v2_edit_search',
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
  // 1. The agency's own messages carry a "⋯" menu; theirs do not. Open the menu on a recent one.
  let page = await open(
    browser,
    `/messages/c/${CAMPAIGNS[0][0]}/${ASG(1)}?name=Daniel%20Okafor`,
    NAMES,
    1600,
    900,
  )
  await page.waitForSelector('[data-test="relationship-thread"]')
  await page.waitForTimeout(800)
  expect(
    (await page.locator('[data-test="message-actions"]').count()) === 3,
    'menu on own, non-deleted messages (s1, s3, s4)',
  )
  expect(
    (await page
      .locator('[data-test="relationship-message-s2"] [data-test="message-actions"]')
      .count()) === 0,
    'no menu on the creator message',
  )
  expect((await page.locator('[data-test="message-edited"]').count()) === 1, 'one "edited" tag')
  expect(
    (await page
      .locator('[data-test="relationship-message-s5"] [data-test="message-deleted"]')
      .innerText()) === 'Message deleted',
    'deleted placeholder',
  )
  expect(
    (await page
      .locator('[data-test="relationship-message-s5"] [data-test="message-actions"]')
      .count()) === 0,
    'no menu on a deleted message',
  )
  await page.locator('[data-test="relationship-message-s3"] [data-test="message-actions"]').click()
  await page.waitForSelector('[data-test="message-action-edit"]')
  await page.waitForTimeout(300)
  await shot(page, '1-edit-menu.png')

  // 2. The edit field, then the saved message with its "edited" tag (the mock answers the PATCH).
  await page.locator('[data-test="message-action-edit"]').click()
  await page.waitForSelector('[data-test="message-edit-input"]')
  await page
    .locator('[data-test="message-edit-input"] textarea.v-field__input:not([readonly])')
    .fill('I will send the final cut by Thursday evening.')
  await page.waitForTimeout(300)
  await shot(page, '2-edit-field.png')
  await page.locator('[data-test="message-edit-save"]').click()
  await page.waitForSelector('[data-test="relationship-message-s3"] [data-test="message-edited"]')
  await page.waitForTimeout(400)
  expect(
    (await page.locator('[data-test="relationship-message-s3"]').innerText()).includes(
      'Thursday evening',
    ),
    'edit shown after save',
  )
  await shot(page, '3-edited-tag.png')

  // 3. An older message: no Edit, the hint and Delete; the delete dialog.
  await page.locator('[data-test="relationship-message-s1"] [data-test="message-actions"]').click()
  await page.waitForSelector('[data-test="message-action-edit-hint"]')
  expect(
    (await page.locator('[data-test="message-action-edit"]').count()) === 0,
    'no Edit past the window',
  )
  await page.waitForTimeout(300)
  await shot(page, '4-menu-past-window.png')
  await page.locator('[data-test="message-action-delete"]').click()
  await page.waitForSelector('[data-test="message-delete-dialog"]')
  await page.waitForTimeout(400)
  await shot(page, '5-delete-confirm.png')
  await page.locator('[data-test="message-delete-confirm"]').click()
  await page.waitForSelector('[data-test="relationship-message-s1"] [data-test="message-deleted"]')
  await page.waitForTimeout(400)
  await shot(page, '6-deleted-placeholder.png')
  await page.context().close()

  // 4. Search in the unified inbox: the hint, then results from both kinds of thread.
  page = await open(browser, '/messages', NAMES, 1600, 900)
  await page.waitForSelector('[data-test="unified-inbox"]')
  const field = page.locator('[data-test="agency-inbox-search"] input')
  await field.fill('b')
  await page.waitForSelector('[data-test="message-search-min"]')
  await shot(page, '7-search-min-chars.png')
  await field.fill('brief')
  await page.waitForSelector('[data-test^="message-hit-0"]', { timeout: 10000 })
  await page.waitForTimeout(400)
  expect(
    (await page.locator('[data-test="message-search-list"] > li').count()) === 3,
    'three search hits',
  )
  expect(
    (await page.locator('[data-test="message-search-results"]').innerText()).includes('You: '),
    'own hit labelled You',
  )
  await shot(page, '8-search-results.png')
  await field.fill('nothingmatches')
  await page.waitForSelector('[data-test="message-search-none"]', { timeout: 10000 })
  await shot(page, '9-search-empty.png')
  await page.context().close()
} finally {
  await browser.close()
  await server.close()
}
console.log('unfixtured:', [...new Set(missed)].join('\n  ') || '(none)')
console.log(failures.length ? `CHECKS FAILED: ${failures.join('; ')}` : 'checks: all passed')
console.log('shots ->', OUT)
process.exitCode = failures.length ? 1 : 0
