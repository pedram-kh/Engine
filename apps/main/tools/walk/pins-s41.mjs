/**
 * S4.1 pins: the five inferred lists (roster, brands, talent pools, files, team), read off the
 * REAL pages over the L4 fixtures (`l4-fixtures.mjs`) in both agency themes at 1600 and 1280.
 * They check the layout rules the pages adopted (R2 and R3 frame and header, R4 filter row, R5
 * table, R9 status pills), not Figma pixels: no frame draws these pages.
 */

const STEP = 'S4.1'

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

/** R2, R3, R5 for one list page: the frame, the header and the table. */
function frame(id, rootSel, headingSel, tableSel) {
  return [
    ...both(id, `${id}: the page frame stacks its blocks 24 apart`, rootSel, () => ({
      display: 'flex',
      flexDirection: 'column',
      rowGap: '24px',
    })),
    pin(`real:${id}-1600`, `${id}: title is Heading/H1 26/32/500`, headingSel, (c) => ({
      fontSize: '26px',
      lineHeight: '32px',
      fontWeight: '500',
      color: c.onSurface,
    })),
    pin(
      `real:${id}-1600`,
      `${id}: the table header row is 34 high`,
      tableSel,
      () => ({ header: 34 }),
      {
        fn: `return { header: Math.round(document.querySelector('${tableSel} thead tr').getBoundingClientRect().height) }`,
      },
    ),
    pin(
      `real:${id}-1600`,
      `${id}: inner body cells use the agency-table padding (8px)`,
      tableSel,
      () => ({ paddingLeft: '8px' }),
      {
        fn: `const s = getComputedStyle(document.querySelector('${tableSel} tbody td:nth-child(2)')); return { paddingLeft: s.paddingLeft }`,
      },
    ),
  ]
}

/** R9: the page draws no bare tonal chip, and the status cells hold pills. */
const noTonalChips = (id, rootSel) =>
  pin(
    `real:${id}-1600`,
    `${id}: no tonal chip is left (status is a pill)`,
    rootSel,
    () => ({ tonal: 0 }),
    {
      fn: `return { tonal: document.querySelectorAll('${rootSel} .v-chip--variant-tonal, ${rootSel} .v-chip--variant-flat').length }`,
    },
  )

const roster = '[data-test="roster-page"]'
const brands = '[data-test="brand-list-page"]'
const pools = '[data-test="pool-list-page"]'
const files = '[data-test="agency-files-page"]'
const team = '[data-test="agency-users-page"]'

