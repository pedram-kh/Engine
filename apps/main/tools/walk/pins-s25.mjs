/**
 * S2.5 pins: the agency notification bell fork, read off the second probe page (`?probe=extra`)
 * under both agency themes: three bells sharing a poll that holds 0, 7 and 120, and the real
 * top-bar bell in the shell. Values are the Figma numbers (top bar, frame 36:182): a 20px glyph
 * with a danger pill right against it, 10.5px bold with 14px line and 0.4px tracking, white,
 * padding 1px by 6px, full radius.
 */

const STEP = 'S2.5'

const pin = (name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page: 'extra',
  ...more,
})
const bell = (n) => `[data-test="extra-bell-${n}"]`

export const S25_PINS = [
  pin(
    'bell button: 28 by 32, secondary colour',
    `${bell(7)} [data-test="notification-bell-btn"]`,
    (c) => ({
      width: '28px',
      height: '32px',
      color: c.onSurfaceVariant,
    }),
  ),
  pin('bell glyph: 20px', `${bell(7)} [data-test="notification-bell-btn"] .v-icon`, () => ({
    width: '20px',
    height: '20px',
    fontSize: '20px',
  })),
  pin('pill: danger fill, white, full radius', `${bell(7)} .v-badge__badge`, (c) => ({
    backgroundColor: c.error,
    color: c.white,
    borderTopLeftRadius: '999px',
  })),
  pin('pill: 10.5/14 bold, 0.4px tracking, uppercase', `${bell(7)} .v-badge__badge`, () => ({
    fontSize: '10.5px',
    lineHeight: '14px',
    fontWeight: '700',
    letterSpacing: '0.4px',
    textTransform: 'uppercase',
  })),
  pin('pill: 16px high, padding 1px by 6px', `${bell(7)} .agency-bell__count`, () => ({
    paddingTop: '1px',
    paddingBottom: '1px',
    paddingLeft: '6px',
    paddingRight: '6px',
  })),
  pin(
    "pill uses the app font, not Vuetify's Roboto default",
    bell(7),
    () => ({ sameFont: true, notRoboto: true }),
    {
      fn: `const f = (el) => getComputedStyle(el).fontFamily; const b = document.querySelector('[data-test="extra-bell-7"] .v-badge__badge'); const app = document.querySelector('.v-application'); return { sameFont: f(b) === f(app), notRoboto: !/^"?Roboto/.test(f(b)) }`,
    },
  ),
  pin(
    'pill is 16px high and sits right against the glyph (no gap, no overlap)',
    bell(7),
    () => ({ height: 16, gap: 0 }),
    {
      fn: `const g = document.querySelector('[data-test="extra-bell-7"] [data-test="notification-bell-btn"] .v-icon').getBoundingClientRect(); const b = document.querySelector('[data-test="extra-bell-7"] .v-badge__badge').getBoundingClientRect(); return { height: Math.round(b.height), gap: Math.round(b.left - g.right) }`,
    },
  ),
  pin('pill is vertically centred on the glyph', bell(7), () => ({ centred: true }), {
    fn: `const g = document.querySelector('[data-test="extra-bell-7"] [data-test="notification-bell-btn"] .v-icon').getBoundingClientRect(); const b = document.querySelector('[data-test="extra-bell-7"] .v-badge__badge').getBoundingClientRect(); return { centred: Math.abs((g.top + g.height / 2) - (b.top + b.height / 2)) <= 1 }`,
  }),
  pin(
    '7 reads 7 and 120 reads 99+, the pill is about 34px wide at the cap (Figma 34)',
    bell(120),
    () => ({ seven: '7', capped: '99+', capWidthOk: true }),
    {
      fn: `const t = (n) => document.querySelector('[data-test="extra-bell-' + n + '"] .v-badge__badge').textContent.trim(); const w = document.querySelector('[data-test="extra-bell-120"] .v-badge__badge').getBoundingClientRect().width; return { seven: t(7), capped: t(120), capWidthOk: Math.abs(w - 34) <= 3 }`,
    },
  ),
  pin('no pill at zero', bell(0), () => ({ visiblePills: 0 }), {
    fn: `const pills = [...document.querySelectorAll('[data-test="extra-bell-0"] .v-badge__badge')].filter((el) => { const s = getComputedStyle(el); const r = el.getBoundingClientRect(); return s.display !== 'none' && s.visibility !== 'hidden' && Number(s.opacity) > 0 && r.width > 0 }); return { visiblePills: pills.length }`,
  }),
  pin('the accessible name keeps the real count', bell(120), () => ({ hasCount: true }), {
    fn: `return { hasCount: document.querySelector('[data-test="extra-bell-120"] [data-test="notification-bell-btn"]').getAttribute('aria-label').includes('120') }`,
  }),
  pin(
    'the shell top bar carries exactly one bell and it is the agency one',
    '.v-app-bar',
    () => ({ bells: 1, agency: true }),
    {
      fn: `const b = document.querySelectorAll('.v-app-bar [data-test="notification-bell-btn"]'); return { bells: b.length, agency: b.length === 1 && b[0].classList.contains('agency-bell__btn') }`,
    },
  ),
]
