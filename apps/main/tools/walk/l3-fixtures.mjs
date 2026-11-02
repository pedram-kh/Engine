/**
 * AH-114 L3 — mock API fixtures and the real-page table shared by `pins.mjs` (pins on a real
 * page component) and `shots-l3.mjs` (the review screenshots).
 *
 * `REAL_PAGES[id]` says which route to open, which route names keep their real page
 * component, and the viewport width. `fixtureFor(url, method)` answers the `/api` calls those
 * pages make; anything else answers 503 (the caller does that). Fixture text avoids the
 * vocabulary the chunk bans, so the Vocabulary grep over `tools/` stays clean.
 *
 * Dev tool only.
 */

export const AGENCY = '01HWALKAGENCY000000000000'
export const DETAIL_ID = '01HCAMPAIGN00000000000001'

import { L4_REAL_PAGES, brandResource, l4FixtureFor } from './l4-fixtures.mjs'

const MESSAGES_NAMES = 'messages.inbox,messages.thread'
const THREAD_CREATOR = '01HCREATORMAYA00000000001'

export const REAL_PAGES = {
  'campaigns-1600': { to: '/campaigns', names: 'campaigns.list,campaigns.create', width: 1600 },
  'campaigns-1280': { to: '/campaigns', names: 'campaigns.list,campaigns.create', width: 1280 },
  'campaign-detail-1600': { to: `/campaigns/${DETAIL_ID}`, names: 'campaigns.detail', width: 1600 },
  'campaign-detail-1280': { to: `/campaigns/${DETAIL_ID}`, names: 'campaigns.detail', width: 1280 },
  'discover-1600': { to: '/discover', names: 'discover.list', width: 1600 },
  'discover-1280': { to: '/discover', names: 'discover.list', width: 1280 },
  // Messages: the list pane alone, then a conversation open in the right pane.
  'messages-1600': { to: '/messages', names: MESSAGES_NAMES, width: 1600, height: 900 },
  'messages-1280': { to: '/messages', names: MESSAGES_NAMES, width: 1280, height: 800 },
  'messages-thread-1600': {
    to: `/messages/${THREAD_CREATOR}?name=Maya%20Thompson`,
    names: MESSAGES_NAMES,
    width: 1600,
    height: 900,
  },
  'messages-thread-1280': {
    to: `/messages/${THREAD_CREATOR}?name=Maya%20Thompson`,
    names: MESSAGES_NAMES,
    width: 1280,
    height: 800,
  },
  ...L4_REAL_PAGES,
}

// ── Campaigns list (Figma 14:3: nine rows of twenty-four) ─────────────────────────────────

const BRANDS = [
  ['01HBRANDJACKJILL0000000001', 'Jack & Jill'],
  ['01HBRANDUKLASH00000000002', 'UKLASH'],
  ['01HBRANDBOLTFOOD000000003', 'Bolt Food'],
  ['01HBRANDCITYSTASH00000004', 'CityStasher'],
  ['01HBRANDPRIMEPROM00000005', 'Prime Prometics'],
  ['01HBRANDVIKTOR0000000006', 'Viktor'],
  ['01HBRANDSKYSCAN00000007', 'Skyscanner'],
  ['01HBRANDSINCERELY0000008', 'Sincerely Yours'],
  ['01HBRANDCLEO00000000009', 'Cleo'],
]

/** [name, brand index, status, listed, budget minor units, currency, starts_at] */
const CAMPAIGN_ROWS = [
  ['Business professionals (US)', 0, 'active', true, 15000, 'USD', '2026-10-08'],
  ['Autumn creators, round 8', 1, 'active', false, 10000, 'GBP', '2026-09-22'],
  ['Food delivery, round 7', 2, 'draft', false, 10000, 'EUR', '2026-10-03'],
  ['Storage, round 1', 3, 'draft', true, 0, 'GBP', '2026-10-03'],
  ['Pharma support, round 3', 4, 'active', true, 0, 'GBP', '2026-10-08'],
  ['Viktor, round 2', 5, 'active', true, 0, 'GBP', '2026-10-06'],
  ['Travel, round 4', 6, 'active', true, 0, 'EUR', '2026-10-12'],
  ['Skincare UGC (US)', 7, 'cancelled', false, 15000, 'USD', '2026-09-01'],
  ['Fintech creators, round 13 (US)', 8, 'active', true, 300000, 'USD', '2026-10-10'],
]

