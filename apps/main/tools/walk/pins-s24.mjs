/**
 * S2.4 pins: the agency KPI card fork, read off the second probe page (`?probe=extra`) under
 * both agency themes. Variants: `link` (icon tile and chevron), `icon` (tile only), `plain`
 * (neither, value 0), `placeholder` (null value), `loading`. Values are the Figma numbers
 * (Dashboard stat card, frame 36:484); colours come from pins-colours.mjs.
 */

const STEP = 'S2.4'

const pin = (name, sel, expect, more = {}) => ({
  step: STEP,
  name,
  sel,
  expect,
  page: 'extra',
  ...more,
})
const card = (v) => `[data-test="extra-kpi-${v}"]`

export const S24_PINS = [
  pin(
    'card: surface, 1px border, radius 10, padding 20, 16 between tile and text',
    card('link'),
    (c) => ({
      backgroundColor: c.surface,
      borderTopWidth: '1px',
      borderTopColor: c.border,
      borderTopLeftRadius: '10px',
      paddingTop: '20px',
      paddingRight: '20px',
      paddingBottom: '20px',
      paddingLeft: '20px',
      columnGap: '16px',
      alignItems: 'center',
      textDecorationLine: 'none',
    }),
  ),
  pin(
    'tile: 40px, radius 8, white glyph',
    `${card('link')} [data-test="agency-kpi-icon"]`,
    (c) => ({
      width: '40px',
      height: '40px',
      borderTopLeftRadius: '8px',
      color: c.white,
    }),
  ),
  pin(
    'label: 13/19, secondary, 2px above the value',
    `${card('link')} .agency-kpi__label`,
    (c) => ({
      fontSize: '13px',
      lineHeight: '19px',
      fontWeight: '400',
      color: c.onSurfaceVariant,
    }),
  ),
  pin('value: 22/28/600', `${card('link')} [data-test="kpi-card-value"]`, (c) => ({
    fontSize: '22px',
    lineHeight: '28px',
    fontWeight: '600',
    color: c.onSurface,
  })),
  pin(
    'value: the placeholder dash is muted',
    `${card('placeholder')} [data-test="kpi-card-value"]`,
    (c) => ({
      color: c.onSurfaceVariant,
    }),
  ),
  pin(
    'chevron: 16px, tertiary, only on the card that links',
    `${card('link')} [data-test="agency-kpi-chevron"]`,
    (c) => ({
      width: '16px',
      height: '16px',
      color: c.tertiary,
    }),
  ),
  pin(
    'only the card with a link has a chevron, only the cards with an icon have a tile',
    card('link'),
    () => ({
      chevrons: 2,
      tiles: 4,
      linkIsAnchor: true,
    }),
    {
      fn: `const q = (s) => document.querySelectorAll('[data-test="extra-kpis"] ' + s).length; return { chevrons: q('[data-test="agency-kpi-chevron"]'), tiles: q('[data-test="agency-kpi-icon"]'), linkIsAnchor: document.querySelector('[data-test="extra-kpi-link"]').tagName === 'A' }`,
    },
  ),
  pin(
    'a plain card keeps its slot: the label sits at the card padding',
    card('plain'),
    () => ({
      labelInset: '20px',
    }),
    {
      fn: `const c = document.querySelector('[data-test="extra-kpi-plain"]'); const l = c.querySelector('.agency-kpi__label'); return { labelInset: Math.round(l.getBoundingClientRect().left - c.getBoundingClientRect().left - 1) + 'px' }`,
    },
  ),
  pin(
    'the loading card shows a skeleton and no value',
    card('loading'),
    () => ({
      skeleton: true,
      value: false,
    }),
    {
      fn: `const c = document.querySelector('[data-test="extra-kpi-loading"]'); return { skeleton: !!c.querySelector('[data-test="kpi-card-skeleton"]'), value: !!c.querySelector('[data-test="kpi-card-value"]') }`,
    },
  ),
  pin('cards in a row have equal height', card('link'), () => ({ equal: true }), {
    fn: `const hs = ['link','icon','plain','placeholder'].map((v) => document.querySelector('[data-test="extra-kpi-' + v + '"]').getBoundingClientRect().height); return { equal: hs.slice(0, 2).every((h) => Math.abs(h - hs[0]) < 0.5) }`,
  }),
]

const TONES = { ai: 'ai', info: 'info', warning: 'warning', success: 'success' }
const TILE_CARD = { ai: 'ai', info: 'link', warning: 'icon', success: 'success' }

// The four solid tile fills (Figma USP 1 to 4 = ai, success, info, warning), identical in both themes.
for (const [tone, key] of Object.entries(TONES)) {
  S24_PINS.push(
    pin(
      `tile ${tone}: solid ${tone} fill`,
      `${card(TILE_CARD[tone])} [data-test="agency-kpi-icon"]`,
      (c) => ({ backgroundColor: c.pills[key].fill }),
    ),
  )
}

S24_PINS.push(
  pin('text block: label and value are 2px apart', card('link'), () => ({ gap: '2px' }), {
    fn: `const t = document.querySelector('[data-test="extra-kpi-link"] .agency-kpi__text'); return { gap: getComputedStyle(t).rowGap }`,
  }),
)
