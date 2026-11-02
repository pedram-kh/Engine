/**
 * S3.2 pins: the Campaign detail page, read off the REAL `CampaignDetailPage` over the L3
 * fixtures (`l3-fixtures.mjs`: one active campaign starting in a week, one pending
 * application) in both agency themes, at 1600 and at 1280. Values are the Figma numbers
 * (Campaign detail frame 16:38); colours come from pins-colours.mjs.
 */

const STEP = 'S3.2'
const WIDE = 'real:campaign-detail-1600'
const NARROW = 'real:campaign-detail-1280'

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

const root = '[data-test="campaign-detail-page"]'
const tab = (name) => `[data-test="tab-${name}"]`
const card = '[data-test="overview-details-card"]'
const reqCard = '[data-test="overview-requirements-card"]'
const glance = '[data-test="overview-glance"]'

export const S32_PINS = [
  // ── header ────────────────────────────────────────────────────────────────────────────
  pin(WIDE, 'title: Heading/H1 26/32/500', '[data-test="campaign-detail-heading"]', (c) => ({
    fontSize: '26px',
    lineHeight: '32px',
    fontWeight: '500',
    color: c.onSurface,
  })),
  pin(
    WIDE,
    'subline: body small 13/19, secondary',
    '[data-test="campaign-detail-subtitle"]',
    (c) => ({
      fontSize: '13px',
      lineHeight: '19px',
      fontWeight: '400',
      color: c.onSurfaceVariant,
    }),
  ),
  pin(
    WIDE,
    'subline reads "Brand · Starts <date>" and nothing about who created it',
    '[data-test="campaign-detail-subtitle"]',
    () => ({ ok: true }),
    {
      fn: `const t = document.querySelector('[data-test="campaign-detail-subtitle"]').textContent.replace(/\\s+/g, ' ').trim(); return { ok: /^Jack & Jill · Starts [A-Z][a-z]{2} \\d{1,2}, \\d{4}$/.test(t) }`,
    },
  ),
  pin(
    WIDE,
    'back button: 36 square, surface, 1px border, radius 8',
    '[data-test="campaign-detail-back"]',
    (c) => ({
      width: '36px',
      height: '36px',
      backgroundColor: c.surface,
      borderTopWidth: '1px',
      borderTopColor: c.border,
      borderTopLeftRadius: '8px',
    }),
  ),
  pin(
    WIDE,
    'back button sits 16 before the title block',
    '[data-test="campaign-detail-back"]',
    () => ({ gap: 16 }),
    {
      fn: `const b = document.querySelector('[data-test="campaign-detail-back"]').getBoundingClientRect(); const h = document.querySelector('[data-test="campaign-detail-heading"]').getBoundingClientRect(); return { gap: Math.round(h.left - b.right) }`,
    },
  ),
  pin(
    WIDE,
    'status pill sits 12 after the title, centred on it',
    '[data-test="campaign-detail-status"]',
    () => ({ gap: 12, centred: true }),
    {
      fn: `const p = document.querySelector('[data-test="campaign-detail-status"]').getBoundingClientRect(); const h = document.querySelector('[data-test="campaign-detail-heading"]'); const r = document.createRange(); r.selectNodeContents(h); const t = r.getBoundingClientRect(); return { gap: Math.round(p.left - t.right), centred: Math.abs((p.top + p.height / 2) - (t.top + t.height / 2)) <= 1 }`,
    },
  ),
  pin(
    WIDE,
    'primary button: accent fill, dark label',
    '[data-test="campaign-detail-invite"]',
    (c) => ({
      backgroundColor: c.primary,
      color: c.onPrimary,
      borderTopLeftRadius: '8px',
    }),
  ),
  ...both('the primary button ends flush with the content column', root, () => ({ flush: true }), {
    fn: `const b = document.querySelector('[data-test="campaign-detail-invite"]').getBoundingClientRect(); const t = document.querySelector('[data-test="campaign-tabs"]').getBoundingClientRect(); return { flush: Math.abs(b.right - t.right) < 0.5 }`,
  }),
  pin(WIDE, 'blocks stacked 20 apart', root, () => ({ rowGap: '20px', display: 'flex' })),

  // ── tabs ──────────────────────────────────────────────────────────────────────────────
  pin(
    WIDE,
    'tab bar: 40 high with a 1px default-border rule under it',
    '[data-test="campaign-tabs"]',
    (c) => ({
      height: '40px',
      borderBottomWidth: '1px',
      borderBottomColor: c.border,
    }),
  ),
  pin(
    WIDE,
    'tab selected: primary text, 500, 14/20, 8 above and 12 below, no side padding',
    tab('overview'),
    (c) => ({
      color: c.onSurface,
      fontWeight: '500',
      fontSize: '14px',
      lineHeight: '20px',
      paddingTop: '8px',
      paddingBottom: '12px',
      paddingLeft: '0px',
      paddingRight: '0px',
    }),
  ),
  pin(WIDE, 'tab idle: secondary, 400', tab('attachments'), (c) => ({
    color: c.onSurfaceVariant,
    fontWeight: '400',
    fontSize: '14px',
    lineHeight: '20px',
  })),
  ...both('tabs are 24 apart', '[data-test="campaign-tabs"]', () => ({ gap: 24 }), {
    fn: `const a = document.querySelector('[data-test="tab-overview"]').getBoundingClientRect(); const b = document.querySelector('[data-test="tab-attachments"]').getBoundingClientRect(); return { gap: Math.round(b.left - a.right) }`,
  }),
  pin(WIDE, 'tab underline: 2px, the accent', '[data-test="tab-overview"] .v-tab__slider', (c) => ({
    height: '2px',
    backgroundColor: c.primary,
  })),
  pin(
    WIDE,
    'applications badge: accent pill, dark 12/16/500 label, 6px sides, 8 after the label',
    '[data-test="tab-applications-badge"] .v-badge__badge',
    (c) => ({
      backgroundColor: c.primary,
      color: c.onPrimary,
      fontSize: '12px',
      lineHeight: '16px',
      fontWeight: '500',
      paddingLeft: '6px',
      paddingRight: '6px',
      borderTopLeftRadius: '999px',
    }),
  ),

  // ── layout ────────────────────────────────────────────────────────────────────────────
  ...both(
    'right panel is 360 wide, 24 after the main column',
    glance,
    () => ({ width: 360, gap: 24 }),
    {
      fn: `const m = document.querySelector('.agency-detail__main').getBoundingClientRect(); const g = document.querySelector('[data-test="overview-glance"]').getBoundingClientRect(); return { width: Math.round(g.width), gap: Math.round(g.left - m.right) }`,
    },
  ),
  pin(WIDE, 'the two columns end at the content edge', glance, () => ({ flush: true }), {
    fn: `const g = document.querySelector('[data-test="overview-glance"]').getBoundingClientRect(); const t = document.querySelector('[data-test="campaign-tabs"]').getBoundingClientRect(); return { flush: Math.abs(g.right - t.right) < 0.5 }`,
  }),
  pin(WIDE, 'cards are 20 apart in the main column', card, () => ({ gap: 20 }), {
    fn: `const a = document.querySelector('[data-test="overview-details-card"]').getBoundingClientRect(); const b = document.querySelector('[data-test="overview-requirements-card"]').getBoundingClientRect(); return { gap: Math.round(b.top - a.bottom) }`,
  }),

  // ── details card ──────────────────────────────────────────────────────────────────────
  pin(WIDE, 'card: surface, 1px border, radius 12, padding 24, 20 between blocks', card, (c) => ({
    backgroundColor: c.surface,
    borderTopWidth: '1px',
    borderTopColor: c.border,
    borderTopLeftRadius: '12px',
    paddingTop: '24px',
    paddingLeft: '24px',
    rowGap: '20px',
  })),
  pin(WIDE, 'card title: 18/24/600', `${card} .agency-card__title`, (c) => ({
    fontSize: '18px',
    lineHeight: '24px',
    fontWeight: '600',
    color: c.onSurface,
  })),
  pin(
    WIDE,
    'field label: Overline 11/14/600, +0.6, uppercase, tertiary',
    `${card} .agency-field__label`,
    (c) => ({
      fontSize: '11px',
      lineHeight: '14px',
      fontWeight: '600',
      letterSpacing: '0.6px',
      textTransform: 'uppercase',
      color: c.tertiary,
    }),
  ),
  pin(WIDE, 'field value: 14/20/500', '[data-test="overview-budget"]', (c) => ({
    fontSize: '14px',
    lineHeight: '20px',
    fontWeight: '500',
    color: c.onSurface,
  })),
  pin(
    WIDE,
    'fields: three columns, 24 apart',
    `${card} .agency-fields`,
    () => ({
      display: 'grid',
      columnGap: '24px',
      columns: 3,
    }),
    {
      fn: `const g = document.querySelector('[data-test="overview-details-card"] .agency-fields'); const s = getComputedStyle(g); return { display: s.display, columnGap: s.columnGap, columns: s.gridTemplateColumns.split(' ').length }`,
    },
  ),
  pin(
    WIDE,
    'fields: first row is brand, status, budget; second is start, end',
    `${card} .agency-fields`,
    () => ({ labels: 'BRAND|STATUS|BUDGET|START DATE|END DATE' }),
    {
      fn: `return { labels: [...document.querySelectorAll('[data-test="overview-details-card"] .agency-field__label')].map((e) => e.textContent.trim().toUpperCase()).slice(0, 5).join('|') }`,
    },
  ),
  pin(
    WIDE,
    'status is the status pill (with its dot)',
    '[data-test="overview-status"]',
    () => ({ dot: true }),
    {
      fn: `const p = document.querySelector('[data-test="overview-status"]'); return { dot: !!p.querySelector('.agency-status-pill__dot') }`,
    },
  ),
  pin(WIDE, 'description rule: 1px, default border', `${card} .agency-card__rule`, (c) => ({
    height: '1px',
    backgroundColor: c.border,
  })),
  pin(
    WIDE,
    'description text: 14/20, 400, secondary',
    '[data-test="overview-description"] .agency-field__text',
    (c) => ({ fontSize: '14px', lineHeight: '20px', fontWeight: '400', color: c.onSurfaceVariant }),
  ),

  // ── settings (Requirements) card ──────────────────────────────────────────────────────
  pin(WIDE, 'settings card: same card, three rows', reqCard, (c) => ({
    backgroundColor: c.surface,
    borderTopLeftRadius: '12px',
    paddingTop: '24px',
    rowGap: '20px',
  })),
  pin(
    WIDE,
    'settings rows: label 14/20/500, switch at the end',
    '[data-test="overview-requirement-contract"] .agency-switch-row__label',
    (c) => ({ fontSize: '14px', lineHeight: '20px', fontWeight: '500', color: c.onSurface }),
  ),
  pin(
    WIDE,
    'three rows, in the Figma order',
    reqCard,
    () => ({ rows: 'contract|creator-posts|job-board' }),
    {
      fn: `return { rows: [...document.querySelectorAll('[data-test="overview-requirements-card"] .agency-switch-row')].map((e) => e.dataset.test.replace('overview-requirement-', '')).join('|') }`,
    },
  ),
  pin(
    WIDE,
    'switches are read-only and set',
    '[data-test="overview-requires-contract"]',
    () => ({ readonly: true, checked: true }),
    {
      fn: `const i = document.querySelector('[data-test="overview-requires-contract"] input'); const sw = document.querySelector('[data-test="overview-requires-contract"]'); return { readonly: i.hasAttribute('readonly') || i.getAttribute('aria-readonly') === 'true' || sw.classList.contains('v-input--readonly'), checked: i.checked }`,
    },
  ),
  pin(
    WIDE,
    'switch track: 32 by 18',
    '[data-test="overview-requires-contract"] .v-switch__track',
    () => ({ width: '32px', height: '18px' }),
  ),
  pin(
    WIDE,
    'switch on: accent track',
    '[data-test="overview-requires-contract"] .v-switch__track',
    (c) => ({ backgroundColor: c.primary }),
  ),

  // ── At a glance ───────────────────────────────────────────────────────────────────────
  pin(WIDE, 'glance card: same card; title 18/24/600', `${glance} .agency-card__title`, (c) => ({
    fontSize: '18px',
    lineHeight: '24px',
    fontWeight: '600',
    color: c.onSurface,
  })),
  pin(
    WIDE,
    'glance tile: 32 square, container fill, radius 8',
    `${glance} .agency-glance-row__tile`,
    (c) => ({
      width: '32px',
      height: '32px',
      backgroundColor: c.primaryContainer,
      borderTopLeftRadius: '8px',
      color: c.primaryText,
    }),
  ),
  pin(WIDE, 'glance label: 14/20, secondary', `${glance} .agency-glance-row__label`, (c) => ({
    fontSize: '14px',
    lineHeight: '20px',
    fontWeight: '400',
    color: c.onSurfaceVariant,
  })),
  pin(
    WIDE,
    'glance value: 14/20/500, ends at the card edge',
    `${glance} .agency-glance-row__value`,
    (c) => ({
      fontSize: '14px',
      lineHeight: '20px',
      fontWeight: '500',
      color: c.onSurface,
    }),
  ),
  pin(
    WIDE,
    'glance holds applications and days until start, nothing else',
    glance,
    () => ({ rows: 'applications:1|days-until-start:7' }),
    {
      fn: `return { rows: [...document.querySelectorAll('[data-test="overview-glance"] .agency-glance-row')].map((e) => e.dataset.test.replace('overview-glance-', '') + ':' + e.querySelector('.agency-glance-row__value').textContent.trim()).join('|') }`,
    },
  ),
  pin(WIDE, 'tile icon is 16', `${glance} .agency-glance-row__tile .v-icon`, () => ({
    fontSize: '16px',
  })),

  // ── what the page does not draw (nothing behind it) ───────────────────────────────────
  pin(
    WIDE,
    'omitted: Share brief, Created by, Market, Creators confirmed, Budget used, Next steps, Edit',
    root,
    () => ({ found: '' }),
    {
      fn: `const t = document.querySelector('[data-test="campaign-detail-page"]').innerText; return { found: ['Share brief', 'Created by', 'Market', 'Creators confirmed', 'Budget used', 'Next steps'].filter((s) => t.includes(s)).concat([...document.querySelectorAll('[data-test="campaign-detail-page"] button')].filter((b) => b.textContent.trim() === 'Edit').map(() => 'Edit button')).join('|') }`,
    },
  ),
]