export function campaignFixture(i, row) {
  const [name, brandIdx, status, listed, budget, currency, startsAt] = row
  const [brandId, brandName] = BRANDS[brandIdx]
  return {
    id: `01HCAMPAIGN000000000000${String(i + 1).padStart(2, '0')}`,
    type: 'campaigns',
    attributes: {
      name,
      description: 'A short brief for the creators: what to show, what to say, how long.',
      objective: 'awareness',
      status,
      budget_minor_units: budget,
      budget_currency: currency,
      starts_at: `${startsAt}T00:00:00Z`,
      ends_at: '2026-12-20T00:00:00Z',
      posting_window_starts_at: null,
      posting_window_ends_at: null,
      brief: null,
      target_creator_count: 6,
      requires_per_campaign_contract: i % 2 === 0,
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
      assignment_count: 0,
      created_at: '2026-01-10T09:00:00Z',
      updated_at: '2026-02-10T09:00:00Z',
    },
    relationships: {
      brand: { data: { id: brandId, type: 'brands', name: brandName } },
      agency: { data: { id: AGENCY, type: 'agencies' } },
    },
  }
}

export const CAMPAIGNS = CAMPAIGN_ROWS.map((row, i) => campaignFixture(i, row))

// ── Campaign detail (Figma 16:38): the first campaign, starting a week from today ───────────