export const S41_PINS = [
  // ── frames, headers, tables ───────────────────────────────────────────────────────────
  ...frame('roster', roster, '[data-test="roster-heading"]', '[data-test="roster-table"]'),
  ...frame('brands', brands, '[data-test="brand-list-heading"]', '[data-test="brand-table"]'),
  ...frame('pools', pools, '[data-test="pool-list-heading"]', '[data-test="pool-table"]'),
  ...frame('files', files, '[data-test="files-heading"]', '[data-test="files-table"]'),
  ...frame('team', team, '[data-test="agency-users-heading"]', '[data-test="members-table"]'),
  pin(
    'real:team-1600',
    'team: the invitations table has the same header row',
    '[data-test="invitations-table"]',
    () => ({ header: 34 }),
    {
      fn: `return { header: Math.round(document.querySelector('[data-test="invitations-table"] thead tr').getBoundingClientRect().height) }`,
    },
  ),

  // ── R9: status pills, one tone table ──────────────────────────────────────────────────
  noTonalChips('roster', '[data-test="roster-table"]'),
  noTonalChips('brands', '[data-test="brand-table"]'),
  noTonalChips('pools', '[data-test="pool-table"]'),
  noTonalChips('team', team),
  pin(
    'real:roster-1600',
    'roster: both status columns hold one pill per row, with the table tones',
    '[data-test="roster-table"]',
    () => ({
      rows: 9,
      relationship: 'success,success,neutral,warning,success,neutral,success,danger,neutral',
      application: 'success,success,warning,success,success,neutral,danger,success,success',
    }),
    {
      fn: `const t = (col) => [...document.querySelectorAll('[data-test="roster-table"] tbody tr')].map((r) => r.querySelector('td:nth-child(' + col + ') .agency-status-pill')?.getAttribute('data-tone')).join(','); return { rows: document.querySelectorAll('[data-test="roster-table"] tbody tr').length, relationship: t(3), application: t(4) }`,
    },
  ),
  pin(
    'real:roster-1600',
    'roster: a success pill reads the theme success text',
    '[data-test^="roster-status-01HRELATION000000000000001"]',
    (c) => ({
      color: c.pills.success.text,
    }),
  ),
  pin(
    'real:brands-1600',
    'brands: active and archived take success and neutral',
    '[data-test="brand-table"]',
    () => ({
      tones: 'success,success,success,success,success,success,success,success,neutral',
    }),
    {
      fn: `return { tones: [...document.querySelectorAll('[data-test="brand-table"] tbody tr')].map((r) => r.querySelector('.agency-status-pill')?.getAttribute('data-tone')).join(',') }`,
    },
  ),
  pin(
    'real:team-1600',
    'team: membership and invitation pills take the table tones',
    team,
    () => ({
      members: 'success,success,success,warning',
      invitations: 'warning,success,neutral',
    }),
    {
      fn: `const t = (sel) => [...document.querySelectorAll(sel + ' tbody tr')].map((r) => r.querySelector('.agency-status-pill')?.getAttribute('data-tone')).join(','); return { members: t('[data-test="members-table"]'), invitations: t('[data-test="invitations-table"]') }`,
    },
  ),

  // ── R5: a brand is a mark and a name, not a chip ──────────────────────────────────────
  pin(
    'real:pools-1600',
    'pools: the brand column is a brand mark and the name',
    '[data-test="pool-table"]',
    () => ({
      marks: 3,
      chips: 0,
    }),
    {
      fn: `return { marks: document.querySelectorAll('[data-test="pool-table"] .agency-cell-brand .agency-brand-mark').length, chips: document.querySelectorAll('[data-test="pool-table"] .v-chip').length }`,
    },
  ),
  pin(
    'real:brands-1600',
    'brands: a brand with no logo shows its initial mark, not a placeholder image',
    '[data-test="brand-table"]',
    () => ({
      marks: 9,
      images: 0,
    }),
    {
      fn: `return { marks: document.querySelectorAll('[data-test="brand-table"] .agency-brand-mark').length, images: document.querySelectorAll('[data-test="brand-table"] .mdi-image-outline').length }`,
    },
  ),

  // ── R4: the filter rows ───────────────────────────────────────────────────────────────
  ...['roster', 'brands', 'pools'].map((id) => {
    const sel = {
      roster: 'roster-status-filter',
      brands: 'brand-status-filter',
      pools: 'pool-status-filter',
    }[id]
    return pin(
      `real:${id}-1600`,
      `${id}: the status filter is the segmented control`,
      `[data-test="${sel}"]`,
      (c) => ({
        backgroundColor: c.surface,
        borderTopWidth: '1px',
        borderTopColor: c.border,
        borderTopLeftRadius: '8px',
      }),
    )
  }),
  pin(
    'real:team-1600',
    'team: both status filters are segmented controls',
    team,
    () => ({ segments: 2 }),
    {
      fn: `return { segments: document.querySelectorAll('[data-test="member-role-filter"].agency-seg, [data-test="invitation-status-filter"].agency-seg').length }`,
    },
  ),
  pin(
    'real:roster-1600',
    'roster: search is the 40-high agency search, with its placeholder and no floating label',
    '[data-test="roster-search"] .v-field',
    () => ({
      height: 40,
      label: 0,
      placeholder: 'Search by name or bio',
    }),
    {
      fn: `const f = document.querySelector('[data-test="roster-search"] .v-field'); return { height: Math.round(f.getBoundingClientRect().height), label: document.querySelectorAll('[data-test="roster-search"] .v-field-label').length, placeholder: document.querySelector('[data-test="roster-search"] input').getAttribute('placeholder') }`,
    },
  ),
  pin(
    'real:roster-1600',
    'roster: country, language and category are pills',
    '[data-test="roster-filters"]',
    () => ({
      pills: 3,
      selects: 2,
    }),
    {
      fn: `const r = document.querySelector('[data-test="roster-filters"]'); return { pills: r.querySelectorAll('.agency-filter-pill').length, selects: r.querySelectorAll('.v-select').length }`,
    },
  ),
  pin(
    'real:roster-1600',
    'roster: every input in the filter row is one height and floats its label',
    '[data-test="roster-filters"]',
    () => ({
      sameHeight: true,
      floated: 6,
    }),
    {
      fn: `const f = [...document.querySelectorAll('[data-test="roster-filters"] .v-field')]; const h = f.map((e) => Math.round(e.getBoundingClientRect().height)); return { sameHeight: new Set(h).size === 1, floated: f.filter((e) => e.classList.contains('v-field--active')).length }`,
    },
  ),
  pin(
    'real:roster-1280',
    'roster: the filter row wraps instead of overflowing',
    '[data-test="roster-filters"]',
    () => ({ overflow: false }),
    {
      fn: `const r = document.querySelector('[data-test="roster-filters"]'); return { overflow: r.scrollWidth > r.clientWidth + 1 }`,
    },
  ),
  pin(
    'real:team-1600',
    'team: the member search sits right and is the agency search',
    '[data-test="member-search"] .v-field',
    () => ({ height: 40 }),
    {
      fn: `return { height: Math.round(document.querySelector('[data-test="member-search"] .v-field').getBoundingClientRect().height) }`,
    },
  ),

  // ── sections ──────────────────────────────────────────────────────────────────────────
  pin(
    'real:team-1600',
    'team: each section title is 18/24/600 and its blocks sit 16 apart',
    '[data-test="members-heading"]',
    (c) => ({
      fontSize: '18px',
      lineHeight: '24px',
      fontWeight: '600',
      color: c.onSurface,
    }),
  ),
  pin(
    'real:team-1600',
    'team: section blocks are 16 apart',
    '[data-test="members-section"]',
    () => ({
      rowGap: '16px',
      display: 'flex',
    }),
  ),
]
