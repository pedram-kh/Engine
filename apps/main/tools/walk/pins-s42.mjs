/**
 * S4.2 pins: the four detail pages (creator on the roster, creator on Discover, brand, talent
 * pool), read off the REAL pages over the L4 fixtures in both agency themes. They check the rules
 * the pages adopted (R3 header with the 36px back button and the status pill, R6 detail frame,
 * R7 cards, R9 pills), not Figma pixels: no frame draws these pages.
 */

const STEP = 'S4.2'

const pin = (page, name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page,
  ...more,
})
const both = (id, name, sel, expect, more = {}) => [
  pin(`real:${id}-1600`, `${name} (1600)`, sel, expect, more),
  pin(`real:${id}-1280`, `${name} (1280)`, sel, expect, more),
]

const PAGES = [
  ['roster-detail', '[data-test="creator-detail-page"]', '[data-test="creator-detail-name"]'],
  [
    'discover-profile',
    '[data-test="discover-profile-page"]',
    '[data-test="discover-profile-name"]',
  ],
  ['brand-detail', '[data-test="brand-detail-page"]', '[data-test="brand-detail-heading"]'],
  ['pool-detail', '[data-test="pool-detail-page"]', '[data-test="pool-detail-name"]'],
]

/** Count of `.agency-card` and of leftover `.v-card` inside a page. */
const cardCount = (root) =>
  `return { agency: document.querySelectorAll('${root} .agency-card').length, vCard: document.querySelectorAll('${root} .v-card').length }`

const frame = ([id, root, heading]) => [
  ...both(id, `${id}: the page frame stacks its blocks 20 apart`, root, () => ({
    display: 'flex',
    flexDirection: 'column',
    rowGap: '20px',
  })),
  pin(`real:${id}-1600`, `${id}: title is Heading/H1 26/32/500`, heading, (c) => ({
    fontSize: '26px',
    lineHeight: '32px',
    fontWeight: '500',
    color: c.onSurface,
  })),
  pin(
    `real:${id}-1600`,
    `${id}: the back button is the 36px square with the default border`,
    `${root} .agency-icon-square`,
    () => ({ width: '36px', height: '36px', borderTopWidth: '1px', borderTopLeftRadius: '8px' }),
  ),
  pin(
    `real:${id}-1600`,
    `${id}: no tonal chip is left in the header`,
    `${root} .agency-page-header`,
    () => ({ tonal: 0 }),
    {
      fn: `return { tonal: document.querySelectorAll('${root} .agency-page-header .v-chip--variant-tonal, ${root} .agency-page-header .v-chip--variant-flat').length }`,
    },
  ),
]

export const S42_PINS = [
  ...PAGES.flatMap(frame),

  // ── R7: every section is an agency card; no Vuetify card is left ──────────────────────
  pin(
    'real:roster-detail-1600',
    'roster detail: ten agency cards, no v-card',
    '[data-test="creator-detail-page"]',
    () => ({ agency: 10, vCard: 0 }),
    { fn: cardCount('[data-test="creator-detail-page"]') },
  ),
  pin(
    'real:discover-profile-1600',
    'discover profile: four agency cards, no v-card',
    '[data-test="discover-profile-page"]',
    () => ({ agency: 4, vCard: 0 }),
    { fn: cardCount('[data-test="discover-profile-page"]') },
  ),
  pin(
    'real:brand-detail-1600',
    'brand detail: one agency card, no v-card',
    '[data-test="brand-detail-page"]',
    () => ({ agency: 1, vCard: 0 }),
    { fn: cardCount('[data-test="brand-detail-page"]') },
  ),
  pin(
    'real:pool-detail-1600',
    'pool detail: a description card and a members card, no v-card',
    '[data-test="pool-detail-page"]',
    () => ({ agency: 2, vCard: 0 }),
    { fn: cardCount('[data-test="pool-detail-page"]') },
  ),
  ...both(
    'roster-detail',
    'roster detail: a card is surface, 1px border, radius 12, padding 24',
    '[data-test="creator-detail-profile"]',
    (c) => ({
      backgroundColor: c.surface,
      borderTopWidth: '1px',
      borderTopLeftRadius: '12px',
      paddingTop: '24px',
      paddingLeft: '24px',
    }),
  ),
  pin(
    'real:roster-detail-1600',
    'roster detail: card title is 18/24/600',
    '[data-test="creator-detail-profile"] .agency-card__title',
    () => ({ fontSize: '18px', lineHeight: '24px', fontWeight: '600' }),
  ),

  // ── R6: the management rail is the 360 panel ──────────────────────────────────────────
  pin(
    'real:roster-detail-1600',
    'roster detail: the rail is 360 wide, 20 below its own cards',
    '.creator-detail__rail',
    () => ({ width: '360px', rowGap: '20px' }),
  ),

  // ── R9: pills ─────────────────────────────────────────────────────────────────────────
  pin(
    'real:roster-detail-1600',
    'roster detail: relationship and application are pills with the table tones',
    '[data-test="creator-detail-page"]',
    () => ({ relationship: 'success', application: 'success' }),
    {
      fn: `const tone = (t) => document.querySelector('[data-test="' + t + '"]')?.getAttribute('data-tone'); return { relationship: tone('creator-detail-relationship-status'), application: tone('creator-detail-application-status') }`,
    },
  ),
  pin(
    'real:discover-profile-1600',
    'discover profile: the connection is the discovery pill',
    '[data-test="discover-profile-page"]',
    () => ({ tone: 'success', text: 'Connected' }),
    {
      fn: `const e = document.querySelector('[data-test="discover-profile-connection-connected"]'); return { tone: e?.getAttribute('data-tone'), text: e?.textContent.trim() }`,
    },
  ),
  pin(
    'real:brand-detail-1600',
    'brand detail: the status is the brand pill',
    '[data-test="brand-detail-page"]',
    () => ({ tone: 'success' }),
    {
      fn: `return { tone: document.querySelector('[data-test="brand-detail-status"]')?.getAttribute('data-tone') }`,
    },
  ),

  // ── Fields: label over value on the brand card ────────────────────────────────────────
  pin(
    'real:brand-detail-1600',
    'brand detail: field label is the Overline (11/14/600, uppercase)',
    '[data-test="brand-detail-industry"] .agency-field__label',
    () => ({
      fontSize: '11px',
      lineHeight: '14px',
      fontWeight: '600',
      textTransform: 'uppercase',
    }),
  ),
  pin(
    'real:pool-detail-1600',
    'pool detail: member avatars start at the card padding, not 16 further in',
    '[data-test="pool-members-card"]',
    () => ({ inset: 0 }),
    {
      fn: `const card = document.querySelector('[data-test="pool-members-card"]'); const row = card.querySelector('.v-list-item .v-avatar'); const pad = parseFloat(getComputedStyle(card).paddingLeft); return { inset: Math.round(row.getBoundingClientRect().left - card.getBoundingClientRect().left - pad - 1) }`,
    },
  ),
]
