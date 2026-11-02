/**
 * S3.3 pins: the Discover page, read off the REAL `DiscoverPage` over the L3 fixtures
 * (`l3-fixtures.mjs`: eight of 412 creators) in both agency themes, at 1600 and at 1280.
 * Values are the Figma numbers (Discover frame 17:52); colours come from pins-colours.mjs.
 */

const STEP = 'S3.3'
const WIDE = 'real:discover-1600'
const NARROW = 'real:discover-1280'

const pin = (page, name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page,
  ...more,
})
/** The same pin on both widths. */
const both = (name, sel, expect, more = {}) => [
  pin(WIDE, `${name} (1600)`, sel, expect, more),
  pin(NARROW, `${name} (1280)`, sel, expect, more),
]

const root = '[data-test="discover-page"]'
const grid = '[data-test="discover-grid"]'
const first = '[data-test="discover-card-01HCREATOR00000000000000001"]'
const second = '[data-test="discover-card-01HCREATOR00000000000000002"]'
const third = '[data-test="discover-card-01HCREATOR00000000000000003"]'
const pill = (name) => `[data-test="discover-${name}-filter"]`

export const S33_PINS = [
  // ── header ────────────────────────────────────────────────────────────────────────────
  pin(WIDE, 'title: Heading/H1 26/32/500', '[data-test="discover-heading"]', (c) => ({
    fontSize: '26px',
    lineHeight: '32px',
    fontWeight: '500',
    color: c.onSurface,
  })),
  pin(
    WIDE,
    'subtitle: Body 14/20, secondary (the Discover frame is one size up from the lists)',
    '.agency-page-header__subtitle',
    (c) => ({ fontSize: '14px', lineHeight: '20px', fontWeight: '400', color: c.onSurfaceVariant }),
  ),
  pin(WIDE, 'blocks stacked 20 apart', root, () => ({ rowGap: '20px', display: 'flex' })),
  pin(
    WIDE,
    'header has no actions (Saved searches and Add to talent pool have nothing behind them)',
    '[data-test="agency-page-header"]',
    () => ({ actions: 0 }),
    { fn: `return { actions: document.querySelectorAll('.agency-page-header__actions').length }` },
  ),

  // ── search ────────────────────────────────────────────────────────────────────────────
  ...both(
    'search is full width and 40 high',
    '[data-test="discover-search"] .v-field',
    () => ({ height: 40, fullWidth: true }),
    {
      fn: `const f = document.querySelector('[data-test="discover-search"] .v-field').getBoundingClientRect(); const g = document.querySelector('[data-test="discover-grid"]').getBoundingClientRect(); return { height: Math.round(f.height), fullWidth: Math.abs(f.width - g.width) < 1 }`,
    },
  ),
  pin(
    WIDE,
    'search: canvas fill, 1px border, radius 8',
    '[data-test="discover-search"] .v-field',
    (c) => ({
      backgroundColor: c.canvas,
      borderTopLeftRadius: '8px',
    }),
  ),
  pin(
    WIDE,
    'search outline is the default border colour',
    '[data-test="discover-search"] .v-field__outline',
    (c) => ({ color: c.border }),
  ),
  pin(WIDE, 'search text: 14/20', '[data-test="discover-search"] input', (c) => ({
    fontSize: '14px',
    lineHeight: '20px',
    color: c.onSurface,
  })),
  pin(
    WIDE,
    'search placeholder is tertiary',
    '[data-test="discover-search"] input',
    (c) => ({ color: c.tertiary }),
    {
      fn: `return { color: getComputedStyle(document.querySelector('[data-test="discover-search"] input'), '::placeholder').color }`,
    },
  ),
  pin(
    WIDE,
    'search icon: 16',
    '[data-test="discover-search"] .v-field__prepend-inner .v-icon',
    () => ({
      fontSize: '16px',
    }),
  ),
  pin(
    WIDE,
    'search input is 16 from its left edge',
    '[data-test="discover-search"]',
    () => ({ inset: 16 }),
    {
      fn: `const f = document.querySelector('[data-test="discover-search"] .v-field').getBoundingClientRect(); const i = document.querySelector('[data-test="discover-search"] .v-field__prepend-inner .v-icon').getBoundingClientRect(); return { inset: Math.round(i.left - f.left) }`,
    },
  ),

  // ── filter pills ──────────────────────────────────────────────────────────────────────
  pin(
    WIDE,
    'pill: surface fill, 1px default border, full radius, secondary text',
    pill('country'),
    (c) => ({
      backgroundColor: c.surface,
      borderTopWidth: '1px',
      borderTopColor: c.border,
      borderTopLeftRadius: '999px',
      color: c.onSurfaceVariant,
    }),
  ),
  pin(
    WIDE,
    'pill label: 10.5/14/700, +0.4, uppercase; 12 and 8 padding',
    `${pill('country')} button`,
    () => ({
      fontSize: '10.5px',
      lineHeight: '14px',
      fontWeight: '700',
      letterSpacing: '0.4px',
      textTransform: 'uppercase',
      paddingTop: '8px',
      paddingLeft: '12px',
      paddingRight: '12px',
    }),
  ),
  ...both('pills are 8 apart', pill('country'), () => ({ gap: 8 }), {
    fn: `const a = document.querySelector('[data-test="discover-country-filter"]').getBoundingClientRect(); const b = document.querySelector('[data-test="discover-language-filter"]').getBoundingClientRect(); return { gap: Math.round(b.left - a.right) }`,
  }),
  pin(
    WIDE,
    'pill height 32 (14 line + 8 + 8 + borders)',
    pill('language'),
    () => ({ height: 32 }),
    {
      fn: `return { height: Math.round(document.querySelector('[data-test="discover-language-filter"]').getBoundingClientRect().height) }`,
    },
  ),
  pin(
    WIDE,
    'a pill holding a value: container fill, accent border, accent text, reads "Country: …", closes with its button',
    pill('country'),
    (c) => ({
      fill: c.primaryContainer,
      border: c.primary,
      text: c.primaryText,
      reads: true,
      cleared: 'COUNTRY',
    }),
    {
      fn: `const root = document.querySelector('[data-test="discover-country-filter"]'); root.querySelector('button').click(); return new Promise((resolve) => setTimeout(() => { const opt = document.querySelector('[data-test="agency-filter-pill-option"]'); opt.click(); setTimeout(() => { const r = document.querySelector('[data-test="discover-country-filter"]'); const out = { fill: getComputedStyle(r).backgroundColor, border: getComputedStyle(r).borderTopColor, text: getComputedStyle(r).color, reads: /^COUNTRY: ./i.test(r.querySelector('button').textContent.trim()) }; r.querySelector('[data-test="agency-filter-pill-clear"]').click(); setTimeout(() => { out.cleared = document.querySelector('[data-test="discover-country-filter"] button').textContent.trim().toUpperCase(); resolve(out) }, 400) }, 400) }, 400))`,
    },
  ),
  pin(
    WIDE,
    'result count: Body small 13/19, tertiary, "412 creators"',
    '[data-test="discover-result-count"]',
    (c) => ({ fontSize: '13px', lineHeight: '19px', color: c.tertiary, text: '412 creators' }),
    {
      fn: `const e = document.querySelector('[data-test="discover-result-count"]'); const s = getComputedStyle(e); return { fontSize: s.fontSize, lineHeight: s.lineHeight, color: s.color, text: e.textContent.trim() }`,
    },
  ),
  pin(
    WIDE,
    'the count ends at the grid edge',
    '[data-test="discover-result-count"]',
    () => ({ flush: true }),
    {
      fn: `const c = document.querySelector('[data-test="discover-result-count"]').getBoundingClientRect(); const g = document.querySelector('[data-test="discover-grid"]').getBoundingClientRect(); return { flush: Math.abs(c.right - g.right) < 0.5 }`,
    },
  ),
  pin(WIDE, 'no grid/list toggle, no gender, age or portfolio pills', root, () => ({ pills: 3 }), {
    fn: `return { pills: document.querySelectorAll('.agency-filter-pill').length }`,
  }),

  // ── grid ──────────────────────────────────────────────────────────────────────────────
  ...both(
    'four columns, 20 apart',
    grid,
    () => ({ columns: 4, columnGap: '20px', rowGap: '20px' }),
    {
      fn: `const s = getComputedStyle(document.querySelector('[data-test="discover-grid"]')); return { columns: s.gridTemplateColumns.split(' ').length, columnGap: s.columnGap, rowGap: s.rowGap }`,
    },
  ),
  ...both('eight equal cards in two rows of four', grid, () => ({ cards: 8, widths: 1, rows: 2 }), {
    fn: `const cs = [...document.querySelectorAll('[data-test="discover-grid"] > article')]; return { cards: cs.length, widths: new Set(cs.map((c) => Math.round(c.getBoundingClientRect().width))).size, rows: new Set(cs.map((c) => Math.round(c.getBoundingClientRect().top))).size }`,
  }),
  pin(WIDE, 'cards in a row are the same height', grid, () => ({ heights: 1 }), {
    fn: `const cs = [...document.querySelectorAll('[data-test="discover-grid"] > article')].slice(0, 4); return { heights: new Set(cs.map((c) => Math.round(c.getBoundingClientRect().height))).size }`,
  }),

  // ── card ──────────────────────────────────────────────────────────────────────────────
  pin(WIDE, 'card: surface, 1px border, radius 12', first, (c) => ({
    backgroundColor: c.surface,
    borderTopWidth: '1px',
    borderTopColor: c.border,
    borderTopLeftRadius: '12px',
    cursor: 'pointer',
  })),
  pin(WIDE, 'cover is 190 high', `${first} .agency-creator-card__cover`, () => ({
    height: '190px',
  })),
  pin(
    WIDE,
    'cover: a gradient from the status colour (no flat grey)',
    `${first} .agency-creator-card__cover`,
    () => ({ gradient: true }),
    {
      fn: `const s = getComputedStyle(document.querySelector('[data-test="discover-card-01HCREATOR00000000000000001"] .agency-creator-card__cover')); return { gradient: /^linear-gradient\\(/.test(s.backgroundImage) && /147deg|147\\.0*\\d*deg/.test(s.backgroundImage) }`,
    },
  ),
  pin(
    WIDE,
    'cover initial: 28/36/500 at 35%, 20 from the left, 110 from the top',
    `${first} .agency-creator-card__initial`,
    (c) => ({
      fontSize: '28px',
      lineHeight: '36px',
      fontWeight: '500',
      opacity: '0.35',
      color: c.onSurface,
      left: 20,
      top: 110,
    }),
    {
      fn: `const e = document.querySelector('[data-test="discover-card-01HCREATOR00000000000000001"] .agency-creator-card__initial'); const s = getComputedStyle(e); const p = e.parentElement.getBoundingClientRect(); const r = e.getBoundingClientRect(); return { fontSize: s.fontSize, lineHeight: s.lineHeight, fontWeight: s.fontWeight, opacity: s.opacity, color: s.color, left: Math.round(r.left - p.left), top: Math.round(r.top - p.top) }`,
    },
  ),
  pin(WIDE, 'body: 16 padding, 12 between lines', `${first} .agency-creator-card__body`, () => ({
    paddingTop: '16px',
    paddingLeft: '16px',
    rowGap: '12px',
  })),
  pin(WIDE, 'name: 14/20/500', `${first} [data-test^="discover-name-"]`, (c) => ({
    fontSize: '14px',
    lineHeight: '20px',
    fontWeight: '500',
    color: c.onSurface,
  })),
  pin(
    WIDE,
    'connected pill: the status pill, success tone, at the end of the name row',
    `${first} [data-test^="discover-connection-"]`,
    (c) => ({ color: c.pills.success.text, text: 'Connected', end: true, dot: true }),
    {
      fn: `const card = document.querySelector('[data-test="discover-card-01HCREATOR00000000000000001"]'); const p = card.querySelector('[data-test^="discover-connection-"]'); const row = card.querySelector('.agency-creator-card__namerow').getBoundingClientRect(); return { color: getComputedStyle(p).color, text: p.textContent.trim(), end: Math.abs(p.getBoundingClientRect().right - row.right) < 0.5, dot: !!p.querySelector('.agency-status-pill__dot') }`,
    },
  ),
  pin(
    WIDE,
    'no connection: "Not connected", 12/16, tertiary, no pill',
    `${third} [data-test^="discover-notconnected-"]`,
    (c) => ({ fontSize: '12px', lineHeight: '16px', color: c.tertiary, pills: 0 }),
    {
      fn: `const card = document.querySelector('[data-test="discover-card-01HCREATOR00000000000000003"]'); const e = card.querySelector('[data-test^="discover-notconnected-"]'); const s = getComputedStyle(e); return { fontSize: s.fontSize, lineHeight: s.lineHeight, color: s.color, pills: card.querySelectorAll('.agency-status-pill').length }`,
    },
  ),
  pin(
    WIDE,
    'location line: 12/16 secondary, 8 after a 13px pin, "Country  ·  age"',
    `${first} .agency-creator-card__line`,
    (c) => ({ fontSize: '12px', lineHeight: '16px', color: c.onSurfaceVariant, columnGap: '8px' }),
  ),
  pin(
    WIDE,
    'location reads "United States · 28"',
    `${first} .agency-creator-card__line`,
    () => ({ text: 'United States · 28', icon: '13px' }),
    {
      fn: `const l = document.querySelector('[data-test="discover-card-01HCREATOR00000000000000001"] .agency-creator-card__line'); return { text: l.textContent.replace(/\\s+/g, ' ').trim(), icon: getComputedStyle(l.querySelector('.v-icon')).fontSize }`,
    },
  ),
  pin(
    WIDE,
    'chips: raised fill, full radius, 3 and 8 padding, 12/16/500 secondary, 8 apart',
    `${first} .agency-creator-card__chip`,
    (c) => ({
      backgroundColor: c.raised,
      borderTopLeftRadius: '999px',
      paddingTop: '3px',
      paddingLeft: '8px',
      fontSize: '12px',
      lineHeight: '16px',
      fontWeight: '500',
      color: c.onSurfaceVariant,
    }),
  ),
  pin(
    WIDE,
    'a third category collapses to "+1"',
    `${second} .agency-creator-card__chips`,
    () => ({ chips: 'Fitness|Sports|+1' }),
    {
      fn: `return { chips: [...document.querySelectorAll('[data-test="discover-card-01HCREATOR00000000000000002"] .agency-creator-card__chip')].map((e) => e.textContent.trim()).join('|') }`,
    },
  ),

  // ── footer ────────────────────────────────────────────────────────────────────────────
  pin(
    WIDE,
    'footer: "Showing 8 of 412", Previous off, Next on',
    '[data-test="discover-pagination"]',
    () => ({ text: 'Showing 8 of 412', previous: true, next: false }),
    {
      fn: `return { text: document.querySelector('[data-test="discover-showing"]').textContent.trim(), previous: document.querySelector('[data-test="discover-page-previous"]').disabled, next: document.querySelector('[data-test="discover-page-next"]').disabled }`,
    },
  ),

  // ── what the page does not draw (nothing behind it) ───────────────────────────────────
  pin(
    WIDE,
    'omitted: video count, heart, verified badge, Saved searches, Add to talent pool, Gender, Age, Has portfolio',
    root,
    () => ({ found: '', icons: 0 }),
    {
      fn: `const t = document.querySelector('[data-test="discover-page"]').innerText; return { found: ['videos', 'Saved searches', 'Add to talent pool', 'Gender', 'Has portfolio'].filter((s) => t.includes(s)).join('|'), icons: document.querySelectorAll('[data-test="discover-page"] .mdi-heart, [data-test="discover-page"] .mdi-check-decagram, [data-test="discover-page"] .mdi-play').length }`,
    },
  ),
]
