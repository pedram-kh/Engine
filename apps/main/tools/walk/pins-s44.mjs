/**
 * S4.4 pins: the dashboard, read off the REAL page over the L4 fixtures in both agency themes at
 * 1600 and 1280. They check R3 (the frame), R7 (the activity card) and the KPI strip adopting
 * `AgencyKpiCard`, and that the welcome bar keeps its aurora edge.
 */

const STEP = 'S4.4'

const pin = (page, name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page,
  ...more,
})
const both = (name, sel, expect, more = {}) => [
  pin('real:dashboard-1600', `${name} (1600)`, sel, expect, more),
  pin('real:dashboard-1280', `${name} (1280)`, sel, expect, more),
]

const page = '[data-test="dashboard-page"]'
const strip = '[data-test="kpi-strip"]'

export const S44_PINS = [
  ...both('dashboard: the page frame stacks its blocks 24 apart', page, () => ({
    display: 'flex',
    flexDirection: 'column',
    rowGap: '24px',
  })),
  ...both(
    'dashboard: four agency KPI cards, none of the shared card',
    strip,
    () => ({ agency: 4, shared: 0 }),
    {
      fn: `return { agency: document.querySelectorAll('${strip} .agency-kpi').length, shared: document.querySelectorAll('${strip} .v-card').length }`,
    },
  ),
  ...both(
    'dashboard: each card has its icon tile; only the roster card links (the others have no number or no page)',
    strip,
    () => ({ tiles: 4, links: 'kpi-creatorsInRoster' }),
    {
      fn: `return { tiles: document.querySelectorAll('${strip} [data-test="agency-kpi-icon"]').length, links: [...document.querySelectorAll('${strip} a.agency-kpi--link')].map((e) => e.getAttribute('data-test')).sort().join(',') }`,
    },
  ),
  pin(
    'real:dashboard-1600',
    'dashboard: the values are the summary (142, 7) and the two stub slots stay as dashes',
    strip,
    () => ({ values: '—,142,7,—' }),
    {
      fn: `return { values: [...document.querySelectorAll('${strip} [data-test="kpi-card-value"]')].map((e) => e.textContent.trim()).join(',') }`,
    },
  ),
  pin(
    'real:dashboard-1600',
    'dashboard: the four cards sit on one row, 20 apart',
    strip,
    () => ({ rows: 1, gap: 20 }),
    {
      fn: `const c = [...document.querySelectorAll('${strip} .agency-kpi')].map((e) => e.getBoundingClientRect()); return { rows: new Set(c.map((r) => Math.round(r.top))).size, gap: Math.round(c[1].left - c[0].right) }`,
    },
  ),
  pin(
    'real:dashboard-1600',
    'dashboard: the activity feed is one agency card with the 18/24/600 title, no v-card around it',
    '[data-test="dashboard-activity"]',
    () => ({ paddingTop: '24px', borderTopWidth: '1px', title: '18px/24px/600' }),
    {
      fn: `const e = document.querySelector('[data-test="dashboard-activity"]'); const s = getComputedStyle(e); const t = getComputedStyle(e.querySelector('.agency-card__title')); return { paddingTop: s.paddingTop, borderTopWidth: s.borderTopWidth, title: t.fontSize + '/' + t.lineHeight + '/' + t.fontWeight }`,
    },
  ),
  pin(
    'real:dashboard-1600',
    'dashboard: the welcome bar keeps its aurora gradient edge',
    '[data-test="welcome-bar"]',
    () => ({ aurora: true }),
    {
      fn: `return { aurora: getComputedStyle(document.querySelector('[data-test="welcome-bar"]')).borderImageSource.includes('gradient') }`,
    },
  ),
]