/** Local date `n` days from today as YYYY-MM-DD (the page counts days from the local date). */
export function daysFromToday(n) {
  const d = new Date()
  d.setDate(d.getDate() + n)
  const pad = (v) => String(v).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export function detailCampaign() {
  const c = campaignFixture(0, CAMPAIGN_ROWS[0])
  c.id = DETAIL_ID
  c.attributes.starts_at = `${daysFromToday(7)}T00:00:00Z`
  c.attributes.ends_at = `${daysFromToday(60)}T00:00:00Z`
  c.attributes.budget_minor_units = 150000
  c.attributes.description =
    'Short videos from business professionals in the US. Show a real working day, say what you ' +
    'use the product for, keep it under a minute.'
  return c
}

// ── Discover (Figma 17:52: two rows of four of 412) ───────────────────────────────────────

/** [name, country, age, language, categories, relationship_status] */
const CREATOR_ROWS = [
  ['Maya Thompson', 'US', 28, 'en', ['lifestyle', 'tech'], 'roster'],
  ['Daniel Okafor', 'US', 34, 'en', ['fitness', 'sports', 'travel'], 'roster'],
  ['Sofia Marchetti', 'IT', 26, 'it', ['fashion', 'beauty'], null],
  ['Lukas Weber', 'DE', 31, 'de', ['gaming', 'tech'], 'pending_request'],
  ['Inês Carvalho', 'PT', 24, 'pt', ['food', 'travel', 'lifestyle'], null],
  ['Emma Laurent', 'FR', 29, 'fr', ['beauty'], 'roster'],
  ['Mateo García', 'ES', 33, 'es', ['music', 'comedy'], null],
  ['Noor van Dijk', 'NL', 27, 'nl', ['design', 'art', 'home'], null],
]

export const DISCOVER_CREATORS = CREATOR_ROWS.map(
  ([name, country, age, language, categories, relationship], i) => ({
    id: `01HCREATOR000000000000000${String(i + 1).padStart(2, '0')}`,
    type: 'creator_discovery',
    attributes: {
      display_name: name,
      country_code: country,
      age,
      primary_language: language,
      accent: null,
      content_companions: null,
      categories,
      avatar_url: null,
      relationship_status: relationship,
    },
  }),
)

// ── Messages (Figma 18:71 / 21:85: Maya Thompson open) ────────────────────────────────────

/** An ISO time `days` days and `minutes` minutes before now (inbox dates are relative). */
function ago(days, minutes = 0) {
  return new Date(Date.now() - days * 86_400_000 - minutes * 60_000).toISOString()
}

const THREADS = [
  [
    THREAD_CREATOR,
    'Maya Thompson',
    ago(0, 25),
    'Perfect, I will film the hook variations tomorrow morning and send the raw footage',
    2,
  ],
  [
    '01HCREATORDANIEL000000002',
    'Daniel Okafor',
    ago(1, 40),
    'Sent over the raw footage, let me know if you need any retakes',
    1,
  ],
  ['01HCREATORPRIYA0000000003', 'Priya Shah', ago(9), 'Contract signed and returned', 0],
  ['01HCREATORLUKAS0000000004', 'Lukas Meyer', ago(40), 'Thanks for the update, speak soon', 0],
  ['01HCREATORAMARA0000000005', 'Amara Nwosu', ago(400), 'Happy to join the next campaign', 0],
]

function inboxRows() {
  return THREADS.map(([id, name, at, preview, unread]) => ({
    id: `thread-${id}`,
    type: 'relationship_thread',
    attributes: {
      creator: { id, display_name: name, avatar_url: null },
      last_message_at: at,
      last_message_preview: preview,
      unread_count: unread,
    },
  }))
}

function feedMessage(id, at, body, own) {
  return {
    id,
    type: 'relationship_message',
    attributes: {
      kind: 'text',
      sender_role: own ? 'agency_user' : 'creator',
      body,
      attachments: [],
      is_own: own,
      sender: { name: own ? 'Jordan' : 'Maya Thompson' },
      read_by_counterparty: own ? true : null,
      created_at: at,
    },
  }
}

function threadFeed() {
  return {
    data: [
      feedMessage(
        'm1',
        ago(1, 95),
        'Hi Maya, the brief is attached. Three hook variations, each under a minute.',
        true,
      ),
      feedMessage(
        'm2',
        ago(1, 70),
        'Amazing, thank you! Just read through it. Should I film all three hook variations in the same outfit or switch it up?',
        false,
      ),
      feedMessage(
        'm3',
        ago(1, 50),
        'Switch it up, a different look for each one works best.',
        true,
      ),
      feedMessage(
        'm4',
        ago(0, 25),
        'Perfect, I will film the hook variations tomorrow morning and send the raw footage by end of day.',
        false,
      ),
    ],
    meta: {
      thread: {
        id: 'thread-x',
        last_message_at: ago(0, 25),
        unread_count: 0,
        can_send: true,
        closed_reason: null,
      },
      has_more: false,
    },
  }
}

export function fixtureFor(url, method) {
  const p = url.pathname
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/relationship-threads`) {
    return { data: inboxRows() }
  }
  if (p === `/api/v1/agencies/${AGENCY}/creators/${THREAD_CREATOR}/relationship-messages/read`) {
    return { meta: { marked: 0, unread_count: 0 } }
  }
  if (
    method === 'GET' &&
    p === `/api/v1/agencies/${AGENCY}/creators/${THREAD_CREATOR}/relationship-messages`
  ) {
    return threadFeed()
  }
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/creators/discover`) {
    return {
      data: DISCOVER_CREATORS,
      meta: { total: 412, page: 1, per_page: 8, last_page: 52 },
    }
  }
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/campaigns/${DETAIL_ID}`) {
    return { data: detailCampaign() }
  }
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/campaigns/${DETAIL_ID}/applications`) {
    return {
      data: [],
      meta: { total: 3, page: 1, per_page: 1, last_page: 3, pending_total: 1 },
    }
  }
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/campaigns`) {
    return {
      data: CAMPAIGNS,
      // AH-116: `status_counts` sums to the 24 the fixture models; it ignores the status tab.
      meta: {
        total: 24,
        page: 1,
        per_page: 25,
        last_page: 1,
        status_counts: { draft: 4, active: 11, paused: 3, completed: 5, cancelled: 1 },
      },
    }
  }
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/brands`) {
    return {
      data: BRANDS.map(([id, name], i) =>
        brandResource(id, name, i === 8 ? 'archived' : 'active', i),
      ),
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        total: BRANDS.length,
        current_page: 1,
        from: 1,
        to: BRANDS.length,
        per_page: 25,
        last_page: 1,
        page: 1,
      },
    }
  }
  return l4FixtureFor(url, method)
}
