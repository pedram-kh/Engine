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

export const REAL_PAGES = {
  'campaigns-1600': { to: '/campaigns', names: 'campaigns.list,campaigns.create', width: 1600 },
  'campaigns-1280': { to: '/campaigns', names: 'campaigns.list,campaigns.create', width: 1280 },
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

export function fixtureFor(url, method) {
  const p = url.pathname
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/campaigns`) {
    return {
      data: CAMPAIGNS,
      meta: { total: 24, page: 1, per_page: 25, last_page: 1 },
    }
  }
  if (method === 'GET' && p === `/api/v1/agencies/${AGENCY}/brands`) {
    return {
      data: BRANDS.map(([id, name]) => ({ id, type: 'brands', attributes: { name } })),
    }
  }
  return null
}
