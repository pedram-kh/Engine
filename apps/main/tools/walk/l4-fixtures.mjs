/**
 * AH-114 L4 fixtures: mock API answers and `REAL_PAGES` entries for the inferred agency pages
 * (lists, details, forms, settings, dashboard, notifications). `l3-fixtures.mjs` merges these
 * into its own `REAL_PAGES` / `fixtureFor`, so `shots-l3.mjs` and `pins.mjs` serve them.
 * Fixture text avoids the vocabulary the chunk bans. Dev tool only.
 */

export const AGENCY = '01HWALKAGENCY000000000000'
const API = `/api/v1/agencies/${AGENCY}`

const pager = (total, perPage = 25) => ({
  total,
  page: 1,
  current_page: 1,
  per_page: perPage,
  last_page: Math.max(1, Math.ceil(total / perPage)),
  from: total === 0 ? null : 1,
  to: Math.min(total, perPage),
})
const links = { first: null, last: null, prev: null, next: null }

/** An ISO time `days` days before now. */
const ago = (days) => new Date(Date.now() - days * 86_400_000).toISOString()

// ── Roster ────────────────────────────────────────────────────────────────────────────────
const ROSTER = [
  [
    'Maya Thompson',
    'maya@example.test',
    'roster',
    'approved',
    'US',
    28,
    'en',
    ['lifestyle', 'tech'],
    5,
    12,
  ],
  [
    'Daniel Okafor',
    'daniel@example.test',
    'roster',
    'approved',
    'GB',
    34,
    'en',
    ['fitness', 'sports'],
    4,
    8,
  ],
  [
    'Sofia Marchetti',
    'sofia@example.test',
    'prospect',
    'pending',
    'IT',
    26,
    'it',
    ['fashion', 'beauty'],
    null,
    0,
  ],
  [
    'Lukas Weber',
    'lukas@example.test',
    'pending_request',
    'approved',
    'DE',
    31,
    'de',
    ['gaming'],
    null,
    0,
  ],
  [
    'Ines Carvalho',
    'ines@example.test',
    'roster',
    'approved',
    'PT',
    24,
    'pt',
    ['food', 'travel'],
    3,
    5,
  ],
  [
    'Emma Laurent',
    'emma@example.test',
    'external',
    'incomplete',
    'FR',
    29,
    'fr',
    ['beauty'],
    null,
    1,
  ],
  [
    'Mateo Garcia',
    'mateo@example.test',
    'roster',
    'rejected',
    'ES',
    33,
    'es',
    ['music', 'comedy'],
    2,
    3,
  ],
  [
    'Noor van Dijk',
    'noor@example.test',
    'declined',
    'approved',
    'NL',
    27,
    'nl',
    ['design', 'art'],
    null,
    0,
  ],
  ['Amara Nwosu', 'amara@example.test', 'ended', 'approved', 'GB', 30, 'en', ['lifestyle'], 4, 14],
]

export const ROSTER_FIRST = '01HCREATORMAYA00000000001'

function rosterRows() {
  return ROSTER.map(([name, email, rel, app, country, age, lang, cats, rating, done], i) => ({
    id: `01HRELATION000000000000${String(i + 1).padStart(3, '0')}`,
    type: 'agency_creator_relations',
    attributes: {
      relationship_status: rel,
      is_blacklisted: false,
      blacklist_type: null,
      internal_rating: rating,
      total_campaigns_completed: done,
      total_paid_minor_units: done * 90_000,
      last_engaged_at: ago(i + 1),
      creator_id: i === 0 ? ROSTER_FIRST : `01HCREATOR${String(i + 1).padStart(15, '0')}`,
      display_name: name,
      email,
      application_status: app,
      country_code: country,
      age,
      primary_language: lang,
      accent: null,
      categories: cats,
    },
  }))
}

// ── Brands ────────────────────────────────────────────────────────────────────────────────
export function brandResource(id, name, status = 'active', i = 0) {
  return {
    id,
    type: 'brands',
    attributes: {
      name,
      slug: name.toLowerCase().replace(/[^a-z]+/g, '-'),
      description: 'Short videos from business professionals. Keep it real and under a minute.',
      industry: ['Software', 'Beauty', 'Food', 'Travel'][i % 4],
      website_url: `https://${name.toLowerCase().replace(/[^a-z]+/g, '')}.example`,
      logo_path: null,
      logo_url: null,
      default_currency: 'EUR',
      default_language: 'en',
      status,
      brand_safety_rules: null,
      client_portal_enabled: false,
      created_at: ago(30 + i * 9),
      updated_at: ago(2 + i),
    },
    relationships: { agency: { data: { id: AGENCY, type: 'agencies' } } },
  }
}

// ── Pools ─────────────────────────────────────────────────────────────────────────────────
const POOLS = [
  [
    '01HPOOLSUMMER0000000000001',
    'Summer launch shortlist',
    'A short list for the summer push.',
    'Jack & Jill',
    12,
  ],
  [
    '01HPOOLFOOD000000000000002',
    'Food and travel',
    'Creators who cover food and travel.',
    'Bolt Food',
    8,
  ],
  ['01HPOOLBEAUTY0000000000003', 'Beauty experts', null, null, 5],
  ['01HPOOLGAMING0000000000004', 'Gaming streamers', 'Streamers for gaming launches.', 'Cleo', 3],
]
export const POOL_ID = POOLS[0][0]

