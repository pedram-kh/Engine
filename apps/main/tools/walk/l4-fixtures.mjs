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
const BRAND_ID = '01HBRANDJACKJILL0000000001'

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
  'roster-detail-1600': {
    to: `/roster/${ROSTER_FIRST}`,
    names: 'roster.detail',
    width: 1600,
    height: 1000,
  },
  'roster-detail-1280': {
    to: `/roster/${ROSTER_FIRST}`,
    names: 'roster.detail',
    width: 1280,
    height: 900,
  },
  'discover-profile-1600': {
    to: `/discover/${ROSTER_FIRST}`,
    names: 'discover.detail',
    width: 1600,
    height: 1000,
  },
  'discover-profile-1280': {
    to: `/discover/${ROSTER_FIRST}`,
    names: 'discover.detail',
    width: 1280,
    height: 900,
  },
  'brand-detail-1600': {
    to: `/brands/${BRAND_ID}`,
    names: 'brands.detail',
    width: 1600,
    height: 900,
  },
  'brand-detail-1280': {
    to: `/brands/${BRAND_ID}`,
    names: 'brands.detail',
    width: 1280,
    height: 800,
  },
  'pool-detail-1600': {
    to: `/talent-pools/${POOL_ID}`,
    names: 'pools.detail',
    width: 1600,
    height: 1000,
  },
  'brand-create-1600': { to: '/brands/new', names: 'brands.create', width: 1600, height: 1000 },
  'brand-edit-1600': {
    to: `/brands/${BRAND_ID}/edit`,
    names: 'brands.edit',
    width: 1600,
    height: 1000,
  },
  'campaign-create-1600': {
    to: '/campaigns/new',
    names: 'campaigns.create',
    width: 1600,
    height: 1000,
  },
  'pool-create-1600': { to: '/talent-pools/new', names: 'pools.create', width: 1600, height: 900 },
  'pool-edit-1600': {
    to: `/talent-pools/${POOL_ID}/edit`,
    names: 'pools.edit',
    width: 1600,
    height: 900,
  },
  'settings-1600': { to: '/settings', names: 'settings', width: 1600, height: 900 },
  'settings-1280': { to: '/settings', names: 'settings', width: 1280, height: 800 },
  'bulk-invite-1600': {
    to: '/creator-invitations/bulk',
    names: 'creator-invitations.bulk',
    width: 1600,
    height: 900,
  },
  'dashboard-1600': { to: '/', names: 'app.dashboard', width: 1600, height: 900 },
  'dashboard-1280': { to: '/', names: 'app.dashboard', width: 1280, height: 800 },
  'pool-detail-1280': {
    to: `/talent-pools/${POOL_ID}`,
    names: 'pools.detail',
    width: 1280,
    height: 900,
  },
}

/** One creator's detail (the agency roster view). */
function creatorDetail() {
  return {
    id: '01HRELATION000000000000001',
    type: 'agency_creator_details',
    attributes: {
      relationship_status: 'roster',
      internal_rating: 4,
      internal_notes: 'Reliable and fast. Delivers drafts a day early.',
      total_campaigns_completed: 12,
      total_paid_minor_units: 1_080_000,
      last_engaged_at: ago(1),
      is_blacklisted: false,
      blacklist_scope: null,
      blacklist_type: null,
      blacklisted_at: null,
      creator: {
        id: ROSTER_FIRST,
        display_name: 'Maya Thompson',
        bio: 'Lifestyle and tech creator based in Austin. Short videos about daily routines and gadgets.',
        email: 'maya@example.test',
        account_name: 'Maya',
        account_last_name: 'Thompson',
        country_code: 'US',
        region: null,
        age: 28,
        primary_language: 'en',
        secondary_languages: ['es'],
        accent: null,
        content_companions: null,
        categories: ['lifestyle', 'tech'],
        avatar_url: null,
        cover_url: null,
        application_status: 'approved',
        social_accounts: [],
        portfolio: [],
      },
    },
  }
}

/** The public profile of the same creator (the Discover view). */
function creatorProfile() {
  const d = creatorDetail().attributes.creator
  return {
    id: ROSTER_FIRST,
    type: 'creator_discovery',
    attributes: {
      display_name: d.display_name,
      bio: d.bio,
      country_code: d.country_code,
      age: d.age,
      primary_language: d.primary_language,
      secondary_languages: d.secondary_languages,
      accent: null,
      content_companions: null,
      categories: d.categories,
      avatar_url: null,
      cover_url: null,
      profile_completeness_score: 86,
      relationship_status: 'roster',
      social_accounts: [],
      portfolio: [],
    },
  }
}

const MEMBER_ROWS = [
  ['Maya Thompson', 'US', false],
  ['Daniel Okafor', 'GB', false],
  ['Sofia Marchetti', 'IT', true],
  ['Emma Laurent', 'FR', false],
]

function poolMembers() {
  return MEMBER_ROWS.map(([name, country, blacklisted], i) => ({
    id: `01HPOOLMEMBER00000000000${i + 1}`,
    type: 'talent_pool_members',
    attributes: {
      display_name: name,
      country_code: country,
      primary_language: 'en',
      categories: ['lifestyle'],
      avatar_url: null,
      application_status: 'approved',
      is_blacklisted: blacklisted,
      blacklist_type: blacklisted ? 'soft' : null,
      added_at: ago(5 + i),
    },
  }))
}

export function l4FixtureFor(url, method) {
  const p = url.pathname
  if (method !== 'GET') return null
  if (p === `${API}/dashboard/summary`) {
    return {
      data: {
        creators_in_roster: 142,
        pending_creator_applications: 7,
        active_campaigns: null,
        payments_due: null,
      },
    }
  }
  if (p === `${API}/dashboard/activity`) {
    const rows = [
      ['creator.invited', 'Maya Thompson', 0, {}],
      ['brand.created', 'Maya Thompson', 1, {}],
      ['bulk_invite.completed', 'Daniel Okafor', 2, { invited: 24, failed: 1 }],
      ['agency_creator_relation.created', 'Maya Thompson', 4, {}],
      ['invitation.accepted', 'Sofia Marchetti', 6, {}],
    ]
    return {
      data: rows.map(([action, actor, days, metadata], i) => ({
        id: `01HACTIVITY00000000000000${i}`,
        action,
        actor_label: actor,
        created_at: ago(days + 0.1),
        metadata,
      })),
    }
  }
  if (p === `${API}/settings`) {
    return {
      data: {
        id: AGENCY,
        type: 'agency_settings',
        attributes: {
          default_currency: 'EUR',
          default_language: 'en',
          blacklist_notification_policy: false,
        },
      },
    }
  }
  if (p === `${API}/creators/discover/${ROSTER_FIRST}`) return { data: creatorProfile() }
  if (p === `${API}/creators/${ROSTER_FIRST}`) return { data: creatorDetail() }
  if (p === `${API}/brands/${BRAND_ID}`) return { data: brandResource(BRAND_ID, 'Jack & Jill') }
  if (p === `${API}/talent-pools/${POOL_ID}`) return { data: poolResource(POOLS[0], 0) }
  if (p === `${API}/talent-pools/${POOL_ID}/creators`) {
    return { data: poolMembers(), links, meta: pager(MEMBER_ROWS.length) }
  }
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
