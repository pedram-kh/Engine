/**
 * S3.1 pins: the Campaigns list, read off the REAL `CampaignListPage` over the L3 fixtures
 * (nine of twenty-four campaigns, `l3-fixtures.mjs`) in both agency themes, at 1600 and at
 * 1280. Values are the Figma numbers (Campaigns frame 14:3); colours come from
 * pins-colours.mjs.
 */

const STEP = 'S3.1'
const WIDE = 'real:campaigns-1600'
const NARROW = 'real:campaigns-1280'

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

const page = '[data-test="campaign-list-page"]'
const table = '[data-test="campaign-table"]'
const row = (n) => `${table} tbody tr:nth-child(${n})`
const cell = (n, col) => `${row(n)} td:nth-child(${col})`

export const S31_PINS = [
  // ── header ────────────────────────────────────────────────────────────────────────────
  pin(WIDE, 'title: Heading/H1 26/32/500', '[data-test="campaign-list-heading"]', (c) => ({
    fontSize: '26px',
    lineHeight: '32px',
    fontWeight: '500',
    color: c.onSurface,
  })),
  pin(WIDE, 'summary: body small 13/19, secondary', '[data-test="campaign-list-summary"]', (c) => ({
    fontSize: '13px',
    lineHeight: '19px',
    fontWeight: '400',
    color: c.onSurfaceVariant,
  })),
  pin(
    WIDE,
    'summary reads the list total, not the page size',
    '[data-test="campaign-list-summary"]',
    () => ({ text: '24 campaigns' }),
    {
      fn: `return { text: document.querySelector('[data-test="campaign-list-summary"]').textContent.trim() }`,
    },
  ),
  pin(WIDE, 'blocks stacked 24 apart', page, () => ({ rowGap: '24px', display: 'flex' })),
  ...both('the primary button ends flush with the table card', page, () => ({ flush: true }), {
    fn: `const b = document.querySelector('[data-test="campaign-create-btn"]').getBoundingClientRect(); const t = document.querySelector('[data-test="campaign-table"]').getBoundingClientRect(); return { flush: Math.abs(b.right - t.right) < 0.5 }`,
  }),

  // ── filters ───────────────────────────────────────────────────────────────────────────
  pin(
    WIDE,
    'brand filter: transparent, radius 8',
    '[data-test="campaign-filter-brand"] .v-field',
    () => ({ backgroundColor: 'rgba(0, 0, 0, 0)', borderTopLeftRadius: '8px' }),
  ),
  pin(
    WIDE,
    'brand filter: Body small 13/19 text',
    '[data-test="campaign-filter-brand"] .v-field__input',
    () => ({ fontSize: '13px', lineHeight: '19px' }),
  ),
  pin(
    WIDE,
    'brand filter: the outline is the default border colour',
    '[data-test="campaign-filter-brand"] .v-field__outline',
    (c) => ({ color: c.border }),
  ),
  pin(
    WIDE,
    'a filter holding a value: container fill, accent border, accent text',
    '[data-test="campaign-filter-starts-from"] .v-field',
    (c) => ({ fill: c.primaryContainer, border: c.primary, text: c.primaryText }),
    {
      fn: `const root = document.querySelector('[data-test="campaign-filter-starts-from"]'); const input = root.querySelector('input'); const set = (v) => { input.value = v; input.dispatchEvent(new Event('input', { bubbles: true })) }; set('2026-10-01'); return new Promise((resolve) => setTimeout(() => { const f = root.querySelector('.v-field'); const out = { fill: getComputedStyle(f).backgroundColor, border: getComputedStyle(root.querySelector('.v-field__outline')).color, text: getComputedStyle(root.querySelector('.v-field__input')).color }; set(''); setTimeout(() => resolve(out), 400) }, 400))`,
    },
  ),
  pin(
    WIDE,
    'status segments: surface box, 1px border, radius 8, 4px padding, 4 between',
    '[data-test="campaign-status-filter"]',
    (c) => ({
      backgroundColor: c.surface,
      borderTopWidth: '1px',
      borderTopColor: c.border,
      borderTopLeftRadius: '8px',
      paddingTop: '4px',
      paddingLeft: '4px',
    }),
  ),
  pin(
    WIDE,
    'segments: radius 6, 28 high, 12/16, 12px padding; selected is raised and 500',
    '[data-test="campaign-filter-all"]',
    (c) => ({
      height: '28px',
      borderTopLeftRadius: '6px',
      paddingLeft: '12px',
      fontSize: '12px',
      lineHeight: '16px',
      fontWeight: '500',
      backgroundColor: c.raised,
      color: c.onSurface,
    }),
  ),
  pin(
    WIDE,
    'segments: an unselected one is transparent, secondary and 400',
    '[data-test="campaign-filter-draft"]',
    (c) => ({
      backgroundColor: 'rgba(0, 0, 0, 0)',
      color: c.onSurfaceVariant,
      fontWeight: '400',
      borderTopLeftRadius: '6px',
    }),
  ),
  pin(
    WIDE,
    'segments carry no counts (the list has none) and no check mark',
    '[data-test="campaign-status-filter"]',
    () => ({ digits: 0, marks: 0, segments: 6 }),
    {
      fn: `const g = document.querySelector('[data-test="campaign-status-filter"]'); return { digits: (g.textContent.match(/\\d/g) ?? []).length, marks: [...g.querySelectorAll('.v-chip__filter')].filter((e) => getComputedStyle(e).display !== 'none').length, segments: g.querySelectorAll('.v-chip').length }`,
    },
  ),

  // ── table ─────────────────────────────────────────────────────────────────────────────
  pin(WIDE, 'table card: surface, 1px border, radius 10', table, (c) => ({
    backgroundColor: c.surface,
    borderTopWidth: '1px',
    borderTopColor: c.border,
    borderTopLeftRadius: '10px',
  })),
  pin(
    WIDE,
    'header cell: Overline 11/14/600, +0.6px, uppercase, raised fill',
    `${table} thead th:nth-child(1)`,
    (c) => ({
      fontSize: '11px',
      lineHeight: '14px',
      fontWeight: '600',
      letterSpacing: '0.6px',
      textTransform: 'uppercase',
      color: c.onSurfaceVariant,
      backgroundColor: c.raised,
      paddingLeft: '20px',
    }),
  ),
  pin(WIDE, 'header row is 34 high', table, () => ({ header: 34 }), {
    fn: `return { header: Math.round(document.querySelector('[data-test="campaign-table"] thead tr').getBoundingClientRect().height) }`,
  }),
  pin(
    WIDE,
    'rows: a 68px pitch, the rule between them identical',
    table,
    () => ({ pitch: '68,68,68,68' }),
    {
      fn: `const rows = [...document.querySelectorAll('[data-test="campaign-table"] tbody tr')]; const top = rows.map((r) => r.getBoundingClientRect().top); return { pitch: [1, 2, 3, 4].map((i) => Math.round(top[i] - top[i - 1])).join(',') }`,
    },
  ),
  pin(
    WIDE,
    'column widths: brand 180, status 140, job board 110, budget 120, action 64',
    table,
    () => ({ widths: '180,140,110,120,64' }),
    {
      fn: `const th = [...document.querySelectorAll('[data-test="campaign-table"] thead th')]; return { widths: th.slice(1).map((e) => Math.round(e.getBoundingClientRect().width)).join(',') }`,
    },
  ),
  ...both(
    'the table fills the content width and does not scroll sideways',
    table,
    () => ({ fits: true }),
    {
      fn: `const t = document.querySelector('[data-test="campaign-table"]'); const h = document.querySelector('[data-test="agency-page-header"]'); const w = t.querySelector('.v-table__wrapper'); return { fits: Math.abs(t.getBoundingClientRect().width - h.getBoundingClientRect().width) < 0.5 && w.scrollWidth <= w.clientWidth + 0.5 }`,
    },
  ),
  pin(WIDE, 'campaign name: 14/20/500', `${cell(1, 1)} .agency-cell__title`, (c) => ({
    fontSize: '14px',
    lineHeight: '20px',
    fontWeight: '500',
    color: c.onSurface,
  })),
  pin(
    WIDE,
    'starts line: 12/16, tertiary, 4 under the name',
    `${cell(1, 1)} .agency-cell__sub`,
    (c) => ({
      fontSize: '12px',
      lineHeight: '16px',
      fontWeight: '400',
      color: c.tertiary,
    }),
  ),
  pin(WIDE, 'the name cell stacks name over the starts line', `${cell(1, 1)} .agency-cell`, () => ({
    rowGap: '4px',
    flexDirection: 'column',
  })),
  pin(WIDE, 'brand mark: 24px, radius 6, chip type', `${cell(1, 2)} .agency-brand-mark`, () => ({
    width: '24px',
    height: '24px',
    borderTopLeftRadius: '6px',
  })),
  pin(
    WIDE,
    'brand mark letter: 10.5/14/700, +0.4px',
    `${cell(1, 2)} .agency-brand-mark__letter`,
    () => ({
      fontSize: '10.5px',
      lineHeight: '14px',
      fontWeight: '700',
      letterSpacing: '0.4px',
      textTransform: 'uppercase',
    }),
  ),
  pin(
    WIDE,
    'brand name: 13/19, secondary, 8 from the mark',
    `${cell(1, 2)} .agency-cell-brand`,
    (c) => ({
      fontSize: '13px',
      lineHeight: '19px',
      color: c.onSurfaceVariant,
      columnGap: '8px',
    }),
  ),
  pin(WIDE, 'budget: 14/20/500', `${cell(1, 5)} .agency-cell__title`, (c) => ({
    fontSize: '14px',
    lineHeight: '20px',
    fontWeight: '500',
    color: c.onSurface,
  })),
  pin(WIDE, 'budget cell is right aligned', cell(1, 5), () => ({ textAlign: 'end' })),
  pin(
    WIDE,
    'the row action is the eye button, at the right edge',
    cell(1, 6),
    () => ({ edge: true, buttons: 1 }),
    {
      fn: `const td = document.querySelector('[data-test="campaign-table"] tbody tr:nth-child(1) td:nth-child(6)'); const b = td.querySelectorAll('.v-btn'); const r = td.getBoundingClientRect(); return { edge: Math.abs(b[0].getBoundingClientRect().right - (r.right - 20)) < 6, buttons: b.length }`,
    },
  ),

  // ── status pill (the page-local colour map is gone) ───────────────────────────────────
  pin(
    WIDE,
    'every row renders the status pill, with the tone the table gives its status',
    table,
    () => ({
      rows: 9,
      pills: 9,
      chips: 0,
      tones: 'success,success,neutral,neutral,success,success,success,danger,success',
    }),
    {
      fn: `const rows = [...document.querySelectorAll('[data-test="campaign-table"] tbody tr')]; const pills = rows.map((r) => r.querySelector('.agency-status-pill')); return { rows: rows.length, pills: pills.filter(Boolean).length, chips: document.querySelectorAll('[data-test="campaign-table"] .v-chip').length, tones: pills.map((p) => p?.getAttribute('data-tone')).join(',') }`,
    },
  ),
  pin(
    WIDE,
    'the Active pill: success text on its tint, 12/16/500',
    `${cell(1, 3)} .agency-status-pill`,
    (c) => ({
      color: c.pills.success.text,
      fontSize: '12px',
      lineHeight: '16px',
      fontWeight: '500',
      borderTopLeftRadius: '999px',
    }),
  ),

  // ── job board switch ──────────────────────────────────────────────────────────────────
  pin(WIDE, 'the job board switch is still a switch, one per row', table, () => ({ switches: 9 }), {
    fn: `return { switches: document.querySelectorAll('[data-test="campaign-table"] tbody [data-test^="campaign-job-board-toggle-"]').length }`,
  }),

  // ── footer ────────────────────────────────────────────────────────────────────────────
  pin(
    WIDE,
    'footer count: Caption 12/16, tertiary',
    '[data-test="campaign-list-showing"]',
    (c) => ({
      fontSize: '12px',
      lineHeight: '16px',
      color: c.tertiary,
    }),
  ),
  pin(
    WIDE,
    'footer reads "Showing 9 of 24" and the page buttons sit at the right edge',
    '[data-test="campaign-list-footer"]',
    () => ({
      text: 'Showing 9 of 24',
      buttons: 2,
      flush: true,
      previousDisabled: true,
      nextDisabled: false,
    }),
    {
      fn: `const f = document.querySelector('[data-test="campaign-list-footer"]'); const t = document.querySelector('[data-test="campaign-table"]').getBoundingClientRect(); const bs = f.querySelectorAll('.v-btn'); return { text: f.querySelector('[data-test="campaign-list-showing"]').textContent.trim(), buttons: bs.length, flush: Math.abs(bs[1].getBoundingClientRect().right - t.right) < 0.5, previousDisabled: bs[0].disabled, nextDisabled: bs[1].disabled }`,
    },
  ),
  pin(
    WIDE,
    'page buttons: Sm secondary, 32 high, radius 8, outlined, label 13/500',
    '[data-test="campaign-page-next"]',
    () => ({
      height: '32px',
      borderTopLeftRadius: '8px',
      borderTopWidth: '1px',
      fontSize: '13px',
      fontWeight: '500',
    }),
  ),
  pin(
    WIDE,
    'the footer sits 24 below the table',
    '[data-test="campaign-list-footer"]',
    () => ({ gap: 24 }),
    {
      fn: `const t = document.querySelector('[data-test="campaign-table"]').getBoundingClientRect(); const f = document.querySelector('[data-test="campaign-list-footer"]').getBoundingClientRect(); return { gap: Math.round(f.top - t.bottom) }`,
    },
  ),
]