function poolResource([id, name, description, brandName, count], i) {
  return {
    id,
    type: 'talent_pools',
    attributes: {
      name,
      description,
      brand_id: brandName ? `01HBRANDPOOL00000000000${i}` : null,
      brand_name: brandName,
      is_archived: false,
      creators_count: count,
      created_at: ago(40 - i * 5),
      updated_at: ago(3 + i),
    },
  }
}

// ── Files ─────────────────────────────────────────────────────────────────────────────────
const FILES = [
  ['Campaign brief.pdf', 'application/pdf', 1_240_000, 'brief'],
  ['Brand guidelines.pdf', 'application/pdf', 3_480_000, 'brief'],
  ['Usage rights template.docx', 'application/msword', 84_000, 'contract'],
  ['Hook variations.mp4', 'video/mp4', 48_200_000, 'reference'],
  ['Moodboard.png', 'image/png', 2_300_000, 'reference'],
  ['Creator list.csv', 'text/csv', 12_000, 'other'],
]

function fileRows() {
  return FILES.map(([name, mime, size, category], i) => ({
    id: `01HFILE00000000000000000${i + 1}`,
    type: 'agency_files',
    attributes: {
      original_filename: name,
      mime_type: mime,
      size_bytes: size,
      category,
      download_url: 'https://files.example/x',
      created_at: ago(i * 3 + 1),
    },
    relationships: {
      uploaded_by: {
        data: {
          id: '01HUSER',
          type: 'users',
          attributes: { name: i % 2 ? 'Jordan Lee' : 'Maya Rivera' },
        },
      },
    },
  }))
}

// ── Team ──────────────────────────────────────────────────────────────────────────────────
const MEMBERS = [
  ['Maya Rivera', 'maya@northwind.example', 'agency_admin', 'active'],
  ['Jordan Lee', 'jordan@northwind.example', 'agency_manager', 'active'],
  ['Sam Patel', 'sam@northwind.example', 'agency_staff', 'active'],
  ['Alex Chen', 'alex@northwind.example', 'agency_staff', 'pending'],
]
const INVITES = [
  ['alex@northwind.example', 'agency_staff', 'pending'],
  ['sam@northwind.example', 'agency_staff', 'accepted'],
  ['old@northwind.example', 'agency_manager', 'expired'],
]

function memberRows() {
  return MEMBERS.map(([name, email, role, status], i) => ({
    id: `01HMEMBER00000000000000${i + 1}`,
    type: 'agency_memberships',
    attributes: {
      user_id: `01HUSER0000000000000000${i + 1}`,
      name,
      email,
      role,
      status,
      created_at: ago(60 - i * 10),
      last_active_at: ago(i),
    },
  }))
}

function inviteRows() {
  return INVITES.map(([email, role, status], i) => ({
    id: `01HINVITE0000000000000${i + 1}`,
    type: 'agency_invitations',
    attributes: {
      email,
      role,
      expires_at: ago(-7),
      accepted_at: status === 'accepted' ? ago(4) : null,
      is_pending: status === 'pending',
      is_expired: status === 'expired',
      created_at: ago(10 + i),
      status,
      invited_at: ago(10 + i),
      invited_by_user_name: 'Maya Rivera',
    },
    relationships: { agency: { data: { id: AGENCY, type: 'agencies' } } },
  }))
}

export const L4_REAL_PAGES = {
  'roster-1600': { to: '/roster', names: 'roster.list', width: 1600, height: 900 },
  'roster-1280': { to: '/roster', names: 'roster.list', width: 1280, height: 800 },
  'brands-1600': { to: '/brands', names: 'brands.list', width: 1600, height: 900 },
  'brands-1280': { to: '/brands', names: 'brands.list', width: 1280, height: 800 },
  'pools-1600': { to: '/talent-pools', names: 'pools.list', width: 1600, height: 900 },
  'pools-1280': { to: '/talent-pools', names: 'pools.list', width: 1280, height: 800 },
  'files-1600': { to: '/files', names: 'files.list', width: 1600, height: 900 },
  'files-1280': { to: '/files', names: 'files.list', width: 1280, height: 800 },
  'team-1600': { to: '/agency-users', names: 'agency-users.list', width: 1600, height: 1000 },
  'team-1280': { to: '/agency-users', names: 'agency-users.list', width: 1280, height: 900 },
}

export function l4FixtureFor(url, method) {
  const p = url.pathname
  if (method !== 'GET') return null
  if (p === `${API}/creators`) {
    const rows = rosterRows()
    return { data: rows, meta: { total: 142, page: 1, per_page: 25, last_page: 6 } }
  }
  if (p === `${API}/talent-pools`) {
    return { data: POOLS.map(poolResource), links, meta: pager(POOLS.length) }
  }
  if (p === `${API}/files`) {
    const rows = fileRows()
    return { data: rows, meta: { total: rows.length, page: 1, per_page: 25, last_page: 1 } }
  }
  if (p === `${API}/members`) {
    return { data: memberRows(), links, meta: pager(MEMBERS.length) }
  }
  if (p === `${API}/invitations`) {
    return { data: inviteRows(), links, meta: pager(INVITES.length) }
  }
  return null
}
